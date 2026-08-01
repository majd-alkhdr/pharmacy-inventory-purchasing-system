<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\BudgetSetting;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🎓 ==================== أهم صنف بكل نظام المشتريات ====================
 *
 * 🆕 إعادة هيكلة شاملة (2026-08) — التغييرات الجوهرية عن النسخة السابقة:
 *
 *   1) لا اختيار سيناريو — استراتيجية واحدة فقط عبر
 *      PriorityBasedPurchaseDecisionService.
 *
 *   2) طبقة استبعاد ثانية: منتجات دورانها بطيء جداً (> شهرين) تُستبعد
 *      تماماً، عبر PurchaseEligibilityFilter::filterEligibleWithTurnover().
 *
 *   3) إشارة أولوية "دوران عالٍ" جديدة عبر StockTurnoverClassifier.
 *
 *   4) اختيار المورد أصبح حسب الشركة المفضّلة (SupplierSelectionService).
 *      عند تعارض بلا حل مُسبَق بـ Session، تتوقف عملية التوليد بالكامل.
 *
 *   5) 🆕 (الجولة الثانية — تحسين أداء): استدعاء الدوران أصبح **دفعة
 *      واحدة لكل المنتجات المؤهَّلة معاً** (filterEligibleWithTurnover)
 *      بدل استدعاء منفصل لكل منتج داخل الحلقة — يقلّص عدد استعلامات
 *      الدوران من "عدد المنتجات" إلى استعلامين ثابتين بالمجمل، بغض النظر
 *      عن حجم الفهرسة. راجع TurnoverCalculator::bulkCalculate() للتفاصيل.
 */
class PurchaseOrderGenerationService
{
    public function __construct(
        private readonly PurchaseEligibilityFilter $eligibilityFilter,
        private readonly DemandCalculationService $demandCalculationService,
        private readonly PriorityBasedPurchaseDecisionService $decisionService,
        private readonly SupplierSelectionService $supplierSelection,
        private readonly BudgetAllocationService $budgetAllocationService,
        private readonly CrossSupplierOverlapDetector $overlapDetector,
    ) {
    }

    public function generate(GeneratePurchaseOrdersParams $params): PurchaseOrderGenerationResult
    {
        if ($params->supplierId && $this->supplierHasPendingDraft($params->supplierId)) {
            return $this->lockedResult($params->supplierId);
        }

        [$budgetCap, $budgetCapSource] = $this->resolveBudgetCap($params);

        $eligibleCount = 0;
        $noRequirementCount = 0;
        $noSupplierCount = 0;
        $candidates = collect();
        $conflictingCompanies = collect();

        $rawProducts = $this->eligibleProductsQuery($params->supplierId)->get();

        // 🆕 استعلامان فقط بالمجمل لكل المنتجات معاً (بدل N+1) — راجع
        // PurchaseEligibilityFilter::filterEligibleWithTurnover().
        $filtered = $this->eligibilityFilter->filterEligibleWithTurnover($rawProducts);
        $products = $filtered['eligible'];
        $turnoverStatuses = $filtered['turnoverStatuses']; // [product_id => TurnoverStatus]

        foreach ($products as $product) {
            $eligibleCount++;

            $demand = $this->demandCalculationService->calculateFor($product, $params->forecastPeriodDays);
            $isMarketShortage = $product->isMarketShortage();

            if (!$demand->hasRequirement()) {
                $noRequirementCount++;
                continue;
            }

            // 🆕 لا استعلام إضافي هنا — القيمة مُحسَّبة مسبقاً بالدفعة أعلاه
            $isHighTurnover = $turnoverStatuses[$product->id]->grantsHighTurnoverPriority();

            $supplierSelection = $this->supplierSelection->resolveFor($product, $params->supplierOverrides);

            if ($supplierSelection->isConflict()) {
                $conflictingCompanies->push($product->company);
                continue;
            }

            if ($supplierSelection->isNone()) {
                $noSupplierCount++;
                continue;
            }

            $decision = $this->decisionService->decide($product, $demand, $supplierSelection->supplierId);

            if (!$decision) {
                $noSupplierCount++;
                continue;
            }

            $candidates->push(new PurchaseLineCandidate(
                product: $product,
                demand: $demand,
                decision: $decision,
                isTargetItem: $product->isTarget(),
                isCriticalShortage: $demand->currentUsableStockQty <= 0 && $demand->dailyConsumptionRate > 0,
                isMarketShortage: $isMarketShortage,
                isHighTurnover: $isHighTurnover,
            ));
        }

        if ($conflictingCompanies->isNotEmpty()) {
            return new PurchaseOrderGenerationResult(
                createdOrders: collect(),
                deferredCandidates: collect(),
                eligibleProductsScanned: $eligibleCount,
                productsWithNoRequirement: $noRequirementCount,
                productsWithNoSupplier: $noSupplierCount,
                lockedSupplierNames: collect(),
                conflictingCompanies: $conflictingCompanies->unique()->values(),
            );
        }

        $allocation = $this->budgetAllocationService->allocate($candidates, $budgetCap);

        [$createdOrders, $lockedSupplierNames] = DB::transaction(fn () => $this->persistOrders(
            $allocation->includedLines,
            $params,
            $budgetCap,
            $budgetCapSource,
        ));

        return new PurchaseOrderGenerationResult(
            createdOrders: $createdOrders,
            deferredCandidates: $allocation->deferredCandidates,
            eligibleProductsScanned: $eligibleCount,
            productsWithNoRequirement: $noRequirementCount,
            productsWithNoSupplier: $noSupplierCount,
            lockedSupplierNames: $lockedSupplierNames,
        );
    }

    private function supplierHasPendingDraft(int $supplierId): bool
    {
        return PurchaseOrder::where('supplier_id', $supplierId)
            ->where('status', PurchaseOrderStatus::Draft->value)
            ->exists();
    }

    private function lockedResult(int $supplierId): PurchaseOrderGenerationResult
    {
        $name = Supplier::find($supplierId)?->name ?? "#{$supplierId}";

        return new PurchaseOrderGenerationResult(
            createdOrders: collect(),
            deferredCandidates: collect(),
            eligibleProductsScanned: 0,
            productsWithNoRequirement: 0,
            productsWithNoSupplier: 0,
            lockedSupplierNames: collect([$name]),
        );
    }

    private function eligibleProductsQuery(?int $supplierId)
    {
        $query = $this->eligibilityFilter->eligibleProductsQuery()->with(['purchaseSetting']);

        if ($supplierId) {
            $companies = SupplierCompany::where('supplier_id', $supplierId)->pluck('company_name');
            $query->whereIn('company', $companies);
        }

        return $query;
    }

    private function resolveBudgetCap(GeneratePurchaseOrdersParams $params): array
    {
        if ($params->manualBudgetOverride !== null) {
            return [$params->manualBudgetOverride, 'manual'];
        }

        $monthly = BudgetSetting::currentMonth();

        if ($monthly && $monthly->monthly_cap_amount !== null) {
            return [(float) $monthly->monthly_cap_amount, 'monthly'];
        }

        return [null, null];
    }

    /**
     * @param Collection<int, RankedPurchaseLine> $includedLines
     * @return array{0: Collection<int,PurchaseOrder>, 1: Collection<int,string>}
     */
    private function persistOrders(
        Collection $includedLines,
        GeneratePurchaseOrdersParams $params,
        ?float $budgetCap,
        ?string $budgetCapSource,
    ): array {
        $bySupplier = $includedLines->groupBy(
            fn (RankedPurchaseLine $line) => $line->candidate->decision->supplierId
        );

        $orders = collect();
        $lockedSupplierNames = collect();

        foreach ($bySupplier as $supplierId => $lines) {
            if (!$supplierId) {
                continue;
            }

            if ($this->supplierHasPendingDraft($supplierId)) {
                $lockedSupplierNames->push(Supplier::find($supplierId)?->name ?? "#{$supplierId}");
                continue;
            }

            $order = PurchaseOrder::create([
                'supplier_id'            => $supplierId,
                'status'                  => PurchaseOrderStatus::Draft,
                'scenario'                => $params->scenario,
                'forecast_period_days'   => $params->forecastPeriodDays,
                'generated_at'            => now(),
                'total_estimated_cost'   => round((float) $lines->sum(fn (RankedPurchaseLine $l) => $l->candidate->decision->lineTotalCost), 2),
                'budget_cap_amount'      => $budgetCap,
                'budget_cap_source'      => $budgetCapSource,
            ]);

            foreach ($lines as $line) {
                $this->createOrderItem($order, $line, $supplierId);
            }

            $orders->push($order->fresh('items'));
        }

        return [$orders, $lockedSupplierNames->unique()->values()];
    }

    private function createOrderItem(PurchaseOrder $order, RankedPurchaseLine $line, int $supplierId): void
    {
        $candidate = $line->candidate;
        $decision = $candidate->decision;
        $overlapWarning = $this->overlapDetector->warningFor($candidate->product->company, $supplierId);

        PurchaseOrderItem::create([
            'purchase_order_id'          => $order->id,
            'product_id'                  => $candidate->product->id,
            'supplier_product_id'        => $decision->supplierProductId,
            'suggested_qty'               => $decision->finalQty,
            'unit_cost'                   => $decision->unitCost,
            'bonus_qty'                   => $decision->bonusQty,
            'line_total_cost'             => $decision->lineTotalCost,
            'is_target_item'              => $candidate->isTargetItem,
            'is_market_shortage'          => $candidate->isMarketShortage,
            'is_over_budget_exception'   => $line->isOverBudgetException,
            'priority_rank'                => $line->priorityRank,
            'explanation_summary'         => $this->buildExplanationSummary($candidate, $line, $overlapWarning),
            'explanation_factors'         => $this->buildExplanationFactors($candidate, $line, $overlapWarning),
        ]);
    }

    private function buildExplanationSummary(PurchaseLineCandidate $candidate, RankedPurchaseLine $line, ?string $overlapWarning): string
    {
        $parts = [$candidate->demand->toExplanationSummary()];

        if ($candidate->decision->scenarioNote) {
            $parts[] = $candidate->decision->scenarioNote;
        }

        if ($line->priorityRank > 0) {
            $parts[] = "أولوية الإدراج بهذه الفاتورة: {$candidate->priorityLabel()} (الترتيب #{$line->priorityRank}).";
        }

        if ($line->isOverBudgetException) {
            $parts[] = '⚠️ تم تجاوز سقف الميزانية المحدَّد لهذا السطر تحديداً بسبب استثناء "قنص نواقص السوق".';
        }

        if ($overlapWarning) {
            $parts[] = $overlapWarning;
        }

        return implode(' ', $parts);
    }

    private function buildExplanationFactors(PurchaseLineCandidate $candidate, RankedPurchaseLine $line, ?string $overlapWarning): array
    {
        return array_merge($candidate->demand->toExplanationFactors(), [
            'is_target_item'            => $candidate->isTargetItem,
            'is_critical_shortage'      => $candidate->isCriticalShortage,
            'is_market_shortage'        => $candidate->isMarketShortage,
            'is_high_turnover'           => $candidate->isHighTurnover,
            'priority_rank'               => $line->priorityRank,
            'is_over_budget_exception'   => $line->isOverBudgetException,
            'scenario_note'               => $candidate->decision->scenarioNote,
            'cross_supplier_overlap_warning' => $overlapWarning,
        ]);
    }
}
