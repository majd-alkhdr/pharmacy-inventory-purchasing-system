<?php

namespace App\Services\Purchasing;

use App\Models\Product;
use App\Models\SupplierProduct;

/**
 * 🎓 لماذا خدمة مشتركة منفصلة بدل تكرار منطق "احسب أفضل سعر" داخل كل
 * سيناريو يحتاجه؟ لأن سيناريو "المتوازن" وسيناريو "الربحية الأعلى"
 * كلاهما يحتاجان بالضبط نفس السؤال: "بافتراض شراء كمية X، من هو المورد
 * الأرخص فعلياً (بعد احتساب أي بونص/خصم سارٍ)؟".
 */
class SupplierPricingResolver
{
    /**
     * أفضل خيار (مورد + تكلفة صافية) لشراء كمية محددة بالضبط من مادة معيّنة.
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

        if ($offer && $offer->hasAnyBenefit()) {
            $effectiveUnitCost = $offer->effectiveUnitCost((float) $supplierProduct->unit_cost, $quantity);
            $bonusQty = $offer->bonus_quantity;
            $note = "طُبِّق عرض ({$offer->label()}) من هذا المورد عند كمية {$quantity}+";
        }

        return new PurchaseLineDecision(
            productId: $supplierProduct->product_id,
            supplierProductId: $supplierProduct->id,
            finalQty: $quantity,
            unitCost: round($effectiveUnitCost, 4),
            bonusQty: $bonusQty,
            lineTotalCost: round($effectiveUnitCost * $quantity, 2),
            scenarioNote: $note,
            hasActiveOffer: $offer !== null && $offer->hasAnyBenefit(),
        );
    }
}
