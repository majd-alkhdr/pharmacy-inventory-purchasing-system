<?php

namespace App\Services\Purchasing;

use App\Models\Product;

/**
 * 🎯 هذا الكائن هو "المدخل" الكامل لمحرك حل تعارض الميزانية (قسم 5 من
 * مستندك). يجمع كل ما يحتاجه ذلك المحرك بمكان واحد: من هو المنتج، ماذا
 * قرر محرك الاحتياج، ماذا قرر محرك السيناريوهات، وأربع إشارات أولوية
 * صريحة (بدل أن يُعيد محرك الميزانية استنتاجها بنفسه من بيانات متفرقة).
 *
 * 🎓 لماذا "إشارات الأولوية" (isTargetItem، isCriticalShortage...) هنا
 * كخصائص جاهزة، بدل أن يحسبها BudgetAllocationService بنفسه لاحقاً؟
 * لأن مصدر كل إشارة مختلف تماماً (بعضها من product_purchase_settings،
 * بعضها من حساب الاحتياج، بعضها من market_shortage_flags) — لو تركنا
 * محرك الميزانية "يذهب ليجلبها بنفسه"، أصبح يعرف تفاصيل داخلية عن 3
 * جداول مختلفة لا علاقة له بها مباشرة (مخالفة لـ SRP). بدل ذلك، من
 * يُنشئ هذا الكائن (المنسّق الكبير لاحقاً) هو من يجمع الإشارات، ومحرك
 * الميزانية يكتفي بقراءتها كحقائق جاهزة.
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
    ) {
    }

    /**
     * 🎯 يطبّق ترتيب الأولوية الحرفي من قسم 5 بمستندك:
     * "أصناف التارجت ← النواقص الحرجة ← أصناف العروض الاستثنائية".
     * رقم أعلى = أولوية أعلى. "قنص نواقص السوق" ليس جزءاً من هذا الترتيب
     * إطلاقاً لأنه لا يخضع للميزانية أصلاً (يُعامَل بشكل منفصل تماماً
     * بـ BudgetAllocationService — راجع تعليقه).
     */
    public function priorityScore(): int
    {
        return match (true) {
            $this->isTargetItem      => 3,
            $this->isCriticalShortage => 2,
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
            $this->decision->hasActiveOffer => 'عرض استثنائي',
            default                          => 'عادي',
        };
    }
}
