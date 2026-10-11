<?php

namespace App\Enums;

/**
 * مصدر نشوء سجل النقص (Shortage) — أي محرك ترميم أنشأه؟
 *
 * 🎯 التمييز ضروري لأن "معنى" التحديث يختلف جذرياً حسب المصدر:
 * - Sales: القيمة تُجمَّع تراكمياً مع أي نقص معلَّق سابق لنفس الزوج
 *   (راجع ShortageRecorderService::recordMany()) — تاريخ آخر ظهور يُعتمد.
 * - StandardInventory: القيمة تُستبدل بالكامل بكل قراءة جرد جديدة
 *   (راجع ParLevelReplenishmentService::replenish()) — قراءة ميدانية
 *   جديدة تُلغي القديمة، لا تُضاف إليها.
 *
 * هذا الفرق بالسلوك هو بالضبط سبب عدم توحيد الاثنين بكلاس "مُسجِّل نقص"
 * واحد بفرع if/else داخلي — كل مصدر له كلاس Strategy مستقل (راجع
 * RestockingStrategy)، وهذا الـ Enum فقط "يُصنِّف" لا "يُقرِّر السلوك".
 */
enum ShortageSource: string
{
    case Sales = 'sales';
    case StandardInventory = 'standard_inventory';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'نقص مبيعات 🛒',
            self::StandardInventory => 'نقص مخزون قياسي 📊',
        };
    }
}
