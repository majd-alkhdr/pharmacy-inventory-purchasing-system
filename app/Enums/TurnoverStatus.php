<?php

namespace App\Enums;

/**
 * 🎯 يمثّل تصنيف دوران المخزون لمادة معيّنة — العتبات محسومة صراحة من
 * قرار المستخدم أثناء تصميم إعادة هيكلة المشتريات:
 *   - دوران > شهرين  → راكد (Stagnant) → يُستبعد بالكامل من ترشيحات الشراء
 *   - دوران < شهرين  → دوران عالٍ (HighTurnover) → أولوية شراء مرتفعة
 *   - غير ذلك        → عادي (Normal)
 *   - لا بيانات كافية بعد (منتج جديد الشراء بلا تاريخ مبيعات) → TooNewToJudge
 *     (لا يُستبعد، ولا يُعطى أولوية "دوران عالٍ" أيضاً — محايد تماماً)
 */
enum TurnoverStatus: string
{
    case Stagnant       = 'stagnant';
    case Normal          = 'normal';
    case HighTurnover    = 'high_turnover';
    case TooNewToJudge  = 'too_new_to_judge';

    public function label(): string
    {
        return match ($this) {
            self::Stagnant      => 'راكد 🐌',
            self::Normal         => 'عادي',
            self::HighTurnover   => 'دوران عالٍ 🚀',
            self::TooNewToJudge => 'جديد — لا بيانات كافية بعد',
        };
    }

    public function isExcludedFromPurchasing(): bool
    {
        return $this === self::Stagnant;
    }

    public function grantsHighTurnoverPriority(): bool
    {
        return $this === self::HighTurnover;
    }
}
