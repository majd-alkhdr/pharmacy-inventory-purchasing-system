<?php
// app/Services/Inventory/Reports/InventoryValueReportService.php

namespace App\Services\Inventory\Reports;

use App\Models\CentralInventory;
use App\Models\CentralInventoryPrice;
use App\Models\PharmacyInventory;
use Illuminate\Support\Collection;

/**
 * 🎯 "تقرير قيمة المخزون" — راجع تقرير-تعليمي-معمارية-نظام-المناقلات.md
 * لفهم لماذا خدمات التقارير تعيش بمجلد Reports/ منفصل عن باقي خدمات
 * Services/Inventory: "احسب قيمة مالية للعرض" سبب مختلف تماماً للتغيير
 * عن "احسب فجوة ترميم واشحنها فعلياً" — نفس منطق SRP المُطبَّق بكل مكان
 * آخر بهذا المشروع، لا استثناءً له.
 *
 * 🎯 يستخدم السعر **الحالي** فقط (لا نقطة زمنية محدَّدة) — بقرار منطقي
 * بحت: هذا التقرير يصف حالة *الآن* (كم يوجد فعلياً هذه اللحظة)، لا حدثاً
 * تاريخياً له تاريخ وقوع مثل فاتورة أو مناقلة عكسية. راجع
 * TransfersValueReportService وShortageValueReportService للمقارنة —
 * هناك السعر وقت الحدث الفعلي هو المطلوب، لا الحالي.
 */
class InventoryValueReportService
{
    /**
     * @return object{rows: Collection, totals: object}
     */
    public function build(): object
    {
        $pharmacyRows = PharmacyInventory::query()
            ->join('products', 'products.id', '=', 'pharmacy_inventories.product_id')
            ->join('pharmacies', 'pharmacies.id', '=', 'pharmacy_inventories.pharmacy_id')
            ->where('pharmacy_inventories.quantity', '>', 0)
            ->select(
                'pharmacies.id as pharmacy_id',
                'pharmacies.name as pharmacy_name',
                'products.id as product_id',
                'products.name as product_name',
                'products.company',
                'pharmacy_inventories.quantity',
            )
            ->get();

        $centralRows = CentralInventory::query()
            ->join('products', 'products.id', '=', 'central_inventories.product_id')
            ->where('central_inventories.quantity', '>', 0)
            ->select(
                'products.id as product_id',
                'products.name as product_name',
                'products.company',
                'central_inventories.quantity',
            )
            ->get();

        // ⚡ استعلام واحد لكل الأسعار — لا استعلام منفصل لكل صيدلية/منتج.
        // effectivePricesFor() بلا تاريخ = اليوم = "الحالي" فعلياً.
        $allProductIds = $pharmacyRows->pluck('product_id')
            ->merge($centralRows->pluck('product_id'))
            ->unique()->values()->all();
        $prices = CentralInventoryPrice::effectivePricesFor($allProductIds);

        $rows = collect();

        foreach ($pharmacyRows->groupBy('pharmacy_id') as $items) {
            $rows->push($this->summarize($items->first()->pharmacy_name, $items->first()->pharmacy_id, false, $items, $prices));
        }

        if ($centralRows->isNotEmpty()) {
            $rows->push($this->summarize('المستودع المركزي', null, true, $centralRows, $prices));
        }

        $rows = $rows->sortByDesc('total_value')->values();

        // 🎯 إجماليات نظامية حقيقية — ليست مجموع أعمدة الجدول (منتج واحد
        // بعدة صيدليات كان سيُحتسَب أكثر من مرة لو جمعنا الأعمدة مباشرة).
        $allProductIdsDistinct = $pharmacyRows->pluck('product_id')->merge($centralRows->pluck('product_id'))->unique();
        $allCompaniesDistinct = $pharmacyRows->pluck('company')->merge($centralRows->pluck('company'))->filter()->unique();

        $totals = (object) [
            'total_value'    => round($rows->sum('total_value'), 2), // القيمة نفسها تجميعية بلا خطر ازدواج (كل وحدة بمكان واحد فقط)
            'locations_count' => $rows->count(),
            'product_count'   => $allProductIdsDistinct->count(),
            'company_count'   => $allCompaniesDistinct->count(),
        ];

        return (object) ['rows' => $rows, 'totals' => $totals];
    }

    private function summarize(string $label, ?int $pharmacyId, bool $isCentral, Collection $items, array $prices): object
    {
        $totalValue = $items->sum(fn($row) => $row->quantity * ($prices[$row->product_id] ?? 0));
        $products   = $items->pluck('product_name', 'product_id');
        $companies  = $items->pluck('company')->filter()->unique()->sort()->values();

        return (object) [
            'label'          => $label,
            'pharmacy_id'    => $pharmacyId,
            'is_central'     => $isCentral,
            'total_value'    => round($totalValue, 2),
            'product_count'   => $products->count(),
            'product_names'  => $products->values()->all(),
            'company_count'   => $companies->count(),
            'company_names'  => $companies->all(),
        ];
    }
}
