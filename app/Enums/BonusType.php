<?php

namespace App\Enums;

enum BonusType: string
{
    case FreeQuantity     = 'free_quantity';
    case PercentDiscount  = 'percent_discount';

    public function label(): string
    {
        return match ($this) {
            self::FreeQuantity    => 'كمية مجانية',
            self::PercentDiscount => 'نسبة خصم %',
        };
    }

    /**
     * 🎯 حساب "التكلفة الصافية الفعلية" لوحدة واحدة بعد تطبيق العرض —
     * هذا بالضبط ما وصفه مستندك بـ"توجيه الربحية نحو التكلفة الصافية
     * الأقل". نقطة حقيقة واحدة لهذا الحساب، تُستخدم لاحقاً من محرك
     * السيناريوهات دون تكرار المنطق بكل مكان يقارن فيه عروضاً مع بعضها.
     */
    public function effectiveUnitCost(float $listUnitCost, float $bonusValue, int $purchasedQty): float
    {
        return match ($this) {
            self::PercentDiscount => $listUnitCost * (1 - ($bonusValue / 100)),
            self::FreeQuantity    => $purchasedQty > 0
                ? ($listUnitCost * $purchasedQty) / ($purchasedQty + $bonusValue)
                : $listUnitCost,
        };
    }
}
