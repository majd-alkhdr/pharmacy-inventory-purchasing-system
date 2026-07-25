<?php

namespace App\Services\Purchasing;

/**
 * 🎓 لماذا DTO (كائن Readonly بسيط) وليس مجرد مصفوفة (array) عادية؟
 * ------------------------------------------------------------------
 * لو أرجعت DemandCalculationService مصفوفة عادية `['required_qty' => ...]`،
 * أي خطأ إملائي بمفتاح المصفوفة (`require_qty` بدل `required_qty` مثلاً)
 * لن يكتشفه PHP إلا وقت التشغيل — بالضبط نفس مشكلة "Magic Strings" التي
 * حلّلناها بتقرير [012] بالمرحلة الأولى، لكن هذه المرة على مستوى بنية
 * البيانات نفسها بدل قيمة عمود واحد. بصنف Readonly صريح، أي استخدام
 * خاطئ لاسم خاصية يُكتشف فوراً بواسطة الـ IDE و PHP نفسه.
 *
 * هذا الكائن هو **بالضبط** ما يتحوّل لاحقاً لعمودي purchase_order_items
 * (`explanation_summary` و`explanation_factors`) المصمَّمين خصيصاً لهذا
 * الغرض بالمرحلة السابقة — راجع الملف التعليمي الثاني.
 */
final class ProductDemandCalculation
{
    public function __construct(
        public readonly int $productId,
        public readonly float $dailyConsumptionRate,
        public readonly int $forecastPeriodDays,
        public readonly int $forecastNeedQty,
        public readonly int $safetyStockQty,
        public readonly bool $usedManualMinimum,
        public readonly int $currentUsableStockQty,
        public readonly int $openPurchaseOrderQty,
        public readonly int $requiredQty,
    ) {
    }

    /** 🎯 يُخزَّن مباشرة بعمود purchase_order_items.explanation_factors (JSON) */
    public function toExplanationFactors(): array
    {
        return [
            'daily_consumption_rate'   => $this->dailyConsumptionRate,
            'forecast_period_days'     => $this->forecastPeriodDays,
            'forecast_need_qty'        => $this->forecastNeedQty,
            'safety_stock_qty'         => $this->safetyStockQty,
            'used_manual_minimum'      => $this->usedManualMinimum,
            'current_usable_stock_qty' => $this->currentUsableStockQty,
            'open_purchase_order_qty'  => $this->openPurchaseOrderQty,
            'required_qty'             => $this->requiredQty,
        ];
    }

    /** 🎯 يُخزَّن مباشرة بعمود purchase_order_items.explanation_summary (نص جاهز للعرض) */
    public function toExplanationSummary(): string
    {
        $minimumSource = $this->usedManualMinimum
            ? 'حد أدنى محدَّد يدوياً من المشرف'
            : 'حد أدنى محسوب تلقائياً (مخزون أمان)';

        return sprintf(
            'الاحتياج المتوقّع لـ%d يوم: %d وحدة (بمعدل استهلاك %.2f وحدة/يوم). %s: %d وحدة. '
                . 'الرصيد الحالي القابل للبيع: %d وحدة، والكميات قيد التوريد فعلاً: %d وحدة. '
                . 'الكمية المطلوبة نهائياً لهذه الفاتورة: %d وحدة.',
            $this->forecastPeriodDays,
            $this->forecastNeedQty,
            $this->dailyConsumptionRate,
            $minimumSource,
            $this->safetyStockQty,
            $this->currentUsableStockQty,
            $this->openPurchaseOrderQty,
            $this->requiredQty,
        );
    }

    /** لا يوجد تاريخ استهلاك كافٍ لهذه المادة بعد (منتج جديد كلياً بلا فواتير سابقة) */
    public function hasNoHistoricalData(): bool
    {
        return $this->dailyConsumptionRate <= 0.0;
    }

    public function hasRequirement(): bool
    {
        return $this->requiredQty > 0;
    }
}
