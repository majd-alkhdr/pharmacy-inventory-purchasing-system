<?php
// app/Services/Purchasing/Reports/SupplierPurchaseSummaryReportService.php
namespace App\Services\Purchasing\Reports;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;

/**
 * 🎯 "تقرير الموردين" — لكل مورد: قيمة ما استُلم فعلياً + قيمة ما نقص من
 * طلبياته، بنفس تعريفَي "الاستلام" و"النقص" أعلاه حرفياً، مُجمَّعين حسب
 * المورد بدل السبب/الشركة.
 */
class SupplierPurchaseSummaryReportService
{
    private const RECEIVED_STATUSES = [
        PurchaseOrderStatus::PartiallyReceived->value,
        PurchaseOrderStatus::Received->value,
        PurchaseOrderStatus::PartiallyCancelled->value,
    ];

    public function build(?string $dateFrom = null, ?string $dateTo = null): object
    {
        $rows = PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->join('products', 'products.id', '=', 'purchase_order_items.product_id')
            ->whereIn('purchase_orders.status', self::RECEIVED_STATUSES)
            ->when($dateFrom, fn($q) => $q->whereRaw(PurchaseOrderItem::RECEIPT_DATE_SQL.' >= ?', [$dateFrom]))
            ->when($dateTo, fn($q) => $q->whereRaw(PurchaseOrderItem::RECEIPT_DATE_SQL.' <= ?', [$dateTo]))
            ->select(
                'suppliers.id as supplier_id',
                'suppliers.name as supplier_name',
                'purchase_order_items.ordered_qty',
                'purchase_order_items.suggested_qty',
                'purchase_order_items.approved_qty',
                'purchase_order_items.received_qty',
                'purchase_order_items.unit_cost',
                'products.id as product_id',
                'products.name as product_name',
                'products.company',
            )
            ->get()
            ->map(function ($row) {
                $orderedOrPlanned = $row->ordered_qty ?? ($row->approved_qty ?? $row->suggested_qty);
                $row->purchase_value  = round($row->received_qty * $row->unit_cost, 2);
                $row->shortfall_qty   = max(0, $orderedOrPlanned - $row->received_qty);
                $row->shortfall_value = round($row->shortfall_qty * $row->unit_cost, 2);
                return $row;
            });

        $grouped = $rows->groupBy('supplier_id')->map(function (Collection $items) {
            $products  = $items->pluck('product_name', 'product_id')->unique();
            $companies = $items->pluck('company')->filter()->unique()->sort()->values();

            return (object) [
                'supplier_id'          => $items->first()->supplier_id,
                'supplier_name'        => $items->first()->supplier_name,
                'total_purchase_value' => round($items->sum('purchase_value'), 2),
                'total_shortage_value' => round($items->sum('shortfall_value'), 2),
                'product_count'        => $products->count(),
                'product_names'        => $products->values()->all(),
                'company_count'        => $companies->count(),
                'company_names'        => $companies->all(),
            ];
        })->sortByDesc('total_purchase_value')->values();

        $totals = (object) [
            'total_purchase_value' => round($rows->sum('purchase_value'), 2),
            'total_shortage_value' => round($rows->sum('shortfall_value'), 2),
            'supplier_count'       => $rows->pluck('supplier_id')->unique()->count(),
        ];

        return (object) ['rows' => $grouped, 'totals' => $totals];
    }
}
