<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\BudgetSetting;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Purchasing\Scenarios\PurchaseScenarioStrategyFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🎓 ==================== أهم صنف بكل نظام المشتريات ====================
 * هذا هو "المخرج" الفعلي الموصوف بقسم 4 من مستندك (Outputs): من الضغط
 * على زر "توليد فواتير الشراء المقترحة" وحتى سجلات حقيقية بقاعدة
 * البيانات جاهزة للعرض والاعتماد.
 *
 * لاحظ أن هذا الصنف **لا يحسب شيئاً بنفسه مباشرة** — فقط يُنسّق استدعاء
 * الأربع خدمات التي بنيناها بالدفعات الأربع السابقة، بترتيب واضح تماماً:
 *
 *   1) PurchaseEligibilityFilter    → من المؤهَّل أصلاً؟ (استبعاد الراكد)
 *   2) DemandCalculationService      → كم يحتاج كل منتج مؤهَّل؟
 *   3) PurchaseScenarioStrategyFactory → أي مورد/كمية حسب السيناريو المُختار؟
 *   4) BudgetAllocationService       → من يدخل الفاتورة فعلياً ضمن الميزانية؟
 *   5) (هنا فقط) → إنشاء سجلات PurchaseOrder/PurchaseOrderItem الفعلية
 *
 * هذا بالضبط نفس نمط InventoryUploadService (Orchestrator بحت) من
 * Phase A، لكن على نطاق أوسع بكثير — 4 خدمات متخصصة بدل 3.
 * ================================================================================
 */
class PurchaseOrderGenerationService
{
    public function __construct(
        private readonly PurchaseEligibilityFilter $eligibilityFilter,
        private readonly DemandCalculationService $demandCalculationService,
        private readonly PurchaseScenarioStrategyFactory $scenarioFactory,
        private readonly BudgetAllocationService $budgetAllocationService,
    ) {
    }

    public function generate(GeneratePurchaseOrdersParams $params): PurchaseOrderGenerationResult
    {
        $strategy = $this->scenarioFactory->make($params->scenario);
        [$budgetCap, $budgetCapSource] = $this->resolveBudgetCap($params);

        $candidates = collect();
        $eligibleCount = 0;
        $noRequirementCount = 0;
        $noSupplierCount = 0;

        $products = $this->eligibleProductsQuery($params->supplierId)->get();

        foreach ($products as $product) {
            $eligibleCount++;

            $demand = $this->demandCalculationService->calculateFor($product, $params->forecastPeriodDays);
            $isMarketShortage = $product->isMarketShortage();

            // 🎯 منتج "مقطوع من السوق" لكن معادلة الاحتياج تقول "لا حاجة حالياً"
            // (الرصيد الحالي كافٍ رغم انقطاع المورد)؟ لا داعٍ للشراء الآن —
            // قرار مقصود: قنص السوق يخدم "منع توقف المبيعات"، وليس "اشترِ
            // أي شيء متاح بغض النظر عن الاحتياج الفعلي".
            if (!$demand->hasRequirement()) {
                $noRequirementCount++;
                continue;
            }

            $decision = $strategy->decide($product, $demand);

            if (!$decision) {
                $noSupplierCount++; // لا يوجد أي مورد مرتبط بهذه المادة بالنظام بعد
                continue;
            }

            $candidates->push(new PurchaseLineCandidate(
                product: $product,
                demand: $demand,
                decision: $decision,
                isTargetItem: $product->isTarget(),
                isCriticalShortage: $demand->currentUsableStockQty <= 0 && $demand->dailyConsumptionRate > 0,
                isMarketShortage: $isMarketShortage,
            ));
        }

        $allocation = $this->budgetAllocationService->allocate($candidates, $budgetCap);

        $createdOrders = DB::transaction(fn () => $this->persistOrders(
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
        );
    }

    private function eligibleProductsQuery(?int $supplierId)
    {
        $query = $this->eligibilityFilter->eligibleProductsQuery()->with(['purchaseSetting', 'supplierProducts.offers']);

        if ($supplierId) {
            $query->whereHas('supplierProducts', fn ($q) => $q->where('supplier_id', $supplierId));
        }

        return $query;
    }

    /**
     * @return array{0: float|null, 1: string|null} [المبلغ, المصدر]
     */
    private function resolveBudgetCap(GeneratePurchaseOrdersParams $params): array
    {
        if ($params->manualBudgetOverride !== null) {
            return [$params->manualBudgetOverride, 'manual'];
        }

        $monthly = BudgetSetting::currentMonth();

        if ($monthly && $monthly->monthly_cap_amount !== null) {
            return [(float) $monthly->monthly_cap_amount, 'monthly'];
        }

        return [null, null]; // لا يوجد أي سقف ميزانية — يطبّق قرارك: "عدم تحديد أي ميزانية"
    }

    /**
     * @param Collection<int, RankedPurchaseLine> $includedLines
     * @return Collection<int, PurchaseOrder>
     */
    private function persistOrders(
        Collection $includedLines,
        GeneratePurchaseOrdersParams $params,
        ?float $budgetCap,
        ?string $budgetCapSource,
    ): Collection {
        $bySupplier = $includedLines->groupBy(
            fn (RankedPurchaseLine $line) => $line->candidate->decision->supplierProductId
                ? $line->candidate->product->supplierProducts
                    ->firstWhere('id', $line->candidate->decision->supplierProductId)
                    ?->supplier_id
                : null
        );

        $orders = collect();

        foreach ($bySupplier as $supplierId => $lines) {
            if (!$supplierId) {
                continue; // شبكة أمان — لا يجب أن يحصل هذا عملياً (راجع تعليق generate())
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
                $this->createOrderItem($order, $line);
            }

            $orders->push($order->fresh('items'));
        }

        return $orders;
    }

    private function createOrderItem(PurchaseOrder $order, RankedPurchaseLine $line): void
    {
        $candidate = $line->candidate;
        $decision = $candidate->decision;

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
            'explanation_summary'         => $this->buildExplanationSummary($candidate, $line),
            'explanation_factors'         => $this->buildExplanationFactors($candidate, $line),
        ]);
    }

    /**
     * 🎯 يطبّق حرفياً "لوحة التبرير" الموصوفة بقسم 8 من مستندك — يُركّب
     * نص التبرير الجاهز للعرض من (تبرير محرك الاحتياج) + (سبب اختيار
     * هذا المورد/الكمية من السيناريو) + (سبب ترتيب الأولوية إن وُجد).
     */
    private function buildExplanationSummary(PurchaseLineCandidate $candidate, RankedPurchaseLine $line): string
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

        return implode(' ', $parts);
    }

    private function buildExplanationFactors(PurchaseLineCandidate $candidate, RankedPurchaseLine $line): array
    {
        return array_merge($candidate->demand->toExplanationFactors(), [
            'is_target_item'            => $candidate->isTargetItem,
            'is_critical_shortage'      => $candidate->isCriticalShortage,
            'is_market_shortage'        => $candidate->isMarketShortage,
            'has_active_offer'           => $candidate->decision->hasActiveOffer,
            'priority_rank'               => $line->priorityRank,
            'is_over_budget_exception'   => $line->isOverBudgetException,
            'scenario_note'               => $candidate->decision->scenarioNote,
        ]);
    }
}
