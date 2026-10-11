<?php
// app/Services/Inventory/Reports/ShortageValueReportService.php

namespace App\Services\Inventory\Reports;

use App\Models\CentralInventoryPrice;
use App\Models\Shortage;
use Illuminate\Support\Collection;

/**
 * 🎯 "تقرير قيمة النواقص" — يُقيِّم النواقص **المعلَّقة حالياً فقط**
 * (pending) — النواقص المُغلَقة (fulfilled) لم تعد نقصاً قائماً، بل سجلاً
 * تاريخياً منتهياً، فلا معنى لتقييمها ضمن "قيمة النقص الحالي".
 *
 * 🎯 السعر يُعتمَد وقت آخر تحديث فعلي لكمية النقص (updated_at) — نفس
 * قرار "وقت الحدث الفعلي" المُطبَّق بتقرير المناقلات، بنفس السبب: نقص
 * سُجِّل قبل شهرين بسعر مختلف عن اليوم يجب أن يعكس قيمته وقتها، لا سعر
 * اليوم الذي قد يكون تغيّر منذ ذلك الحين.
 */
class ShortageValueReportService
{
    /**
     * @return object{rows: Collection, totals: object}
     */
    public function build(?string $dateFrom = null, ?string $dateTo = null, ?int $pharmacyId = null): object
    {
        $shortageRows = Shortage::pending()
            ->join('products', 'products.id', '=', 'shortages.product_id')
            ->join('pharmacies', 'pharmacies.id', '=', 'shortages.pharmacy_id')
            ->when($dateFrom, fn($q) => $q->whereDate('shortages.updated_at', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->whereDate('shortages.updated_at', '<=', $dateTo))
            ->when($pharmacyId, fn($q) => $q->where('shortages.pharmacy_id', $pharmacyId))
            ->select(
                'pharmacies.id as pharmacy_id', 'pharmacies.name as pharmacy_name',
                'products.id as product_id', 'products.name as product_name', 'products.company',
                'shortages.quantity', 'shortages.updated_at',
            )
            ->get();

        $valued = $this->applyPointInTimePrices($shortageRows);

        $rows = $valued->groupBy('pharmacy_id')->map(function (Collection $items) {
            $products  = $items->pluck('product_name', 'product_id');
            $companies = $items->pluck('company')->filter()->unique()->sort()->values();

            return (object) [
                'pharmacy_id'   => $items->first()->pharmacy_id,
                'pharmacy_name' => $items->first()->pharmacy_name,
                'total_value'   => round($items->sum('value'), 2),
                'product_count' => $products->count(),
                'product_names' => $products->values()->all(),
                'company_count' => $companies->count(),
                'company_names' => $companies->all(),
            ];
        })->sortByDesc('total_value')->values();

        $totals = (object) [
            'total_value'   => round($rows->sum('total_value'), 2),
            'product_count' => $valued->pluck('product_id')->unique()->count(),
            'company_count' => $valued->pluck('company')->filter()->unique()->count(),
        ];

        return (object) ['rows' => $rows, 'totals' => $totals];
    }

    /** 🎯 نفس تقنية التجميع حسب التاريخ المُستخدَمة بـ TransfersValueReportService بالضبط */
    private function applyPointInTimePrices(Collection $rows): Collection
    {
        return $rows->groupBy(fn($r) => \Illuminate\Support\Carbon::parse($r->updated_at)->toDateString())
            ->flatMap(function (Collection $group, string $date) {
                $productIds = $group->pluck('product_id')->unique()->values()->all();
                $prices = CentralInventoryPrice::effectivePricesFor($productIds, $date);

                return $group->map(function ($row) use ($prices) {
                    $unitPrice = $prices[$row->product_id] ?? 0;
                    $row->value = round($row->quantity * $unitPrice, 2);
                    return $row;
                });
            })->values();
    }
}
