<?php

declare(strict_types=1);

namespace App\Services\Inventory;

/**
 * 🎯 القلب الحسابي لعملية "الحفاظ على المخزون القياسي" — **مُستخرَج حرفياً**
 * من ParLevelReplenishmentService (كان جزءاً من حلقة واحدة طويلة داخلها) إلى
 * كلاس نقي مستقل. لا يعرف شيئاً عن Eloquent، قاعدة البيانات، الفواتير، أو
 * حتى معنى "صيدلية" — يستقبل أرقاماً مجرَّدة (رصيد، مخزون قياسي، تسامح،
 * حجم عبوة) ويُعيد قراراً. هذا بالضبط ما يجعله قابلاً للاختبار بعشرات حالات
 * الحافة (Edge Cases) دون فتح اتصال قاعدة بيانات واحد.
 *
 * 🎓 لماذا استخراجه الآن تحديداً، لا إبقاؤه داخل الحلقة كما كان؟ الوضع
 * الرابع القادم ("مبيعات تفتح الباب، والكمية = فجوة المخزون القياسي لا
 * كمية البيع") يحتاج **نفس هذا الحساب بالضبط** — الفرق الوحيد بينه وبين
 * الوضع الحالي هو **أي الأزواج تُقيَّم** (كل زوج له مخزون قياسي بصيدليات
 * معيَّنة، مقابل: فقط الأزواج التي باعت شيئاً هذه الدفعة تحديداً)، لا
 * **كيف تُقيَّم**. لولا هذا الاستخراج، كان الخياران الوحيدان: نسخ نفس
 * الرياضيات حرفياً بكلاس ثانٍ (انحراف مضمون لاحقاً — أي تعديل على القاعدة
 * يُنسى بأحد النسختين)، أو استدعاء ParLevelReplenishmentService::replenish()
 * نفسها بحيلة (لكنها تُقيِّم *كل* أزواج الصيدلية، لا مجموعة فرعية محدَّدة
 * بدقة) — كلا الخيارين سيئ. كلاس واحد، مصدر حقيقة واحد، يستخدمه الاثنان.
 *
 * ⚠️ نقطتان يجب الحفاظ عليهما حرفياً عند أي تعديل مستقبلي (كلتاهما قرار
 * صاحب المشروع الصريح، موثَّقتان أصلاً بتعليق ParLevelReplenishmentService):
 *  1. الفائض (physicalExcess) يُحسَب من الأرقام **الخام** دائماً — لا يتأثر
 *     بـ $alreadyShipped (تصالح "الأكبر لا الجمع" مع استراتيجية أخرى)، لأنه
 *     حقيقة مادية بحتة لا علاقة لها بما شحنته استراتيجية أخرى بنفس الدفعة.
 *  2. min_reorder_gap نطاق تسامح **متماثل** حول الهدف (يُطبَّق على الفجوة
 *     والفائض معاً بنفس القيمة)، لا سقفاً أحادي الاتجاه.
 */
final class ParLevelCalculator
{
    /**
     * يقيّم زوجاً واحداً مقابل مخزونه القياسي — **بلا أي معرفة بتوفّر
     * المخزون المركزي**؛ فقط "هل توجد حاجة/فائض حقيقيان، وبأي مقدار بعد
     * تقريب العبوة؟". استدعِ allocate() بعدها فقط إن كانت النتيجة needsRestock().
     *
     * @param int $alreadyShipped ما شحنته استراتيجية أخرى (مثلاً المبيعات ضمن
     *        الهجين) لنفس الزوج بنفس الدفعة بالفعل — يُطرَح من الفجوة الخام
     *        قبل أي تقييم (تصالح "الأكبر لا الجمع"؛ 0 بلا تأثير).
     */
    public function evaluate(
        int $standardQuantity,
        int $currentStock,
        int $minReorderGap,
        int $packSize,
        int $alreadyShipped = 0,
    ): ParLevelDecision {
        $rawGapBeforeReconciliation = $standardQuantity - $currentStock;

        // حقيقة مادية بحتة — لا تتأثر بما شحنته استراتيجية أخرى (راجع تحذير الكلاس، النقطة 1).
        $physicalExcess = $currentStock - $standardQuantity;

        $rawGap = max(0, $rawGapBeforeReconciliation - $alreadyShipped);

        if ($rawGap <= 0) {
            $effectiveExcess = $physicalExcess > $minReorderGap
                ? $this->roundDownToPack($physicalExcess, $packSize)
                : 0;

            return new ParLevelDecision(
                rawGap: $rawGap,
                effectiveGap: 0,
                physicalExcess: $physicalExcess,
                effectiveExcess: $effectiveExcess,
                isAtOrAboveStandard: true,
                gapBelowThreshold: false,
            );
        }

        if ($rawGap <= $minReorderGap) {
            return new ParLevelDecision(
                rawGap: $rawGap,
                effectiveGap: 0,
                physicalExcess: $physicalExcess,
                effectiveExcess: 0,
                isAtOrAboveStandard: false,
                gapBelowThreshold: true,
            );
        }

        return new ParLevelDecision(
            rawGap: $rawGap,
            effectiveGap: $this->roundDownToPack($rawGap, $packSize),
            physicalExcess: $physicalExcess,
            effectiveExcess: 0,
            isAtOrAboveStandard: false,
            gapBelowThreshold: false,
        );
    }

    /**
     * كم من effectiveGap يمكن شحنه الآن بمعرفة الرصيد المركزي المتاح فعلياً
     * هذه اللحظة؟ إدارة المورد المشترك (نفس المنتج قد تحتاجه عدة صيدليات
     * بنفس الدفعة) مسؤولية المستدعي — هذه الدالة نقية بمعزل عن أي حالة.
     */
    public function allocate(int $effectiveGap, int $availableCentral, int $packSize): ParLevelAllocation
    {
        $rawSuppliable = min($effectiveGap, max(0, $availableCentral));
        $suppliedNow = $this->roundDownToPack($rawSuppliable, $packSize);

        return new ParLevelAllocation(
            suppliedNow: $suppliedNow,
            remainingGap: $effectiveGap - $suppliedNow,
        );
    }

    /** 0 يعني "بلا قيد تعبئة" — القيمة تبقى كما هي بلا تقريب (راجع توثيق pack_size الأصلي). */
    private function roundDownToPack(int $value, int $packSize): int
    {
        return $packSize > 0 ? intdiv($value, $packSize) * $packSize : $value;
    }
}
