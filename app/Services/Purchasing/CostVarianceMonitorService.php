<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\SupplierProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 يطبّق قسم 7 من مستندك: *"مراقبة آلية لتغيرات أسعار الموردين أو
 * إلغاء العروض. إظهار تنبيهات مرئية للمشرف في حال ارتفعت التكلفة الصافية
 * لأي مادة عن آخر توريد لها."*
 *
 * 🩹 قرار أداء مُتعمَّد (بعد درس إصلاح مشكلة الـ Timeout مباشرة):
 * بدل حلقة `foreach ($supplierProducts as $sp) { Query::latestFor($sp) }`
 * (استعلام منفصل لكل رابطة مورد-مادة — N+1 كلاسيكي)، نجلب "آخر تكلفة
 * فعلية مدفوعة لكل رابطة" **باستعلام SQL واحد فقط** عبر subquery يحدّد
 * أعلى ID (= الأحدث) لكل supplier_product_id ضمن الفواتير المُستلَمة.
 *
 * لا حاجة لجدول "تاريخ أسعار" منفصل (Cost History) — البيانات موجودة
 * أصلاً بـ purchase_order_items، والاستعلام أدناه يستخرجها مباشرة.
 */
class CostVarianceMonitorService
{
    /**
     * @return Collection<int, object{supplier_product: SupplierProduct, last_paid_cost: float, current_cost: float, variance_amount: float, variance_percent: float}>
     */
    public function detectVariances(): Collection
    {
        $lastPaidCosts = $this->fetchLastPaidCosts();

        if ($lastPaidCosts->isEmpty()) {
            return collect();
        }

        return SupplierProduct::with(['product', 'supplier'])
            ->whereIn('id', $lastPaidCosts->keys())
            ->get()
            ->map(function (SupplierProduct $sp) use ($lastPaidCosts) {
                $lastPaid = (float) $lastPaidCosts[$sp->id];
                $current  = (float) $sp->unit_cost;

                return (object) [
                    'supplier_product' => $sp,
                    'last_paid_cost'     => $lastPaid,
                    'current_cost'        => $current,
                    'variance_amount'    => round($current - $lastPaid, 2),
                    'variance_percent'   => $lastPaid > 0 ? round((($current - $lastPaid) / $lastPaid) * 100, 1) : 0.0,
                ];
            })
            // 🎯 نُظهر فقط الارتفاع (تآكل هامش الربح) — انخفاض السعر خبر جيد، لا يحتاج تنبيهاً
            ->filter(fn ($row) => $row->current_cost > $row->last_paid_cost)
            ->sortByDesc('variance_percent')
            ->values();
    }

    /**
     * استعلام واحد فقط: [supplier_product_id => آخر سعر فعلي مدفوع]
     */
    private function fetchLastPaidCosts(): Collection
    {
        $latestItemIds = DB::table('purchase_order_items as poi2')
            ->join('purchase_orders as po2', 'po2.id', '=', 'poi2.purchase_order_id')
            ->whereIn('po2.status', [
                PurchaseOrderStatus::Received->value,
                PurchaseOrderStatus::PartiallyReceived->value,
            ])
            ->whereNotNull('poi2.supplier_product_id')
            ->groupBy('poi2.supplier_product_id')
            ->selectRaw('MAX(poi2.id) as max_id');

        return DB::table('purchase_order_items as poi')
            ->joinSub($latestItemIds, 'latest', fn ($join) => $join->on('latest.max_id', '=', 'poi.id'))
            ->pluck('poi.unit_cost', 'poi.supplier_product_id');
    }
}
