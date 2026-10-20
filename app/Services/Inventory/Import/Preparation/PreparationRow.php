<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Preparation;

/** 🎯 (ب.3) صف واحد صالح من ملف التحضير — بعد التحليل والتطبيع. */
final class PreparationRow
{
    public function __construct(
        public readonly int $sourceRow,
        public readonly string $name,
        public readonly int $preparedQty,
    ) {
    }
}
