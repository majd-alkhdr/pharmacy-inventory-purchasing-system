<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 🎯 (ج.1) كيف يُحدَّد نطاق الموردين لتوليد فواتير الشراء المقترحة — المفتاح الذي يختار به
 * SupplierScopeStrategyResolver الاستراتيجية (نفس نمط RestockingMode ← RestockingStrategyResolver).
 */
enum SupplierScopeMode: string
{
    case DirectSuppliers = 'direct';
    case ByCompany = 'company';

    public function label(): string
    {
        return match ($this) {
            self::DirectSuppliers => 'تحديد مباشر بالمورد',
            self::ByCompany => 'اختيار شركة ← الموردون الذين يحملونها',
        };
    }
}
