<?php
// app/Services/Purchasing/PurchaseOrderGenerationService.php
namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\BudgetSetting;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🎓 الجولة الرابعة — تجميد محرك التنبؤ (ConsumptionRateCalculator +
 * StockTurnoverClassifier + PurchaseEligibilityFilter) بالكامل، استبداله
 * بـRequiredQuantityProvider (نواقص/مخزون قياسي/الأكبر بينهما)، وربط سعر
 * حقيقي من product_purchase_prices بدل 0.0 الثابت سابقاً.
 *
 * 🆕 (2026-10) "آخر سعر شراء" أُلغي مع ملف جرد المشتريات — سعر الوحدة صار "سعر المادة" الحالي
 * من ملف الأصناف (central_inventory_prices)، المصدر الوحيد للسعر بالنظام.
 */
class PurchaseOrderGenerationService
{
    public function __construct(
        private readonly RequiredQuantityProviderResolver $requiredQuantityResolver,
        private readonly PriorityBasedPurchaseDecisionService $decisionService,
        private readonly SupplierSelectionService $supplierSelection,
        private readonly BudgetAllocationService $budgetAllocationService,
        private readonly CrossSupplierOverlapDetector $overlapDetector,
    ) {}

    /**
     * 🆕 (ج.1) النطاق يأتي موحَّداً من GeneratePurchaseOrdersParams::supplierScope() — بصرف النظر
     * عن الاستراتيجية التي أنتجته (موردون مباشرة / مشتق من شركة). كل ما يلي (احتياج، اختيار مورد
     * لكل مادة، ميزانية، فاتورة مستقلة لكل مورد) مسار واحد لم يتكرر لأي استراتيجية.
     */
    public function generate(GeneratePurchaseOrdersParams $params): PurchaseOrderGenerationResult
    {
        $scope = $params->supplierScope();

        // كل الموردين المختارين لديهم مسودة معلّقة ⇒ لا شيء يمكن توليده إطلاقاً. (بعضهم فقط ⇒
        // يُتخطّون عند الحفظ ويُذكرون بالرسالة — نفس سلوك "كل الموردين" القديم.)
        if (!$scope->isAllSuppliers()) {
            $locked = array_values(array_filter($scope->supplierIds, fn (int $id) => $this->supplierHasPendingDraft($id)));
            if (count($locked) === count($scope->supplierIds)) {
                return $this->lockedResult(...$locked);
            }
        }

        [$budgetCap, $budgetCapSource] = $this->resolveBudgetCap($params);

        $eligibleCount = 0;
        $noRequirementCount = 0;
        $noSupplierCount = 0;
        $candidates = collect();
        $conflictingCompanies = collect();

        // 🆕 لا PurchaseEligibilityFilter بعد الآن (استبعاد الركود/التصفية
        // مُجمَّد بالكامل) — الاستبعاد الوحيد المتبقي هو شركة المورد
        // المحدَّد إن وُجد، كما كان تماماً.
        $products = $this->eligibleProductsQuery($scope)->get();

        $provider = $this->requiredQuantityResolver->resolve($params);
        $requirements = $provider->bulkCalculate($products);
        $pricesByProduct = $this->bulkLatestPrices($products->pluck('id'));

        foreach ($products as $product) {
            $eligibleCount++;

            $requirement = $requirements[$product->id];

            if (!$requirement->hasRequirement()) {
                $noRequirementCount++;
                continue;
            }

            $supplierSelection = $this->supplierSelection->resolveFor($product, $params->supplierOverrides, $scope->supplierIds);

            if ($supplierSelection->isConflict()) {
                $conflictingCompanies->push($product->company);
                continue;
            }

            if ($supplierSelection->isNone()) {
                $noSupplierCount++;
                continue;
            }

            $unitCost = (float) ($pricesByProduct[$product->id] ?? 0.0);
            $decision = $this->decisionService->decide($product, $requirement, $unitCost, $supplierSelection->supplierId);

            if (!$decision) {
                $noSupplierCount++;
                continue;
            }

            $candidates->push(new PurchaseLineCandidate(
                product: $product,
                requirement: $requirement,
                decision: $decision,
                isTargetItem: $product->isTarget(),
                isMarketShortage: $product->isMarketShortage(),
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

        [$createdOrders, $lockedSupplierNames] = DB::transaction(fn() => $this->persistOrders(
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
        return PurchaseOrder::where('supplier_id', $supplierId)->where('status', PurchaseOrderStatus::Draft->value)->exists();
    }

    private function lockedResult(int ...$supplierIds): PurchaseOrderGenerationResult
    {
        $names = Supplier::whereIn('id', $supplierIds)->pluck('name');

        return new PurchaseOrderGenerationResult(
            createdOrders: collect(),
            deferredCandidates: collect(),
            eligibleProductsScanned: 0,
            productsWithNoRequirement: 0,
            productsWithNoSupplier: 0,
            lockedSupplierNames: $names->isEmpty() ? collect(array_map(fn ($id) => "#{$id}", $supplierIds)) : $names,
        );
    }

    /**
     * 🆕 (ج.1) المواد المؤهلة = شركات **كل** الموردين المختارين معاً (اتحاد)، محصورة بشركة واحدة
     * إن جاء النطاق من استراتيجية الشركة.
     */
    private function eligibleProductsQuery(\App\Services\Purchasing\SupplierScope\SupplierScope $scope)
    {
        // 🆕 with('purchaseSetting') يبقى — ليس لغرض الاستبعاد بعد الآن،
        // بل فقط لتفادي N+1 عند قراءة isTarget() (معلوماتي) داخل الحلقة.
        $query = Product::query()->with('purchaseSetting');

        if (!$scope->isAllSuppliers()) {
            $companies = SupplierCompany::whereIn('supplier_id', $scope->supplierIds)->pluck('company_name')->unique();
            $query->whereIn('company', $companies);
        }

        if ($scope->companyFilter !== null) {
            $query->where('company', $scope->companyFilter);
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

    /** 🎯 "سعر المادة" الحالي لكل منتج بمجموعة IDs معاً — استعلامان ثابتان (CentralInventoryPrice::latestForMany) */
    private function bulkLatestPrices(Collection $productIds): array
    {
        return \App\Models\CentralInventoryPrice::latestForMany($productIds->map(fn ($id) => (int) $id)->all());
    }

    private function persistOrders(Collection $includedLines, GeneratePurchaseOrdersParams $params, ?float $budgetCap, ?string $budgetCapSource): array
    {
        $bySupplier = $includedLines->groupBy(fn(RankedPurchaseLine $line) => $line->candidate->decision->supplierId);

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
                'supplier_id'           => $supplierId,
                'status'                 => PurchaseOrderStatus::Draft,
                'scenario'               => $params->scenario,
                'forecast_period_days'  => $params->forecastPeriodDays,
                'generated_at'           => now(),
                'total_estimated_cost'  => round((float) $lines->sum(fn(RankedPurchaseLine $l) => $l->candidate->decision->lineTotalCost), 2),
                'budget_cap_amount'     => $budgetCap,
                'budget_cap_source'     => $budgetCapSource,
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
            'purchase_order_id'         => $order->id,
            'product_id'                 => $candidate->product->id,
            'supplier_product_id'       => $decision->supplierProductId,
            'suggested_qty'              => $decision->finalQty,
            'unit_cost'                  => $decision->unitCost,
            'bonus_qty'                  => $decision->bonusQty,
            'line_total_cost'            => $decision->lineTotalCost,
            'is_target_item'             => $candidate->isTargetItem,
            'is_market_shortage'         => $candidate->isMarketShortage,
            'is_over_budget_exception'  => $line->isOverBudgetException,
            'priority_rank'               => $line->priorityRank,
            'explanation_summary'        => $this->buildExplanationSummary($candidate, $line, $overlapWarning),
            'explanation_factors'        => $this->buildExplanationFactors($candidate, $line, $overlapWarning),
        ]);
    }

    private function buildExplanationSummary(PurchaseLineCandidate $candidate, RankedPurchaseLine $line, ?string $overlapWarning): string
    {
        $parts = [$candidate->requirement->explanationSummary];

        if ($candidate->decision->scenarioNote) {
            $parts[] = $candidate->decision->scenarioNote;
        }

        if ($candidate->isTargetItem) {
            $parts[] = 'صنف تارجت (معلوماتي فقط — لا يؤثر على الأولوية حالياً).';
        }


        if ($overlapWarning) {
            $parts[] = $overlapWarning;
        }

        return implode(' ', $parts);
    }

    private function buildExplanationFactors(PurchaseLineCandidate $candidate, RankedPurchaseLine $line, ?string $overlapWarning): array
    {
        return array_merge($candidate->requirement->explanationFactors, [
            'is_target_item'                  => $candidate->isTargetItem,
            'is_market_shortage'              => $candidate->isMarketShortage,
            'is_over_budget_exception'        => $line->isOverBudgetException,
            'cross_supplier_overlap_warning'  => $overlapWarning,
        ]);
    }
}
