<?php

namespace App\Services\Purchasing\Scenarios;

use App\Models\Product;
use App\Services\Purchasing\ProductDemandCalculation;
use App\Services\Purchasing\PurchaseLineDecision;
use App\Services\Purchasing\SupplierPricingResolver;
use Illuminate\Support\Collection;

/**
 * 🎯 يطبّق وصف مستندك بالكامل: *"يوجّه النظام الطلبات نحو الموردين الذين
 * يقدّمون أفضل بونص أو عروض مركّبة. يرفع كميات الطلب لأصناف التارجت إذا
 * كان ذلك سيحقق شريحة خصم أعلى."*
 *
 * 🎓 قرار تصميمي مهم يستحق الشرح الصريح: **لماذا "تضخيم الكمية لتفعيل
 * شريحة أعلى" مقصور على أصناف التارجت فقط، وليس كل مادة؟**
 * لأن شراء كمية أكبر من المطلوب فعلياً = تجميد رأس مال إضافي بمخزون لم
 * يُطلب بعد. هذا قرار مالي يستحق مبرراً استراتيجياً واضحاً — ومستندك
 * حدّد هذا المبرر بدقة: "لضمان تحقيق أهداف المبيعات الاستراتيجية"
 * (تعريف is_target بجدول product_purchase_settings). لأي مادة أخرى غير
 * مُعلَّمة تارجت، هذا السيناريو يتصرّف تماماً كسيناريو "المتوازن": يختار
 * أفضل سعر **عند الكمية المطلوبة بالضبط**، دون تضخيم غير مبرَّر.
 *
 * ⚠️ حماية إضافية أضفتها بنفسي لم يطلبها المستند صراحة لكنها ضرورة
 * مالية بديهية: **سقف تضخيم أقصى** (لا يتجاوز ضعف الكمية المطلوبة
 * أصلاً) — لمنع النظام من اقتراح شراء كميات هائلة لمجرد وجود عرض جذّاب
 * على شريحة بعيدة جداً عن الاحتياج الفعلي. راجع MAX_INFLATION_MULTIPLIER.
 */
class MaxProfitabilityScenarioService implements PurchaseScenarioStrategy
{
    /** 🩹 حماية مالية: لا نضخّم الكمية لأكثر من ضعف الاحتياج الفعلي مهما كان العرض مغرياً */
    private const MAX_INFLATION_MULTIPLIER = 2;

    public function __construct(
        private readonly SupplierPricingResolver $pricingResolver,
    ) {
    }

    public function decide(Product $product, ProductDemandCalculation $demand): ?PurchaseLineDecision
    {
        if (!$demand->hasRequirement()) {
            return null;
        }

        $candidates = collect([$this->pricingResolver->resolveBestChoiceAt($product, $demand->requiredQty)])
            ->filter();

        if ($product->isTarget()) {
            $candidates = $candidates->merge($this->tierEscalationCandidates($product, $demand->requiredQty));
        }

        return $candidates->isEmpty() ? null : $candidates->sortBy('unitCost')->first();
    }

    /**
     * يفحص كل عروض كل الموردين المرتبطين بهذه المادة، ويُولّد "مرشحاً"
     * إضافياً لكل شريحة تتجاوز الاحتياج الحالي لكن تبقى ضمن سقف الحماية
     * المالية — لنقارنها لاحقاً بالخيار الأساسي حسب التكلفة الصافية.
     */
    private function tierEscalationCandidates(Product $product, int $requiredQty): Collection
    {
        $ceiling = $requiredQty * self::MAX_INFLATION_MULTIPLIER;
        $candidates = collect();

        foreach ($product->supplierProducts as $supplierProduct) {
            foreach ($supplierProduct->offers as $offer) {
                $isEligibleTier = $offer->min_quantity > $requiredQty
                    && $offer->min_quantity <= $ceiling
                    && $offer->isActiveNow();

                if (!$isEligibleTier) {
                    continue;
                }

                // 🩹 نمرر $supplierProduct المتوفر أصلاً بالسياق الخارجي بدل
                // الاعتماد على $offer->supplierProduct (كان سيُنفّذ استعلام
                // إضافي غير ضروري لكل عرض — N+1 Query صغير لكن قابل للتفادي).
                $decision = $this->pricingResolver->evaluateAt($supplierProduct, $offer->min_quantity);

                $candidates->push(new PurchaseLineDecision(
                    productId: $decision->productId,
                    supplierProductId: $decision->supplierProductId,
                    finalQty: $decision->finalQty,
                    unitCost: $decision->unitCost,
                    bonusQty: $decision->bonusQty,
                    lineTotalCost: $decision->lineTotalCost,
                    scenarioNote: "صنف تارجت — رُفعت الكمية إلى {$offer->min_quantity} لتفعيل شريحة عرض أفضل ({$offer->bonus_type->label()}).",
                    hasActiveOffer: true,
                ));
            }
        }

        return $candidates;
    }
}
