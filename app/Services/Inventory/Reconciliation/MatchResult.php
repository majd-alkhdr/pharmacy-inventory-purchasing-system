<?php

declare(strict_types=1);

namespace App\Services\Inventory\Reconciliation;

/** 🎯 (ب.3) ما قرره PreparationNameMatcher لاسم واحد: النوع + المادة (حين تكون محددة بلا غموض). */
final class MatchResult
{
    public function __construct(
        public readonly NameMatchKind $kind,
        public readonly ?int $productId = null,
    ) {
    }
}
