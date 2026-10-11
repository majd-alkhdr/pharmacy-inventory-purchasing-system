<?php
// app/Services/Purchasing/RequiredQuantityResult.php
namespace App\Services\Purchasing;

final class RequiredQuantityResult
{
    public function __construct(
        public readonly int $productId,
        public readonly int $requiredQty,
        public readonly string $explanationSummary,
        public readonly array $explanationFactors = [],
    ) {}

    public function hasRequirement(): bool
    {
        return $this->requiredQty > 0;
    }
}
