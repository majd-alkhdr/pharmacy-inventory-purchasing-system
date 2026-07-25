<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Collection;

final class BudgetAllocationResult
{
    /**
     * @param Collection<int, RankedPurchaseLine> $includedLines أسطر ستدخل الفاتورة فعلياً
     * @param Collection<int, PurchaseLineCandidate> $deferredCandidates تجاوزت الميزانية ولم تُدرَج (تُرحَّل تلقائياً للطلبية القادمة)
     */
    public function __construct(
        public readonly Collection $includedLines,
        public readonly Collection $deferredCandidates,
        public readonly float $totalEstimatedCost,
        public readonly ?float $budgetCapAmount,
    ) {
    }

    public function hasDeferredItems(): bool
    {
        return $this->deferredCandidates->isNotEmpty();
    }

    public function budgetWasExceededByException(): bool
    {
        return $this->includedLines->contains(fn (RankedPurchaseLine $line) => $line->isOverBudgetException);
    }
}
