<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Collection;

/**
 * 🎯 يطبّق قسم 5 من مستندك حرفياً: *"في حال كانت قيمة الاحتياجات تتجاوز
 * الميزانية المحددة... يتدخل النظام لترتيب الأولويات آلياً وفق التسلسل
 * التالي حتى استنفاد الميزانية: (1) أصناف التارجت، (2) النواقص الحرجة،
 * (3) أصناف العروض الاستثنائية... ما يفيض عن الميزانية يُرحَّل كمسودة
 * للطلبيات القادمة."*
 *
 * وقسم 6: *"نظراً لندرة الأصناف المقطوعة وأهميتها، يمنحها النظام
 * استثناءً لتجاوز سقف الميزانية المحدد فور توفرها."* (Budget Override)
 *
 * 🎓 القرار التصميمي الأهم هنا: **"قنص نواقص السوق" لا يدخل مطلقاً ضمن
 * منافسة الأولوية الثلاثية (تارجت/نقص حرج/عروض)** — بل يُعامَل بمعزل
 * تام قبلها: يُدرَج **دائماً** بالكامل أولاً (بصرف النظر عن الميزانية)،
 * ثم تُوزَّع الميزانية **المتبقية فقط** على البقية حسب الأولوية الثلاثية.
 * هذا يطابق حرفياً وصف مستندك بأن هذا الاستثناء "خارج" منظومة الأولوية
 * العادية تماماً، وليس مجرد "أعلى أولوية داخلها".
 */
class BudgetAllocationService
{
    /**
     * @param Collection<int, PurchaseLineCandidate> $candidates
     */
    public function allocate(Collection $candidates, ?float $budgetCap): BudgetAllocationResult
    {
        [$marketShortageCandidates, $regularCandidates] = $candidates->partition(
            fn (PurchaseLineCandidate $c) => $c->isMarketShortage
        );

        $includedLines = collect();
        $runningTotal = 0.0;
        $rank = 0;

        // ══ الخطوة 1: قنص نواقص السوق — يُدرَج بالكامل دائماً، بلا شرط ميزانية ══
        foreach ($marketShortageCandidates as $candidate) {
            $cost = $candidate->decision->lineTotalCost;
            $exceedsBudget = $budgetCap !== null && ($runningTotal + $cost) > $budgetCap;

            $runningTotal += $cost;
            $includedLines->push(new RankedPurchaseLine(
                candidate: $candidate,
                priorityRank: ++$rank,
                isOverBudgetException: $exceedsBudget,
            ));
        }

        // ══ الخطوة 2: البقية مرتّبة حسب الأولوية الثلاثية، ضمن الميزانية المتبقية فقط ══
        $sortedRegular = $regularCandidates->sortByDesc(fn (PurchaseLineCandidate $c) => $c->priorityScore());

        $deferredCandidates = collect();

        foreach ($sortedRegular as $candidate) {
            $cost = $candidate->decision->lineTotalCost;
            $fitsBudget = $budgetCap === null || ($runningTotal + $cost) <= $budgetCap;

            if (!$fitsBudget) {
                $deferredCandidates->push($candidate);
                continue;
            }

            $runningTotal += $cost;
            $includedLines->push(new RankedPurchaseLine(
                candidate: $candidate,
                priorityRank: ++$rank,
                isOverBudgetException: false,
            ));
        }

        return new BudgetAllocationResult(
            includedLines: $includedLines,
            deferredCandidates: $deferredCandidates,
            totalEstimatedCost: round($runningTotal, 2),
            budgetCapAmount: $budgetCap,
        );
    }
}
