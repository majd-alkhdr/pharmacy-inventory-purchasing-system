<?php

namespace App\Services\Purchasing\Scenarios;

use App\Models\Product;
use App\Models\SupplierProduct;
use App\Services\Purchasing\ProductDemandCalculation;
use App\Services\Purchasing\PurchaseLineDecision;

/**
 * 🎯 يطبّق وصف مستندك حرفياً: *"يولّد طلبات شراء تغطي النواقص الصفرية
 * والكميات التي هبطت تحت الحد الأدنى فقط، بغض النظر عن العروض. الهدف:
 * توفير السيولة وتوفير البضاعة بأسرع وقت."*
 *
 * 🎓 لاحظ أن هذا السيناريو **الوحيد من الثلاثة** الذي لا يستخدم
 * SupplierPricingResolver إطلاقاً — وهذا **مقصود تماماً وليس نسياناً**:
 * بما أن الهدف "السرعة" وليس "أفضل سعر"، لا داعي لحساب أي عروض بونص أو
 * مقارنة موردين متعددين؛ فقط: هل يوجد مورد مفضَّل (is_preferred)؟ استخدمه
 * مباشرة. إن لم يوجد، اختر الأرخص بسعره الأساسي فقط (بدون فحص عروض).
 */
class RestockingOnlyScenarioService implements PurchaseScenarioStrategy
{
    public function decide(Product $product, ProductDemandCalculation $demand): ?PurchaseLineDecision
    {
        if (!$demand->hasRequirement()) {
            return null;
        }

        $supplierProduct = $this->pickSupplier($product);

        if (!$supplierProduct) {
            return null; // لا يوجد أي مورد مرتبط بهذه المادة إطلاقاً بالنظام بعد
        }

        $qty = $demand->requiredQty;
        $unitCost = (float) $supplierProduct->unit_cost;

        return new PurchaseLineDecision(
            productId: $product->id,
            supplierProductId: $supplierProduct->id,
            finalQty: $qty,
            unitCost: $unitCost,
            bonusQty: 0, // 🎯 بغض النظر عن العروض — هذا السيناريو لا يطبّق أي بونص عمداً
            lineTotalCost: round($unitCost * $qty, 2),
            scenarioNote: 'سيناريو ترميم سريع — تم تجاهل أي عروض متاحة لصالح توفير البضاعة بأسرع وقت.',
        );
    }

    private function pickSupplier(Product $product): ?SupplierProduct
    {
        return $product->supplierProducts->firstWhere('is_preferred', true)
            ?? $product->supplierProducts->sortBy('unit_cost')->first();
    }
}
