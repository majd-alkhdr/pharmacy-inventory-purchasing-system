<?php

namespace App\Services\Purchasing;

use App\Models\ProductPurchasePrice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 يطبّق نفس هدف النسخة السابقة (تنبيه بصري عند ارتفاع تكلفة مادة عن
 * آخر توريد لها)، لكن بمصدر بيانات مختلف تماماً بعد إعادة هيكلة المشتريات:
 *
 * ⚠️ التغيير الجوهري: النسخة القديمة كانت تقارن purchase_order_items.unit_cost
 * (المرتبط باستلام فعلي فعلي) — لكن بعد قرار "لا أسعار على مستوى السطر
 * بعد الآن" (unitCost = 0 دائماً بمحرك القرار الجديد)، هذا المصدر لم يعد
 * يحمل أي قيمة مفيدة. المصدر الجديد الموافَق عليه صراحة من صاحب المشروع:
 * "آخر سعر شراء" الذي يُضاف الآن كعمود بملف جرد المشتريات نفسه، ويُخزَّن
 * تاريخياً بجدول product_purchase_prices (راجع مايجريشن 2026_08_01_000003).
 *
 * الاستعلام أصبح أبسط بكثير من النسخة القديمة (كانت تحتاج subquery معقّد
 * عبر purchase_order_items+purchase_orders) لأن المصدر أصبح مباشراً.
 */
class CostVarianceMonitorService
{
    /**
     * @return Collection<int, object{product: \App\Models\Product, previous_price: float, current_price: float, variance_amount: float, variance_percent: float}>
     */
    public function detectVariances(): Collection
    {
        // 🎯 كل منتج له سطران على الأقل بجدول أسعار الشراء التاريخي —
        // استعلام واحد يجلب أحدث سعرين لكل منتج معاً (بدل حلقة N+1).
        $productIds = ProductPurchasePrice::query()
            ->select('product_id')
            ->groupBy('product_id')
            ->havingRaw('COUNT(*) >= 2')
            ->pluck('product_id');

        if ($productIds->isEmpty()) {
            return collect();
        }

        return $productIds->map(function (int $productId) {
            $current = ProductPurchasePrice::latestFor($productId);
            $previous = ProductPurchasePrice::previousFor($productId);

            if ($current === null || $previous === null || $current <= $previous) {
                return null; // 🎯 نُظهر فقط الارتفاع — انخفاض السعر خبر جيد، لا يحتاج تنبيهاً
            }

            $product = \App\Models\Product::find($productId);

            return (object) [
                'product'          => $product,
                'previous_price'   => $previous,
                'current_price'     => $current,
                'variance_amount'  => round($current - $previous, 2),
                'variance_percent' => round((($current - $previous) / $previous) * 100, 1),
            ];
        })
        ->filter()
        ->sortByDesc('variance_percent')
        ->values();
    }
}
