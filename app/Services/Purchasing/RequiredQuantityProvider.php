<?php
// app/Services/Purchasing/RequiredQuantityProvider.php
namespace App\Services\Purchasing;

use App\Models\Product;
use Illuminate\Support\Collection;

interface RequiredQuantityProvider
{
    /**
     * @param Collection<int, Product> $products
     * @return array<int, RequiredQuantityResult> [product_id => النتيجة]
     */
    public function bulkCalculate(Collection $products): array;
}
