<?php

namespace App\Enums;

/**
 * 🎯 (ج.3) حال مادة واحدة بفرع واحد — كما تُعرض بلوحة "الفروع" بتفاصيل فاتورة الشراء.
 * ترتيب الحالات هنا هو ترتيب العرض (راجع BranchStockLookup::sortRows).
 *
 * ⚠️ surplus/surplus_reserved تُحدَّدان **حصراً** من SurplusEntry (الفائض الفعّال بعد تسامح
 * min_reorder_gap وتقريب العبوة)، لا من مقارنة الرصيد بالقياسي: رصيد يزيد قليلاً ضمن
 * التسامح = Ok لا فائض. نفس نمط ShortageSource: Enum يُصنِّف فقط، لا يقرر سلوكاً.
 */
enum BranchStockState: string
{
    case Surplus = 'surplus';
    case SurplusReserved = 'surplus_reserved';
    case Ok = 'ok';
    case Below = 'below';
    case Unknown = 'unknown';
    case NoStandard = 'no_standard';

    public function label(): string
    {
        return match ($this) {
            self::Surplus => 'فائض متاح',
            self::SurplusReserved => 'فائض محجوز بمناقلة',
            self::Ok => 'ضمن القياسي',
            self::Below => 'دون القياسي',
            self::Unknown => 'لا رصيد مرفوع',
            self::NoStandard => 'بلا مخزون قياسي',
        };
    }

    /** موضع الحالة بترتيب العرض (الأصغر أولاً) — نفس ترتيب تعريف الحالات. */
    public function sortOrder(): int
    {
        return array_search($this, self::cases(), true);
    }
}
