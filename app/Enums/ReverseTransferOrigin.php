<?php

namespace App\Enums;

/**
 * 🎯 من أين نشأت المناقلة العكسية — للعرض والتدقيق فقط. الكيان ومسار التنفيذ واحد
 * بالحالتين (قرار ج.3 الصريح: "لا نوع فاتورة موازٍ").
 */
enum ReverseTransferOrigin: string
{
    case Restocking = 'restocking';
    case Purchasing = 'purchasing';

    public function label(): string
    {
        return match ($this) {
            self::Restocking => 'محرك الترميم',
            self::Purchasing => 'زر «مناقلات؟» بالمشتريات',
        };
    }
}
