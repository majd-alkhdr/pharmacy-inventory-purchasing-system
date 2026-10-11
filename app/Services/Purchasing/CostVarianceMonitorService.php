<?php

namespace App\Services\Purchasing;

use App\Models\CentralInventoryPrice;
use App\Models\Product;
use App\Models\ProductPriceChange;
use Illuminate\Support\Collection;

/**
 * 🎯 تنبيه بصري عند أي تغيّر بـ"سعر المادة" عن قيمته السابقة — ارتفاعاً أو انخفاضاً.
 *
 * 🆕 المصدر: سجل تغيّرات ملف الأصناف (product_price_changes) — لا product_purchase_prices
 * (مفهوم "آخر سعر شراء" من ملف جرد المشتريات أُلغي بالكامل). لكل مادة لها تغيّر واحد على الأقل:
 *
 *   السعر الحالي  = "سعر المادة" المخزَّن الآن (آخر سطر central_inventory_prices)
 *   السعر السابق = old_price بآخر سطر تغيّر لهذه المادة (= القيمة قبل آخر تغيير مباشرة)
 *
 * الاتجاهان يُعرضان بنفس الحساب (الفرق = الحالي − السابق، النسبة = الفرق ÷ السابق × 100)؛ الإشارة
 * تحدد الاتجاه. الترتيب حسب حجم التغيّر المطلق، فأكبر تذبذب أولاً أياً كان اتجاهه.
 * ثلاثة استعلامات ثابتة مهما كان عدد المواد (لا N+1).
 */
class CostVarianceMonitorService
{
    /**
     * @return Collection<int, object{product: Product, previous_price: float, current_price: float, variance_amount: float, variance_percent: float}>
     */
    public function detectVariances(): Collection
    {
        $latestChangeIds = ProductPriceChange::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('product_id')
            ->pluck('id');

        if ($latestChangeIds->isEmpty()) {
            return collect();
        }

        $previousByProduct = ProductPriceChange::whereIn('id', $latestChangeIds)
            ->pluck('old_price', 'product_id')
            ->map(fn ($price) => (float) $price);

        $currentByProduct = CentralInventoryPrice::latestForMany($previousByProduct->keys()->all());
        $products = Product::whereIn('id', $previousByProduct->keys())->get()->keyBy('id');

        return $previousByProduct
            ->map(function (float $previous, int $productId) use ($currentByProduct, $products) {
                $current = $currentByProduct[$productId] ?? null;

                // لا سعر حالي، أو سابق صفر (نسبة غير معرَّفة)، أو لا تغيّر فعلي (عاد السعر لقيمته) ⇒ لا تنبيه.
                if ($current === null || $previous <= 0 || round($current, 2) === round($previous, 2) || !$products->has($productId)) {
                    return null;
                }

                return (object) [
                    'product'          => $products->get($productId),
                    'previous_price'   => $previous,
                    'current_price'    => $current,
                    'variance_amount'  => round($current - $previous, 2),
                    'variance_percent' => round((($current - $previous) / $previous) * 100, 1),
                ];
            })
            ->filter()
            ->sortByDesc(fn (object $row) => abs($row->variance_percent))
            ->values();
    }
}
