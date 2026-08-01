<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Collection;

final class PurchaseOrderGenerationResult
{
    /**
     * @param Collection<int, \App\Models\PurchaseOrder> $createdOrders
     * @param Collection<int, PurchaseLineCandidate> $deferredCandidates
     * @param Collection<int, string> $lockedSupplierNames أسماء الموردين الذين لديهم مسودة معلّقة
     * @param Collection<int, string> $conflictingCompanies 🆕 أسماء الشركات التي لها أكثر من مورد مفضِّل
     *        بلا اختيار محدَّد بعد لهذه الدفعة — عند وجودها، createdOrders تبقى فارغة عمداً
     *        (لا يُبنى شيء إلا بعد حل كل التعارضات)، ويجب توجيه المشرف لشاشة الحل.
     */
    public function __construct(
        public readonly Collection $createdOrders,
        public readonly Collection $deferredCandidates,
        public readonly int $eligibleProductsScanned,
        public readonly int $productsWithNoRequirement,
        public readonly int $productsWithNoSupplier,
        public readonly Collection $lockedSupplierNames,
        public readonly Collection $conflictingCompanies = new Collection(),
    ) {
    }

    public function totalEstimatedCost(): float
    {
        return round((float) $this->createdOrders->sum('total_estimated_cost'), 2);
    }

    public function hasLockedSuppliers(): bool
    {
        return $this->lockedSupplierNames->isNotEmpty();
    }

    /** 🆕 هل يحتاج التوليد تدخلاً يدوياً لحل تعارض موردين قبل إكماله؟ */
    public function hasSupplierConflicts(): bool
    {
        return $this->conflictingCompanies->isNotEmpty();
    }
}
