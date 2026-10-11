<?php

declare(strict_types=1);

namespace App\Services\Inventory;

/**
 * 🎯 نتيجة تقييم زوج (صيدلية+مادة) واحد مقابل مخزونه القياسي — **قبل** أي
 * اعتبار لتوفّر المخزون المركزي (ذاك دور ParLevelAllocation المنفصل). لا
 * يعرف شيئاً عن صيدلية أو منتج بعينه، فقط أرقام مجرَّدة — يمكن اختباره
 * بملايين المجموعات بلا لمس قاعدة بيانات.
 */
final class ParLevelDecision
{
    public function __construct(
        /** المخزون القياسي − الرصيد الحالي، بعد طرح ما شحنته استراتيجية أخرى بنفس الدفعة (raw، بعد التصالح فقط لا التقريب). */
        public readonly int $rawGap,
        /** rawGap بعد تقريبه لأسفل لأقرب عبوة كاملة. صفر = لا حاجة فعلية قابلة للتنفيذ الآن. */
        public readonly int $effectiveGap,
        /** الرصيد الحالي − المخزون القياسي إن كان موجباً؛ من الأرقام الخام دائماً (راجع تحذير الكلاس). */
        public readonly int $physicalExcess,
        /** physicalExcess بعد تقريبه لأسفل لأقرب عبوة كاملة. صفر = لا مناقلة عكسية مستحقة. */
        public readonly int $effectiveExcess,
        /** الرصيد عند المخزون القياسي أو فوقه (بعد التصالح) — يُغلق أي نقص معلَّق سابق بصرف النظر عن الفائض. */
        public readonly bool $isAtOrAboveStandard,
        /** فجوة حقيقية موجودة لكنها ضمن حد التسامح (min_reorder_gap) — تُتجاهَل كلياً، لا شحن ولا لمس نقص قديم. */
        public readonly bool $gapBelowThreshold,
    ) {
    }

    public function needsRestock(): bool
    {
        return $this->effectiveGap > 0;
    }

    public function needsReverseTransfer(): bool
    {
        return $this->effectiveExcess > 0;
    }
}
