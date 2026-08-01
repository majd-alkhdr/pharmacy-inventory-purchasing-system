<?php

namespace App\Services\Purchasing;

use App\Models\Product;

/**
 * 🎯 يستبدل بالكامل PurchaseScenarioStrategyFactory والاستراتيجيات الثلاث
 * (RestockingOnly / MaxProfitability / Balanced) — لم يعد هناك اختيار
 * سيناريو من المشرف؛ استراتيجية واحدة فقط تُطبَّق دائماً، بناءً على قرار
 * صاحب المشروع الصريح: "حالياً لا يوجد أي عروض أو خصومات بالنظام، فلا
 * معنى لثلاثة سيناريوهات مبنية على المقارنة السعرية."
 *
 * ⚠️ الملفات الثلاثة القديمة (RestockingOnlyScenarioService,
 * MaxProfitabilityScenarioService, BalancedScenarioService) وPurchaseScenario
 * Enum وPurchaseScenarioStrategyFactory **تبقى موجودة بالكود بلا حذف**
 * (بقرار صريح من صاحب المشروع لتأجيل التنظيف)، لكنها لم تعد تُستدعى من
 * PurchaseOrderGenerationService إطلاقاً بعد اليوم.
 *
 * لا سعر يُحسَب هنا بعد (unitCost = 0) — راجع وثيقة التصميم §9.
 */
class PriorityBasedPurchaseDecisionService
{
    public function __construct(
        private readonly SupplierSelectionService $supplierSelection,
    ) {
    }

    /**
     * @return PurchaseLineDecision|null null تعني: "لا يوجد مورد مؤهَّل
     *         بعد لهذه المادة" — يُحسَب ضمن productsWithNoSupplier.
     *         SupplierSelectionResult::isConflict() تُعامَل بشكل خاص من
     *         المُستدعي (PurchaseOrderGenerationService) قبل الوصول هنا
     *         أصلاً — راجع SupplierSelectionService للتفاصيل.
     */
    public function decide(Product $product, ProductDemandCalculation $demand, ?int $overrideSupplierId = null): ?PurchaseLineDecision
    {
        if (!$demand->hasRequirement()) {
            return null;
        }

        $supplierId = $overrideSupplierId ?? $this->supplierSelection->resolveSingleSupplierFor($product);

        if (!$supplierId) {
            return null;
        }

        $qty = $demand->requiredQty;

        return new PurchaseLineDecision(
            productId: $product->id,
            supplierId: $supplierId,
            finalQty: $qty,
            unitCost: 0.0,
            bonusQty: 0,
            lineTotalCost: 0.0,
            scenarioNote: 'تم اختيار المورد تلقائياً حسب الشركة المفضّلة المسجَّلة له.',
        );
    }
}
