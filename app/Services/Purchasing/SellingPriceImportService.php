<?php

namespace App\Services\Purchasing;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SellingPriceImportService
{
    /**
     * @param Collection<int, array{barcode:string, price:float}> $rows
     * @return array{matched: int, unmatched: Collection}
     */
    public function process(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return ['matched' => 0, 'unmatched' => collect()];
        }

        $now = now();
        $effectiveFrom = $now->toDateString(); // 🎯 تاريخ الرفع نفسه، كما تحدَّد صراحة

        $barcodes = $rows->pluck('barcode')->unique()->values();
        $productIdsByBarcode = Product::whereIn('barcode', $barcodes)->pluck('id', 'barcode');

        $matched = 0;
        $unmatched = collect();
        $insertRows = [];

        foreach ($rows as $row) {
            $productId = $productIdsByBarcode[$row['barcode']] ?? null;

            if (!$productId) {
                $unmatched->push("{$row['barcode']} — سعر {$row['price']}");
                continue;
            }

            $insertRows[] = [
                'product_id'      => $productId,
                'price'            => $row['price'],
                'effective_from'  => $effectiveFrom,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
            $matched++;
        }

        foreach (array_chunk($insertRows, 500) as $chunk) {
            DB::table('product_selling_prices')->insert($chunk);
        }

        return ['matched' => $matched, 'unmatched' => $unmatched->unique()->values()];
    }
}
