<?php

namespace App\Services\Purchasing;

/**
 * 🎯 نفس فلسفة ProductDemandCalculation بالضبط (DTO صريح بدل مصفوفة).
 * هذا الكائن هو "لغة مشتركة" بين الثلاثة سيناريوهات القادمة — كل واحد
 * منها ينتج نفس الشكل بالضبط، بغض النظر عن اختلاف منطقه الداخلي. هذا
 * ما يسمح للمنسّق القادم (Orchestrator) بالتعامل مع أي سيناريو بنفس
 * الطريقة دون معرفة تفاصيله (Polymorphism عبر عقد موحّد).
 */
final class PurchaseLineDecision
{
    public function __construct(
        public readonly int $productId,
        public readonly ?int $supplierProductId,
        public readonly int $finalQty,
        public readonly float $unitCost,
        public readonly int $bonusQty,
        public readonly float $lineTotalCost,
        public readonly ?string $scenarioNote = null,
        // 🎯 أُضيف بمرحلة محرك الميزانية: يُستخدم مباشرة لمعرفة "هل هذا
        // السطر مؤهَّل لأولوية 'أصناف العروض الاستثنائية'؟" دون الحاجة
        // لتخمين ذلك من نص scenarioNote الحر (هش وعرضة للخطأ).
        public readonly bool $hasActiveOffer = false,
    ) {
    }
}
