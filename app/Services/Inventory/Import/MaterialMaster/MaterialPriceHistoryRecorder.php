<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\MaterialMaster;

use App\Models\CentralInventoryPrice;
use App\Models\ProductPriceChange;

/**
 * 🎯 المسؤولية الوحيدة: قبل أن يكتب ملف الأصناف السعر الجديد، يقارنه بـ"سعر المادة" المخزَّن
 * حالياً (آخر سطر central_inventory_prices) ويسجّل سطر تغيّر (قديم ← جديد) **فقط عند الاختلاف**.
 *
 * - لا سعر مخزَّن سابقاً (مادة تُسعَّر لأول مرة) ⇒ لا سطر: لا يوجد "قديم" يُقارَن به، وهذا ليس تغيّراً.
 * - نفس السعر ⇒ لا سطر.
 * - المقارنة على خانتين عشريتين — نفس دقة التخزين (decimal 10,2) بالجدولين.
 *
 * ⚠️ يجب استدعاؤه **قبل** CentralInventoryPriceRecorderService::recordChanges() بنفس المعاملة،
 * وإلا سيقرأ السعر الجديد نفسه كـ"مخزَّن" ولن يرى أي تغيير.
 */
final class MaterialPriceHistoryRecorder
{
    private const INSERT_CHUNK = 500;

    /**
     * @param  array<int, float>  $pricesByProductId  product_id ← السعر كما ورد بالملف
     * @return int عدد أسطر التغيّر المسجَّلة
     */
    public function recordChanges(array $pricesByProductId): int
    {
        if ($pricesByProductId === []) {
            return 0;
        }

        $stored = CentralInventoryPrice::latestForMany(array_keys($pricesByProductId));
        $now = now();
        $rows = [];

        foreach ($pricesByProductId as $productId => $newPrice) {
            $oldPrice = $stored[$productId] ?? null;

            if ($oldPrice === null || round($oldPrice, 2) === round((float) $newPrice, 2)) {
                continue;
            }

            $rows[] = [
                'product_id'  => $productId,
                'old_price'   => round($oldPrice, 2),
                'new_price'   => round((float) $newPrice, 2),
                'recorded_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            ProductPriceChange::insert($chunk);
        }

        return count($rows);
    }
}
