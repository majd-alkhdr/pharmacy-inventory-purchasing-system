<?php

declare(strict_types=1);

namespace App\Services\Inventory\ProductMasterData;

use App\Enums\ProductMasterField;

/** 🎯 سطر واحد بشاشة بيانات المادة: الحقل، قيمته المخزَّنة الآن، ومن كتبها آخراً (null = قبل بدء التتبع). */
final class ProductFieldSnapshot
{
    public function __construct(
        public readonly ProductMasterField $field,
        public readonly string|int|float|null $value,
        public readonly ?FieldSourceInfo $source,
    ) {
    }
}
