<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\PharmacyTransferStatus;
use App\Models\PharmacyTransfer;
use App\Models\PharmacyTransferItem;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 يشغّل InterPharmacyTransferPlanner (قراءة فقط) ثم يكتب نتيجته كاقتراحات
 * قابلة للمراجعة البشرية. هذا هو الخط الفاصل الوحيد بين "قراءة نقية" (المخطِّط)
 * و"كتابة فعلية" (هنا) — فصل مقصود يُبقي المخطِّط قابلاً للاختبار بمعزل تام.
 *
 * 🎓 لماذا "استبدال كامل" لا "إضافة تراكمية" عند كل تحديث؟ كل اقتراح لم يُبتّ
 * فيه بعد (Suggested) يُحذَف أولاً، ثم تُكتَب اللقطة الجديدة كاملة — تماماً
 * كفلسفة "استبدال كامل" المُتَّبعة بكشف المستودعات وبمحرك الترميم القياسي.
 * التراكم كان سينتج اقتراحات مكررة أو متناقضة (نفس الزوج بأرقام مختلفة من
 * تحديثين متتاليين) بلا أي فائدة — الاقتراح **لقطة الآن**، لا سجل تاريخي؛
 * السجل التاريخي الحقيقي هو الاقتراحات التي **تحوَّلت** فعلاً (Completed) أو
 * رُفضت (Dismissed) صراحة — تلك لا تُحذَف أبداً هنا، فقط Suggested.
 *
 * 🎓 لماذا مستند واحد لكل زوج (من، إلى) يجمع كل موادهما، لا مستند منفصل لكل
 * مادة؟ نفس سبب تجميع reverse_transfers لكل مواد صيدلية واحدة بمستند واحد:
 * موظف العمليات يراجع "ماذا سنرسل لصيدلية ب من صيدلية أ" كوحدة عمل واحدة،
 * لا عشرات المستندات المنفصلة لكل صنف على حدة.
 */
final class PharmacyTransferSuggestionService
{
    private const ITEM_CHUNK = 500;

    public function __construct(
        private readonly InterPharmacyTransferPlanner $planner,
        private readonly \App\Services\Inventory\Surplus\InventoryCycleResolver $cycleResolver, // 🆕 (أ.3)
    ) {
    }

    /**
     * @return int عدد الاقتراحات (أصناف، لا مستندات) المكتوبة بهذا التحديث
     */
    public function refresh(): int
    {
        $suggestions = $this->planner->plan();
        $cycleId = $this->cycleResolver->current()->id; // 🆕 (أ.3) كل اقتراح يُوسم بدورته

        return DB::transaction(function () use ($suggestions, $cycleId): int {
            // 🎯 cascadeOnDelete على pharmacy_transfer_items يتكفَّل بحذف الأصناف تلقائياً.
            PharmacyTransfer::suggested()->delete();

            if ($suggestions === []) {
                return 0;
            }

            $grouped = [];
            foreach ($suggestions as $suggestion) {
                $grouped["{$suggestion->fromPharmacyId}_{$suggestion->toPharmacyId}"][] = $suggestion;
            }

            $now = now();

            foreach ($grouped as $items) {
                $transfer = PharmacyTransfer::create([
                    'from_pharmacy_id' => $items[0]->fromPharmacyId,
                    'to_pharmacy_id' => $items[0]->toPharmacyId,
                    'status' => PharmacyTransferStatus::Suggested,
                    'cycle_import_log_id' => $cycleId,
                ]);

                $rows = array_map(fn (TransferSuggestion $s): array => [
                    'pharmacy_transfer_id' => $transfer->id,
                    'product_id' => $s->productId,
                    'quantity' => $s->quantity,
                    'from_pharmacy_excess_at_time' => $s->fromPharmacyExcessAtTime,
                    'to_pharmacy_gap_at_time' => $s->toPharmacyGapAtTime,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $items);

                foreach (array_chunk($rows, self::ITEM_CHUNK) as $chunk) {
                    PharmacyTransferItem::insert($chunk);
                }
            }

            return count($suggestions);
        });
    }
}
