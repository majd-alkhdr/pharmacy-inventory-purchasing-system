<?php
// app/Services/Inventory/Reports/TransfersValueReportService.php

namespace App\Services\Inventory\Reports;

use App\Models\CentralInventoryPrice;
use App\Models\InvoiceItem;
use App\Models\ReverseTransferItem;
use Illuminate\Support\Collection;

/**
 * 🎯 "تقرير قيمة المناقلات والمناقلات العكسية" — بخلاف تقرير المخزون
 * الحالي، هذا تقرير عن **أحداث لها تاريخ وقوع** (فاتورة صدرت يوم كذا،
 * مناقلة عكسية حدثت يوم كذا)، فالسعر المُعتمَد هنا هو السعر الذي كان
 * سارياً **وقت وقوع الحدث نفسه** — بقرار صريح من صاحب المشروع (راجع
 * ملاحظة CentralInventoryPrice::effectivePricesFor() حول لماذا يجب
 * تجميع الصفوف حسب التاريخ أولاً قبل استدعائها دفعة واحدة).
 */
class TransfersValueReportService
{
    /**
     * @return object{rows: Collection, totals: object}
     */
    public function build(string $dateFrom, string $dateTo, ?int $pharmacyId = null): object
    {
        $forwardRows = InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('products', 'products.id', '=', 'invoice_items.product_id')
            ->join('pharmacies', 'pharmacies.id', '=', 'invoices.pharmacy_id')
            ->whereBetween('invoices.date', [$dateFrom, $dateTo])
            ->when($pharmacyId, fn($q) => $q->where('invoices.pharmacy_id', $pharmacyId))
            ->select(
                'pharmacies.id as pharmacy_id', 'pharmacies.name as pharmacy_name',
                'products.id as product_id', 'products.name as product_name', 'products.company',
                'invoice_items.supplied_qty as quantity', 'invoices.date',
            )
            ->where('invoice_items.supplied_qty', '>', 0) // 🎯 سطر بلا شحن فعلي لا قيمة له بهذا التقرير
            ->get();

        $reverseRows = ReverseTransferItem::query()
            ->join('reverse_transfers', 'reverse_transfers.id', '=', 'reverse_transfer_items.reverse_transfer_id')
            ->join('products', 'products.id', '=', 'reverse_transfer_items.product_id')
            ->join('pharmacies', 'pharmacies.id', '=', 'reverse_transfers.pharmacy_id')
            ->whereBetween('reverse_transfers.date', [$dateFrom, $dateTo])
            ->when($pharmacyId, fn($q) => $q->where('reverse_transfers.pharmacy_id', $pharmacyId))
            // 🆕 (ب.6) قيمة ما أُعيد فعلاً = المنفَّذ فقط (راجع نفس الشرط بـ ProductMovementReportService).
            ->where('reverse_transfers.status', \App\Enums\ReverseTransferStatus::Executed->value)
            ->where(fn ($q) => $q->whereNull('reverse_transfer_items.execution_status')
                ->orWhere('reverse_transfer_items.execution_status', \App\Enums\TransferItemExecutionStatus::Executed->value))
            ->select(
                'pharmacies.id as pharmacy_id', 'pharmacies.name as pharmacy_name',
                'products.id as product_id', 'products.name as product_name', 'products.company',
                'reverse_transfer_items.quantity', 'reverse_transfers.date',
            )
            ->get();

        $forwardValued = $this->applyPointInTimePrices($forwardRows);
        $reverseValued = $this->applyPointInTimePrices($reverseRows);

        $pharmacyIds = $forwardValued->pluck('pharmacy_id')->merge($reverseValued->pluck('pharmacy_id'))->unique();

        $rows = $pharmacyIds->map(function ($pid) use ($forwardValued, $reverseValued) {
            $fRows = $forwardValued->where('pharmacy_id', $pid);
            $rRows = $reverseValued->where('pharmacy_id', $pid);
            $allRows = $fRows->concat($rRows);

            $products  = $allRows->pluck('product_name', 'product_id');
            $companies = $allRows->pluck('company')->filter()->unique()->sort()->values();
            $forwardValue = round($fRows->sum('value'), 2);
            $reverseValue = round($rRows->sum('value'), 2);

            return (object) [
                'pharmacy_id'    => $pid,
                'pharmacy_name' => ($fRows->first() ?? $rRows->first())->pharmacy_name,
                'forward_value' => $forwardValue,
                'reverse_value' => $reverseValue,
                'net_value'      => round($forwardValue - $reverseValue, 2), // 🎯 إضافة عملية: صافي ما تحرَّك فعلياً بالاتجاهين
                'product_count'  => $products->count(),
                'product_names' => $products->values()->all(),
                'company_count'  => $companies->count(),
                'company_names' => $companies->all(),
            ];
        })->sortByDesc('forward_value')->values();

        $totals = (object) [
            'forward_value' => round($rows->sum('forward_value'), 2),
            'reverse_value' => round($rows->sum('reverse_value'), 2),
            'net_value'      => round($rows->sum('net_value'), 2),
            'product_count'  => $forwardValued->pluck('product_id')->merge($reverseValued->pluck('product_id'))->unique()->count(),
            'company_count'  => $forwardValued->pluck('company')->merge($reverseValued->pluck('company'))->filter()->unique()->count(),
        ];

        return (object) ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * 🎯 يُجمِّع الصفوف حسب التاريخ أولاً، ثم يجلب أسعار كل مجموعة دفعة
     * واحدة بتاريخها الخاص — استعلام واحد لكل تاريخ مختلف بالنطاق
     * المُفلتَر، لا استعلام لكل صف (لا N+1، ولا استخدام خاطئ لتاريخ واحد
     * مشترك لكل الصفوف رغم اختلاف تواريخها الفعلية).
     */
    private function applyPointInTimePrices(Collection $rows): Collection
    {
        return $rows->groupBy(fn($r) => (string) $r->date)->flatMap(function (Collection $group, string $date) {
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
