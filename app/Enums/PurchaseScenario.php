<?php

namespace App\Enums;

/**
 * 🎯 يمثّل "محرك السيناريوهات" — بعد إعادة هيكلة المشتريات (2026-08)، لم
 * يعد المشرف يختار سيناريو إطلاقاً؛ سيناريو واحد فقط (PriorityBased)
 * يُطبَّق دائماً تلقائياً. القيم الثلاث القديمة (RestockingOnly,
 * MaxProfitability, Balanced) **تبقى موجودة بلا حذف** (بيانات تاريخية
 * لفواتير قديمة أُنشئت بها، ولتفادي كسر أي Enum Cast على صفوف قديمة
 * بجدول purchase_orders)، لكنها لم تعد تُستخدم لتوليد فواتير جديدة.
 */
enum PurchaseScenario: string
{
    case RestockingOnly    = 'restocking_only';
    case MaxProfitability  = 'max_profitability';
    case Balanced           = 'balanced';

    /** 🆕 السيناريو الوحيد الفعّال الآن — أولوية تارجت > نقص حرج > دوران عالٍ > عادي */
    case PriorityBased      = 'priority_based';

    public function label(): string
    {
        return match ($this) {
            self::RestockingOnly   => 'ترميم المخزون فقط (أولوية السرعة) — قديم',
            self::MaxProfitability => 'الربحية الأعلى (استغلال العروض) — قديم',
            self::Balanced          => 'الشامل المتوازن — قديم',
            self::PriorityBased     => 'الأولوية حسب الدوران والحاجة (الافتراضي الحالي)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RestockingOnly   => 'يغطي النواقص الصفرية وما هبط تحت الحد الأدنى فقط، بغض النظر عن العروض.',
            self::MaxProfitability => 'يوجّه الطلبات نحو أفضل بونص/عروض، ويرفع كميات أصناف التارجت لتحقيق شرائح خصم أعلى.',
            self::Balanced          => 'يوازن بين توفير النواقص بسرعة واستغلال العروض المتاحة.',
            self::PriorityBased     => 'يرتّب الأصناف: تارجت ← نقص حرج ← دوران عالٍ ← عادي، ويستبعد الأصناف الراكدة تلقائياً.',
        };
    }
}
