<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Enums\BranchStockState;

/**
 * 🎯 (ج.3) سطر فرع واحد بلوحة "الفروع": رصيده وقياسيه وحاله من مادة واحدة.
 * null = "لا نعرف" (لا صف مرفوع) — لا يُفترض صفراً أبداً.
 */
final class BranchStockRow
{
    public function __construct(
        public readonly int $pharmacyId,
        public readonly string $name,
        public readonly ?int $currentStock,
        public readonly ?int $standard,
        public readonly ?int $difference,
        public readonly BranchStockState $state,
        public readonly int $available,
        public readonly int $committed,
    ) {
    }
}
