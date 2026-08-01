<?php

namespace App\Services\Purchasing;

use App\Models\Product;

/**
 * 🎯 "المدخل" الكامل لمحرك حل تعارض الميزانية — راجع الشرح الأصلي المفصَّل
 * أعلى الملف بالنسخة السابقة (لا يزال صحيحاً بالكامل).
 *
 * 🆕 isHighTurnover: إشارة أولوية رابعة أُضيفت بإعادة هيكلة المشتريات —
 * مصدرها StockTurnoverClassifier (دوران < شهرين). راجع priorityScore()
 * أدناه للترتيب الجديد الكامل.
 */
final class PurchaseLineCandidate
{
    public function __construct(
        public readonly Product $product,
        public readonly ProductDemandCalculation $demand,
        public readonly PurchaseLineDecision $decision,
        public readonly bool $isTargetItem,
        public readonly bool $isCriticalShortage,
        public readonly bool $isMarketShortage,
        public readonly bool $isHighTurnover = false,
    ) {
    }

    /**
     * 🎯 يطبّق ترتيب الأولوية النهائي المتفَق عليه صراحة مع صاحب المشروع:
     * تارجت (4) > نقص حرج (3) > دوران عالٍ (2) > عرض نشط (1) > عادي (0).
     *
     * ⚠️ "عرض نشط" (1) محجوز بالترتيب لكنه ميت عملياً حالياً — لا عروض
     * تُفعَّل بمحرك القرار الجديد (hasActiveOffer دائماً false من
     * PriorityBasedPurchaseDecisionService) — جاهز فقط لعودة نظام العروض
     * لاحقاً بلا حاجة لإعادة تصميم الترتيب حينها.
     *
     * "قنص نواقص السوق" ليس جزءاً من هذا الترتيب إطلاقاً (كما كان أصلاً)
     * لأنه لا يخضع للميزانية أصلاً — يُعامَل بمعزل تام بـ BudgetAllocationService.
     */
    public function priorityScore(): int
    {
        return match (true) {
            $this->isTargetItem      => 4,
            $this->isCriticalShortage => 3,
            $this->isHighTurnover     => 2,
            $this->decision->hasActiveOffer => 1,
            default                    => 0,
        };
    }

    public function priorityLabel(): string
    {
        return match (true) {
            $this->isMarketShortage         => 'قنص نواقص سوق (استثناء ميزانية)',
            $this->isTargetItem             => 'صنف تارجت',
            $this->isCriticalShortage       => 'نقص حرج (رصيد صفري)',
            $this->isHighTurnover            => 'دوران عالٍ',
            $this->decision->hasActiveOffer => 'عرض استثنائي',
            default                          => 'عادي',
        };
    }
}
