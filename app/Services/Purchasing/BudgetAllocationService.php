<?php
// app/Services/Purchasing/BudgetAllocationService.php
namespace App\Services\Purchasing;

use Illuminate\Support\Collection;

class BudgetAllocationService
{
    public function allocate(Collection $candidates, ?float $budgetCap): BudgetAllocationResult
    {
        $includedLines = collect();
        $deferredCandidates = collect();
        $runningTotal = 0.0;
        $rank = 0;

        // 🆕 لا استثناء "قنص نواقص سوق" بعد الآن — كل الأصناف تخضع لنفس
        // قاعدة الميزانية بلا تمييز، بلا ترتيب أولوية (مُجمَّد سابقاً).
        foreach ($candidates as $candidate) {
            $cost = $candidate->decision->lineTotalCost;
            $fitsBudget = $budgetCap === null || ($runningTotal + $cost) <= $budgetCap;

            if (!$fitsBudget) {
                $deferredCandidates->push($candidate);
                continue;
            }

            $runningTotal += $cost;
            $includedLines->push(new RankedPurchaseLine(candidate: $candidate, priorityRank: ++$rank, isOverBudgetException: false));
        }

        return new BudgetAllocationResult(
            includedLines: $includedLines,
            deferredCandidates: $deferredCandidates,
            totalEstimatedCost: round($runningTotal, 2),
            budgetCapAmount: $budgetCap,
        );
    }
}
