<?php

declare(strict_types=1);

namespace App\Services\Inventory\Surplus;

/**
 * 🎯 فائض زوج (صيدلية، مادة) واحد كما يراه كل المستهلكين الثلاثة بنفس اللحظة.
 *
 * physicalExcess: الفائض الفعّال من اللقطة (بعد تسامح min_reorder_gap وتقريب العبوة —
 *                 بالضبط ParLevelDecision::effectiveExcess، نفس رقم المناقلة العكسية).
 * committed:      مجموع ما التُزم به منه بالدورة الحالية.
 * available:      ما يجوز اقتراحه/تنفيذه الآن = max(0, physical − committed).
 */
final class SurplusEntry
{
    public function __construct(
        public readonly int $pharmacyId,
        public readonly int $productId,
        public readonly int $standardQuantity,
        public readonly int $currentStock,
        public readonly int $physicalExcess,
        public readonly int $committed,
    ) {
    }

    public function available(): int
    {
        return max(0, $this->physicalExcess - $this->committed);
    }

    public function key(): string
    {
        return SurplusSnapshot::key($this->pharmacyId, $this->productId);
    }
}
