<?php
// app/Services/Inventory/Reports/TransfersValueReportService.php

namespace App\Services\Inventory\Reports;

use App\Enums\PharmacyTransferStatus;
use App\Enums\TransferItemExecutionStatus;
use App\Models\CentralInventoryPrice;
use App\Models\InvoiceItem;
use App\Models\PharmacyTransferItem;
use App\Models\ReverseTransfer;
use App\Models\ReverseTransferItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * 🎯 "تقرير قيمة المناقلات والمناقلات العكسية" — بخلاف تقرير المخزون
 * الحالي، هذا تقرير عن **أحداث لها تاريخ وقوع** (فاتورة صدرت يوم كذا،
 * مناقلة عكسية حدثت يوم كذا)، فالسعر المُعتمَد هنا هو السعر الذي كان
 * سارياً **وقت وقوع الحدث نفسه** — بقرار صريح من صاحب المشروع (راجع
 * ملاحظة CentralInventoryPrice::effectivePricesFor() حول لماذا يجب
 * تجميع الصفوف حسب التاريخ أولاً قبل استدعائها دفعة واحدة).
 *
 * 🆕 (2026-10) أربعة اتجاهات لكل صيدلية:
 *   إليها:  من المستودع (فواتير) + من صيدليات أخرى (مناقلات مباشرة منفَّذة)
 *   منها:   إلى المستودع (عكسية منفَّذة) + إلى صيدليات أخرى (مناقلات مباشرة منفَّذة)
 *   الصافي = إليها − منها.
 * كل بند مناقلة مباشرة يُحسب مرتين — "وارد" للمستقبِلة و"صادر" للمرسِلة — فإجمالي الوارد بين
 * الصيدليات يساوي إجمالي الصادر بلا فلتر صيدلية، ويتلاشى أثرهما على صافي النظام كله (نقل داخلي).
 *
 * 🆕 مواد بلا "سعر مادة" سارٍ بتاريخ حدثها تُحسب صفراً — تُعدّ وتُعرض صراحة (unpriced_product_count).
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
            // 🩹 تاريخ الحركة = يوم التنفيذ لا يوم الاقتراح — يحدد الفترة **وسعر ذلك اليوم** معاً.
            ->whereRaw(ReverseTransfer::MOVEMENT_DATE_SQL.' BETWEEN ? AND ?', [$dateFrom, $dateTo])
            ->when($pharmacyId, fn($q) => $q->where('reverse_transfers.pharmacy_id', $pharmacyId))
            // 🆕 (ب.6) قيمة ما أُعيد فعلاً = المنفَّذ فقط (راجع نفس الشرط بـ ProductMovementReportService).
            ->where('reverse_transfers.status', \App\Enums\ReverseTransferStatus::Executed->value)
            ->executedLines()
            ->select(
                'pharmacies.id as pharmacy_id', 'pharmacies.name as pharmacy_name',
                'products.id as product_id', 'products.name as product_name', 'products.company',
                'reverse_transfer_items.quantity', DB::raw(ReverseTransfer::MOVEMENT_DATE_SQL.' as date'),
            )
            ->get();

        [$interInRows, $interOutRows] = $this->interPharmacyLegs($dateFrom, $dateTo, $pharmacyId);

        $forwardValued = $this->applyPointInTimePrices($forwardRows);
        $reverseValued = $this->applyPointInTimePrices($reverseRows);
        $interInValued = $this->applyPointInTimePrices($interInRows);
        $interOutValued = $this->applyPointInTimePrices($interOutRows);
        $allValued = $forwardValued->concat($reverseValued)->concat($interInValued)->concat($interOutValued);

        $rows = $allValued->groupBy('pharmacy_id')->map(function (Collection $allRows, $pid) use ($forwardValued, $reverseValued, $interInValued, $interOutValued) {
            $products  = $allRows->pluck('product_name', 'product_id');
            $companies = $allRows->pluck('company')->filter()->unique()->sort()->values();
            $sumFor = fn (Collection $set) => round($set->where('pharmacy_id', $pid)->sum('value'), 2);

            $forwardValue = $sumFor($forwardValued);
            $reverseValue = $sumFor($reverseValued);
            $interInValue = $sumFor($interInValued);
            $interOutValue = $sumFor($interOutValued);

            return (object) [
                'pharmacy_id'     => $pid,
                'pharmacy_name'   => $allRows->first()->pharmacy_name,
                'forward_value'   => $forwardValue,
                'inter_in_value'  => $interInValue,
                'reverse_value'   => $reverseValue,
                'inter_out_value' => $interOutValue,
                'net_value'       => round($forwardValue + $interInValue - $reverseValue - $interOutValue, 2),
                'product_count'   => $products->count(),
                'product_names'   => $products->values()->all(),
                'company_count'   => $companies->count(),
                'company_names'   => $companies->all(),
            ];
        })->sortByDesc('forward_value')->values();

        $totals = (object) [
            'forward_value'          => round($rows->sum('forward_value'), 2),
            'inter_in_value'         => round($rows->sum('inter_in_value'), 2),
            'reverse_value'          => round($rows->sum('reverse_value'), 2),
            'inter_out_value'        => round($rows->sum('inter_out_value'), 2),
            'net_value'              => round($rows->sum('net_value'), 2),
            'product_count'          => $allValued->pluck('product_id')->unique()->count(),
            'company_count'          => $allValued->pluck('company')->filter()->unique()->count(),
            'unpriced_product_count' => $allValued->where('unpriced', true)->pluck('product_id')->unique()->count(),
        ];

        return (object) ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * بنود المناقلات المباشرة المنفَّذة فعلاً بالفترة (لا "superseded")، كساقين: وارد للمستقبِلة، صادر
     * للمرسِلة. التاريخ = يوم التنفيذ (executed_date؛ السجلات القديمة بلا قيمة ⇒ يوم آخر تحديث).
     * فلتر الصيدلية يُبقي ساقها هي فقط — لا يظهر الطرف الآخر كسطر بتقرير صيدلية محددة.
     *
     * @return array{0: Collection, 1: Collection}
     */
    private function interPharmacyLegs(string $dateFrom, string $dateTo, ?int $pharmacyId): array
    {
        $dateSql = 'COALESCE(pharmacy_transfers.executed_date, DATE(pharmacy_transfers.updated_at))';

        $lines = PharmacyTransferItem::query()
            ->join('pharmacy_transfers', 'pharmacy_transfers.id', '=', 'pharmacy_transfer_items.pharmacy_transfer_id')
            ->join('pharmacies as from_ph', 'from_ph.id', '=', 'pharmacy_transfers.from_pharmacy_id')
            ->join('pharmacies as to_ph', 'to_ph.id', '=', 'pharmacy_transfers.to_pharmacy_id')
            ->join('products', 'products.id', '=', 'pharmacy_transfer_items.product_id')
            ->where('pharmacy_transfers.status', PharmacyTransferStatus::Completed->value)
            ->where(fn ($q) => $q->whereNull('pharmacy_transfer_items.execution_status')
                ->orWhere('pharmacy_transfer_items.execution_status', TransferItemExecutionStatus::Executed->value))
            ->whereRaw("{$dateSql} BETWEEN ? AND ?", [$dateFrom, $dateTo])
            ->when($pharmacyId, fn ($q) => $q->where(fn ($qq) => $qq->where('pharmacy_transfers.from_pharmacy_id', $pharmacyId)
                ->orWhere('pharmacy_transfers.to_pharmacy_id', $pharmacyId)))
            ->select(
                'pharmacy_transfers.from_pharmacy_id', 'from_ph.name as from_name',
                'pharmacy_transfers.to_pharmacy_id', 'to_ph.name as to_name',
                'products.id as product_id', 'products.name as product_name', 'products.company',
                'pharmacy_transfer_items.quantity', DB::raw("{$dateSql} as date"),
            )
            ->get();

        $leg = fn ($line, int $pid, string $name) => (object) [
            'pharmacy_id' => $pid, 'pharmacy_name' => $name,
            'product_id' => $line->product_id, 'product_name' => $line->product_name, 'company' => $line->company,
            'quantity' => $line->quantity, 'date' => $line->date,
        ];

        $in = $lines->map(fn ($l) => $leg($l, (int) $l->to_pharmacy_id, $l->to_name))
            ->when($pharmacyId, fn (Collection $c) => $c->where('pharmacy_id', $pharmacyId))->values();
        $out = $lines->map(fn ($l) => $leg($l, (int) $l->from_pharmacy_id, $l->from_name))
            ->when($pharmacyId, fn (Collection $c) => $c->where('pharmacy_id', $pharmacyId))->values();

        return [$in, $out];
    }

    /**
     * 🎯 يُجمِّع الصفوف حسب التاريخ أولاً، ثم يجلب أسعار كل مجموعة دفعة
     * واحدة بتاريخها الخاص — استعلام واحد لكل تاريخ مختلف بالنطاق
     * المُفلتَر، لا استعلام لكل صف (لا N+1، ولا استخدام خاطئ لتاريخ واحد
     * مشترك لكل الصفوف رغم اختلاف تواريخها الفعلية).
     */
    private function applyPointInTimePrices(Collection $rows): Collection
    {
        return $rows->groupBy(fn($r) => substr((string) $r->date, 0, 10))->flatMap(function (Collection $group, string $date) {
            $productIds = $group->pluck('product_id')->unique()->values()->all();
            $prices = CentralInventoryPrice::effectivePricesFor($productIds, $date);

            return $group->map(function ($row) use ($prices) {
                $row->unpriced = !isset($prices[$row->product_id]);
                $row->value = round($row->quantity * ($prices[$row->product_id] ?? 0), 2);
                return $row;
            });
        })->values();
    }
}
