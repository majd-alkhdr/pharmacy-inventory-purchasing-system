<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Collection;

final class PurchaseOrderGenerationResult
{
    /**
     * @param Collection<int, \App\Models\PurchaseOrder> $createdOrders
     * @param Collection<int, PurchaseLineCandidate> $deferredCandidates
     */
    public function __construct(
        public readonly Collection $createdOrders,
        public readonly Collection $deferredCandidates,
        public readonly int $eligibleProductsScanned,
        public readonly int $productsWithNoRequirement,
        public readonly int $productsWithNoSupplier,
    ) {
    }

    public function totalEstimatedCost(): float
    {
        return round((float) $this->createdOrders->sum('total_estimated_cost'), 2);
    }
}
