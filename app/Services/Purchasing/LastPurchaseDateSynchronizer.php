<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use Illuminate\Support\Facades\DB;

/**
 * 🎯 المسؤولية الوحيدة: إعادة اشتقاق products.last_purchase_date من سجلات فواتير الشراء المنفَّذة —
 * تاريخ آخر استلام فعلي (received_qty > 0) لهذه المادة بأي فاتورة شراء.
 *
 * 🎓 لماذا عمود مُشتَق يُحدَّث عند الاستلام بدل حسابه عند كل قراءة؟ قارئه الرئيسي
 * (TurnoverCalculator → Product::isNewlyPurchased) يعمل داخل حلقة على مئات المواد أثناء التوليد؛
 * استعلام فرعي لكل مادة هناك = N+1. التحديث يحدث فقط عند الاستلام (نادر نسبياً)، ولمواد ذلك
 * الاستلام فقط، والقيمة تُحسب دائماً من البيانات لا من "اليوم" — فإعادة الاستدعاء آمنة ومتطابقة.
 */
final class LastPurchaseDateSynchronizer
{
    /**
     * @param  iterable<int>  $productIds
     */
    public function syncFor(iterable $productIds): void
    {
        $ids = collect($productIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('products')->whereIn('id', $ids)->update([
            'last_purchase_date' => DB::raw(
                '(SELECT DATE(MAX(poi.received_at)) FROM purchase_order_items poi'
                .' WHERE poi.product_id = products.id AND poi.received_qty > 0 AND poi.received_at IS NOT NULL)'
            ),
        ]);
    }
}
