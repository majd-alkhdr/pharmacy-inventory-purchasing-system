<?php

declare(strict_types=1);

namespace App\Services\Inventory\Transfers;

use App\Enums\PharmacyTransferStatus;
use App\Models\PharmacyTransfer;
use App\Models\ReverseTransfer;
use App\Services\Inventory\Surplus\SurplusEngine;
use App\Services\Inventory\Surplus\SurplusSnapshot;
use Illuminate\Support\Collection;

/**
 * 🎯 (ب.6/ب.7) ما تعرضه شاشتا المراجعة كـ"بانتظار التنفيذ" — مقترحات **الدورة الحالية**
 * فقط، وكل بند مُعلَّم: هل ما زال فائضه يغطي كميته بالكامل؟ مستند لم يبقَ فيه بند مغطى
 * يختفي من القائمة — هذا هو "الاختفاء التلقائي" للإلغاء المتبادل.
 *
 * 🎓 لماذا Query Object منفصل لا منطق بالكونترولر؟ (1) Skinny Controllers. (2) لقطة الفائض
 * تُحسب **مرة واحدة** لكل مواد كل المستندات المعروضة (لا استعلام لكل بند)، وهذا تفصيل أداء
 * لا يعني الكونترولر. (3) شاشتان (عكسية، بين صيدليات) تحتاجان نفس التعليم بنفس القاعدة.
 *
 * يضيف لكل بند خاصيتين وقت التشغيل (لا أعمدة): is_covered، available_now.
 */
final class PendingTransfersQuery
{
    public function __construct(
        private readonly SurplusEngine $surplusEngine,
    ) {
    }

    /** @return Collection<int, ReverseTransfer> */
    public function reverseTransfers(?int $pharmacyId = null): Collection
    {
        $cycle = $this->surplusEngine->currentCycle();

        $transfers = ReverseTransfer::suggested()->inCycle($cycle->id)
            ->forPharmacy($pharmacyId)
            ->with(['pharmacy', 'items.product'])
            ->latest('id')
            ->get();

        return $this->annotate($transfers, fn (ReverseTransfer $t) => (int) $t->pharmacy_id);
    }

    /** @return Collection<int, PharmacyTransfer> */
    public function pharmacyTransfers(): Collection
    {
        $cycle = $this->surplusEngine->currentCycle();

        $transfers = PharmacyTransfer::where('status', PharmacyTransferStatus::Suggested)->inCycle($cycle->id)
            ->with(['fromPharmacy', 'toPharmacy', 'items.product'])
            ->latest('id')
            ->get();

        return $this->annotate($transfers, fn (PharmacyTransfer $t) => (int) $t->from_pharmacy_id);
    }

    /**
     * @template T of ReverseTransfer|PharmacyTransfer
     * @param Collection<int, T> $transfers
     * @param callable(T): int $sourcePharmacy
     * @return Collection<int, T>
     */
    private function annotate(Collection $transfers, callable $sourcePharmacy): Collection
    {
        if ($transfers->isEmpty()) {
            return $transfers;
        }

        $productIds = $transfers->flatMap(fn ($t) => $t->items->pluck('product_id'))->unique()->values()->all();
        $snapshot = $this->surplusEngine->snapshot($productIds);

        return $transfers
            ->each(fn ($t) => $this->annotateItems($t, $sourcePharmacy($t), $snapshot))
            ->filter(fn ($t) => $t->items->contains(fn ($item) => $item->is_covered))
            ->values();
    }

    private function annotateItems(ReverseTransfer|PharmacyTransfer $transfer, int $sourcePharmacyId, SurplusSnapshot $snapshot): void
    {
        foreach ($transfer->items as $item) {
            $item->available_now = $snapshot->available($sourcePharmacyId, (int) $item->product_id);
            $item->is_covered = $snapshot->covers($sourcePharmacyId, (int) $item->product_id, (int) $item->quantity);
        }
    }
}
