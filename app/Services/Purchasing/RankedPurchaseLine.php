<?php

namespace App\Services\Purchasing;

final class RankedPurchaseLine
{
    public function __construct(
        public readonly PurchaseLineCandidate $candidate,
        public readonly int $priorityRank,
        public readonly bool $isOverBudgetException,
    ) {
    }
}
