<?php

namespace App\Services\Purchasing;

use App\Enums\BonusType;
use App\Models\Product;
use App\Models\SupplierProduct;

/**
 * 🎓 لماذا خدمة مشتركة منفصلة بدل تكرار منطق "احسب أفضل سعر" داخل كل
 * سيناريو يحتاجه؟
 * ------------------------------------------------------------------
 * سيناريو "المتوازن" وسيناريو "الربحية الأعلى" **كلاهما** يحتاجان بالضبط
 * نفس السؤال: "بافتراض أننا نشتري كمية X من هذه المادة، من هو المورد
 * الأرخص فعلياً (بعد احتساب أي عرض بونص أو خصم سارٍ)؟". الفرق الوحيد
 * بينهما هو: هل نُثبّت X عند الاحتياج المحسوب فقط (المتوازن)، أم نُجرّب
 * أيضاً كميات أعلى بحثاً عن شريحة عرض أفضل (الربحية الأعلى)؟
 *
 * هذا بالضبط نفس نمط CentralInventoryStockService::applyInventoryReplace()
 * بالمرحلة الأولى (تقرير [004]) — منطق مشترك بين مستهلكَين مختلفين،
 * فوُضع بخدمة واحدة تُحقَن بكليهما، بدل نسخه مرتين.
 */
class SupplierPricingResolver
{
    /**
     * أفضل خيار (مورد + تكلفة صافية) لشراء كمية محددة بالضبط من مادة معيّنة،
     * عبر كل الموردين المرتبطين بها — بدون أي محاولة لتعديل الكمية نفسها.
     */
    public function resolveBestChoiceAt(Product $product, int $quantity): ?PurchaseLineDecision
    {
        if ($quantity <= 0) {
            return null;
        }

        $candidates = $product->supplierProducts
            ->map(fn (SupplierProduct $sp) => $this->evaluateAt($sp, $quantity));

        return $candidates->isEmpty() ? null : $candidates->sortBy('unitCost')->first();
    }

    /** تقييم مورد واحد بعينه عند كمية محددة (مع تطبيق أفضل عرض سارٍ إن وُجد) */
    public function evaluateAt(SupplierProduct $supplierProduct, int $quantity): PurchaseLineDecision
    {
        $offer = $supplierProduct->bestActiveOfferFor($quantity);

        $effectiveUnitCost = (float) $supplierProduct->unit_cost;
        $bonusQty = 0;
        $note = null;

        if ($offer) {
            $effectiveUnitCost = $offer->bonus_type->effectiveUnitCost(
                (float) $supplierProduct->unit_cost,
                (float) $offer->bonus_value,
                $quantity
            );

            if ($offer->bonus_type === BonusType::FreeQuantity) {
                $bonusQty = (int) $offer->bonus_value;
            }

            $note = "طُبِّق عرض ({$offer->bonus_type->label()}) من هذا المورد عند كمية {$quantity}+";
        }

        return new PurchaseLineDecision(
            productId: $supplierProduct->product_id,
            supplierProductId: $supplierProduct->id,
            finalQty: $quantity,
            unitCost: round($effectiveUnitCost, 4),
            bonusQty: $bonusQty,
            lineTotalCost: round($effectiveUnitCost * $quantity, 2),
            scenarioNote: $note,
            hasActiveOffer: $offer !== null,
        );
    }
}
