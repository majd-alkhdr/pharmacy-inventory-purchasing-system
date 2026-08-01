<?php

namespace App\Services\Purchasing\Scenarios;

use App\Models\Product;
use App\Services\Purchasing\ProductDemandCalculation;
use App\Services\Purchasing\PurchaseLineDecision;
use App\Services\Purchasing\SupplierPricingResolver;
use Illuminate\Support\Collection;

/**
 * 🎯 إعادة تعريف كاملة بناءً على توضيح المستخدم الصريح: الهدف الحقيقي
 * هو "أي مادة تعطيني ربحاً أكبر عند بيعها" — أي **الهامش الفعلي**
 * (سعر البيع الرسمي − التكلفة الصافية بعد بونص/خصم)، وليس فقط أرخص
 * تكلفة شراء كما كانت النسخة السابقة تفعل (كانت تقارن تكلفة الوحدة
 * فقط، بمعزل تام عن سعر البيع — وهذا لم يكن يقيس "ربحاً" حقيقياً).
 *
 * 🎓 لماذا لا يزال تضخيم الكمية مقصوراً على أصناف التارجت فقط، ونفس
 * سقف الحماية المالية (MAX_INFLATION_MULTIPLIER)؟ هذا القرار لم
 * يتغيّر — لا يزال شراء كمية أكبر من المطلوب = تجميد رأس مال إضافي،
 * وهذا قرار استراتيجي مقصور على أصناف التارجت فقط (نفس التبرير
 * الأصلي، لم يتغيّر بإعادة التعريف).
 *
 * ⚠️ حالة "لا يوجد سعر بيع مسجَّل لهذه المادة" (marginPerUnit = null):
 * لا يمكن اعتبارها "ربحاً صفرياً" (قد تكون مادة بربح ضخم فعلياً، لكن
 * سعرها لم يُستورَد بعد). لذلك: أي مرشّح بهامش معروف يُفضَّل دائماً
 * على أي مرشّح بهامش غير معروف، بصرف النظر عن التكلفة — لأننا لا نملك
 * معلومة كافية للمفاضلة الصحيحة بالحالة الثانية. فقط لو **كل** المرشحين
 * بلا سعر بيع مسجَّل، نتراجع لاختيار الأرخص تكلفة (سلوك السيناريو
 * المتوازن) كحل احتياطي معقول، مع علامة صريحة بالتفسير النهائي.
 */
class MaxProfitabilityScenarioService implements PurchaseScenarioStrategy
{
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

        $sellingPrice = $product->currentSellingPrice();

        $candidates = collect([$this->pricingResolver->resolveBestChoiceAt($product, $demand->requiredQty)])
            ->filter()
            ->map(fn (PurchaseLineDecision $d) => $this->withMargin($d, $sellingPrice));

        if ($product->isTarget()) {
            $candidates = $candidates->merge(
                $this->tierEscalationCandidates($product, $demand->requiredQty, $sellingPrice)
            );
        }

        if ($candidates->isEmpty()) {
            return null;
        }

        return $this->pickBest($candidates, $sellingPrice === null);
    }

    /**
     * 🎯 قلب إعادة التصميم: لو الهامش معروف (سعر بيع مسجَّل)، نختار
     * أعلى هامش صافٍ للوحدة. لو غير معروف للجميع، نتراجع لأرخص تكلفة
     * مع ملاحظة صريحة أن هذا اختيار احتياطي بانتظار تسجيل سعر البيع.
     */
    private function pickBest(Collection $candidates, bool $marginUnknownForAll): ?PurchaseLineDecision
    {
        if ($marginUnknownForAll) {
            $best = $candidates->sortBy('unitCost')->first();

            return $best ? $this->appendNote($best, '⚠️ لا يوجد سعر بيع مسجَّل لهذه المادة بعد — تم اختيار أرخص تكلفة احتياطياً بدل مقارنة الربح الفعلي. سجّل سعر البيع لتفعيل مقارنة الربحية الحقيقية.') : null;
        }

        return $candidates->sortByDesc('marginPerUnit')->first();
    }

    private function withMargin(PurchaseLineDecision $decision, ?float $sellingPrice): PurchaseLineDecision
    {
        $margin = $sellingPrice === null ? null : round($sellingPrice - $decision->unitCost, 4);

        return new PurchaseLineDecision(
            productId: $decision->productId,
            supplierProductId: $decision->supplierProductId,
            finalQty: $decision->finalQty,
            unitCost: $decision->unitCost,
            bonusQty: $decision->bonusQty,
            lineTotalCost: $decision->lineTotalCost,
            scenarioNote: $decision->scenarioNote,
            hasActiveOffer: $decision->hasActiveOffer,
            marginPerUnit: $margin,
        );
    }

    private function appendNote(PurchaseLineDecision $decision, string $extra): PurchaseLineDecision
    {
        $note = $decision->scenarioNote ? "{$decision->scenarioNote} {$extra}" : $extra;

        return new PurchaseLineDecision(
            productId: $decision->productId,
            supplierProductId: $decision->supplierProductId,
            finalQty: $decision->finalQty,
            unitCost: $decision->unitCost,
            bonusQty: $decision->bonusQty,
            lineTotalCost: $decision->lineTotalCost,
            scenarioNote: $note,
            hasActiveOffer: $decision->hasActiveOffer,
            marginPerUnit: $decision->marginPerUnit,
        );
    }

    /**
     * يفحص كل شرائح عروض كل الموردين المرتبطين بهذه المادة، ويُولّد
     * مرشحاً إضافياً لكل شريحة تتجاوز الاحتياج الحالي لكن تبقى ضمن سقف
     * الحماية المالية — لنقارنها لاحقاً بالخيار الأساسي حسب **الهامش**.
     */
    private function tierEscalationCandidates(Product $product, int $requiredQty, ?float $sellingPrice): Collection
    {
        $ceiling = $requiredQty * self::MAX_INFLATION_MULTIPLIER;
        $candidates = collect();

        foreach ($product->supplierProducts as $supplierProduct) {
            foreach ($supplierProduct->offers as $offer) {
                $isEligibleTier = $offer->min_quantity > $requiredQty
                    && $offer->min_quantity <= $ceiling
                    && $offer->isActiveNow()
                    && $offer->hasAnyBenefit();

                if (!$isEligibleTier) {
                    continue;
                }

                $decision = $this->pricingResolver->evaluateAt($supplierProduct, $offer->min_quantity);

                $withNote = new PurchaseLineDecision(
                    productId: $decision->productId,
                    supplierProductId: $decision->supplierProductId,
                    finalQty: $decision->finalQty,
                    unitCost: $decision->unitCost,
                    bonusQty: $decision->bonusQty,
                    lineTotalCost: $decision->lineTotalCost,
                    scenarioNote: "صنف تارجت — رُفعت الكمية إلى {$offer->min_quantity} لتفعيل شريحة أعلى ({$offer->label()})، بحثاً عن أعلى ربح صافٍ للوحدة.",
                    hasActiveOffer: true,
                );

                $candidates->push($this->withMargin($withNote, $sellingPrice));
            }
        }

        return $candidates;
    }
}
