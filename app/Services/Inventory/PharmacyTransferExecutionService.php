<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\PharmacyTransferStatus;
use App\Models\PharmacyInventory;
use App\Models\PharmacyTransfer;
use App\Models\PharmacyTransferItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 🎯 يحوِّل مناقلة "مقترَحة" إلى قرار بشري نهائي: موافقة (تحريك فعلي للمخزون
 * بين الصيدليتين) أو رفض (بلا أي أثر على المخزون). راجع تعليق مايجريشن
 * create_pharmacy_transfers_table للسبب الكامل وراء وجود سير عمل مراجعة هنا
 * إطلاقاً (بخلاف المناقلات العكسية، المُنفَّذة تلقائياً فوراً بلا مراجعة).
 *
 * ⚠️ نقطة أمان جوهرية: الكمية المقترَحة كانت **لقطة وقت الاقتراح**، وقد يكون
 * قد مرّ وقت بينها وبين هذه الموافقة (اقتراح آخر استهلك نفس مصدر الفائض،
 * أو رفعة جرد جديدة غيَّرت الأرصدة). **لا يجوز نقل الكمية المقترَحة كما هي
 * بشكل أعمى** — لو كان المصدر يملك الآن أقل مما اقتُرح، نقل الكمية الكاملة
 * كان سيُخلق رصيداً من العدم عند الوجهة (المصدر يُطرَح منه ما يملكه فقط،
 * لكن الوجهة كانت ستزداد بالكمية المقترَحة الكاملة الأكبر — فرق يُخلَق من
 * لا شيء). الحل: الكمية الفعلية المنقولة = min(المقترَحة، المتاح فعلياً
 * بالمصدر الآن)، بقفل (lockForUpdate) يمنع نفس السباق مع أي موافقة أخرى
 * متزامنة على نفس المصدر.
 */
/**
 * 🧊 (ب.7) **مُجمَّد — لا تستخدمه لكود جديد.** استُبدل مساره بالواجهة بـ
 * Transfers\TransferExecutionService::executePharmacyTransfer()، الذي يحجز الفائض عبر
 * SurplusEngine (إلغاء متبادل مع المناقلات العكسية وزر "مناقلات؟") بدل تعديل لقطة
 * pharmacy_inventories مباشرة كما يفعل approve() هنا. أُبقي بلا حذف (فلسفة "جمّد لا تحذف")
 * لأن قرار "هل يُعدَّل الجرد عند التنفيذ؟" قد يُعاد النظر فيه — حينها هذا الكود جاهز ومختبَر.
 *
 * @deprecated استخدم \App\Services\Inventory\Transfers\TransferExecutionService
 */
final class PharmacyTransferExecutionService
{
    public function approve(int $transferId): PharmacyTransferExecutionOutcome
    {
        return DB::transaction(function () use ($transferId): PharmacyTransferExecutionOutcome {
            $transfer = PharmacyTransfer::where('id', $transferId)->lockForUpdate()->find($transferId);

            if ($transfer === null) {
                throw new RuntimeException("مناقلة الصيدليات #{$transferId} غير موجودة.");
            }

            if ($transfer->status !== PharmacyTransferStatus::Suggested) {
                throw new RuntimeException("مناقلة الصيدليات #{$transferId} ليست بانتظار المراجعة (حالتها الحالية: {$transfer->status->label()}) — ربما بُتَّ فيها بالفعل.");
            }

            $items = PharmacyTransferItem::where('pharmacy_transfer_id', $transferId)->get();
            $productIds = $items->pluck('product_id')->unique();

            $fromStock = PharmacyInventory::where('pharmacy_id', $transfer->from_pharmacy_id)
                ->whereIn('product_id', $productIds)->lockForUpdate()->get()->keyBy('product_id');
            $toStock = PharmacyInventory::where('pharmacy_id', $transfer->to_pharmacy_id)
                ->whereIn('product_id', $productIds)->lockForUpdate()->get()->keyBy('product_id');

            $fully = 0;
            $partially = 0;
            $skipped = 0;
            $now = now();
            $toInserts = [];
            $toUpdates = [];
            $fromUpdates = [];

            foreach ($items as $item) {
                $fromRow = $fromStock->get($item->product_id);
                $availableNow = $fromRow ? (int) $fromRow->quantity : 0;

                // راجع تحذير الكلاس أعلاه — هذا الطرح هو نقطة الأمان الجوهرية بالكامل.
                $actualQty = min((int) $item->quantity, $availableNow);

                if ($actualQty <= 0) {
                    $skipped++;
                    continue;
                }

                $actualQty === (int) $item->quantity ? $fully++ : $partially++;

                $fromUpdates[$fromRow->id] = $availableNow - $actualQty;

                $toRow = $toStock->get($item->product_id);
                if ($toRow) {
                    $toUpdates[$toRow->id] = ($toUpdates[$toRow->id] ?? (int) $toRow->quantity) + $actualQty;
                } else {
                    // 🩹 قد تتكرر نفس المادة بأكثر من صف مناقلة نادراً — نجمع هنا
                    // بمفتاح product_id بدل إدراج صفين لنفس (صيدلية+مادة) فيتصادم القيد الفريد.
                    $toInserts[$item->product_id] = ($toInserts[$item->product_id] ?? 0) + $actualQty;
                }
            }

            foreach ($fromUpdates as $id => $newQty) {
                PharmacyInventory::where('id', $id)->update(['quantity' => $newQty]);
            }
            foreach ($toUpdates as $id => $newQty) {
                PharmacyInventory::where('id', $id)->update(['quantity' => $newQty]);
            }
            foreach ($toInserts as $productId => $qty) {
                PharmacyInventory::create(['pharmacy_id' => $transfer->to_pharmacy_id, 'product_id' => $productId, 'quantity' => $qty]);
            }

            $transfer->update(['status' => PharmacyTransferStatus::Completed, 'executed_date' => $now->toDateString()]);

            return new PharmacyTransferExecutionOutcome($fully, $partially, $skipped);
        });
    }

    public function dismiss(int $transferId): void
    {
        $transfer = PharmacyTransfer::find($transferId);

        if ($transfer === null) {
            throw new RuntimeException("مناقلة الصيدليات #{$transferId} غير موجودة.");
        }

        if ($transfer->status !== PharmacyTransferStatus::Suggested) {
            throw new RuntimeException("مناقلة الصيدليات #{$transferId} ليست بانتظار المراجعة (حالتها الحالية: {$transfer->status->label()}).");
        }

        $transfer->update(['status' => PharmacyTransferStatus::Dismissed]);
    }
}
