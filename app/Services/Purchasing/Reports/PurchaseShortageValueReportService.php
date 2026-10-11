<?php
// app/Services/Purchasing/Reports/PurchaseShortageValueReportService.php
namespace App\Services\Purchasing\Reports;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;

/**
 * 🎯 "تقرير نواقص المشتريات" — الفرق (المطلوب − المستلَم) لكل سطر تجاوزت
 * فاتورته مرحلة الاستلام الفعلي، بصرف النظر عن كون الباقي أُلغي لاحقاً
 * أم لا (receivingShortfall، لا outstandingQuantity — الفرق موثَّق بتعليق
 * PurchaseOrderItem نفسه). هذا تقرير عن "ماذا لم يصل تاريخياً"، لا "ماذا
 * لا يزال متوقَّعاً" (ذاك دور OpenPurchaseOrderQuantityService).
 *
 * 🆕 التجميع حسب الشركة، وكل صف يحمل الآن أسماء المواد الناقصة نفسها
 * (لا عدّاً فقط) — تصحيح صريح منك.
 */
class PurchaseShortageValueReportService
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
            ->join('products', 'products.id', '=', 'purchase_order_items.product_id')
            ->whereIn('purchase_orders.status', self::RECEIVED_STATUSES)
            ->when($dateFrom, fn($q) => $q->whereRaw(PurchaseOrderItem::RECEIPT_DATE_SQL.' >= ?', [$dateFrom]))
            ->when($dateTo, fn($q) => $q->whereRaw(PurchaseOrderItem::RECEIPT_DATE_SQL.' <= ?', [$dateTo]))
            ->select(
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
                $row->shortfall_qty = max(0, $orderedOrPlanned - $row->received_qty);
                $row->shortfall_value = round($row->shortfall_qty * $row->unit_cost, 2);
                return $row;
            })
            ->filter(fn($row) => $row->shortfall_qty > 0)
            ->values();

        $grouped = $rows->groupBy('company')->map(function (Collection $items, string $company) {
            $products = $items->pluck('product_name', 'product_id')->unique();

            return (object) [
                'company'       => $company,
                'total_value'   => round($items->sum('shortfall_value'), 2),
                'total_qty'     => $items->sum('shortfall_qty'),
                'product_count' => $products->count(),
                'product_names' => $products->values()->all(), // 🆕
            ];
        })->sortByDesc('total_value')->values();

        $totals = (object) [
            'total_value'   => round($rows->sum('shortfall_value'), 2),
            'product_count' => $rows->pluck('product_id')->unique()->count(),
            'company_count' => $rows->pluck('company')->filter()->unique()->count(),
        ];

        return (object) ['rows' => $grouped, 'totals' => $totals];
    }
}
