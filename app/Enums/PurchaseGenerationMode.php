<?php
// app/Enums/PurchaseGenerationMode.php
namespace App\Enums;

enum PurchaseGenerationMode: string
{
    case Shortages     = 'shortages';
    case StandardStock = 'standard_stock';
    case Both          = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Shortages     => 'حسب النواقص المسجَّلة بالمناقلات',
            self::StandardStock => 'حسب تحقيق المخزون القياسي للمستودع',
            self::Both           => 'الأكبر بين النواقص والمخزون القياسي',
        };
    }

    public function includesShortages(): bool
    {
        return $this !== self::StandardStock;
    }
    public function includesStandardStock(): bool
    {
        return $this !== self::Shortages;
    }
}
