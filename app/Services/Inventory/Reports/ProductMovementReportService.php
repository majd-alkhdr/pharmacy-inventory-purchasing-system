<?php
// app/Services/Inventory/Reports/ProductMovementReportService.php

namespace App\Services\Inventory\Reports;

use App\Enums\PharmacyTransferStatus;
use App\Enums\TransferItemExecutionStatus;
use App\Models\InvoiceItem;
use App\Models\PharmacyTransferItem;
use App\Models\ReverseTransfer;
use App\Models\ReverseTransferItem;
use App\Models\WatchedProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 "تقرير مراقبة حركة مواد محدَّدة" — يعرض كل حركة لأصناف مُختارة صراحة
 * بشاشة "أصناف للمراقبة" فقط، لا كل النظام.
 *
 * 🎓 لماذا استعلام حي (Live Query) على الجداول الموجودة أصلاً، لا جدول
 * "سجل حركة" جديد يُكتَب إليه عند كل عملية؟ لأن كل حركة تهمنا هنا **مسجَّلة
 * بالفعل** بمكان واحد صحيح ووحيد: شحن فعلي = سطر InvoiceItem، مناقلة
 * عكسية = سطر ReverseTransferItem، بين صيدليتين = سطر PharmacyTransferItem منفَّذ. نسخ هذه البيانات لجدول جديد يعني
 * مصدرَي حقيقة لنفس الواقعة، مع خطر انحرافهما عن بعضهما بمرور الوقت —
 * بلا أي فائدة حقيقية مقابل هذا الخطر، بما أن الاستعلام الحي بسيط وسريع
 * (نطاق محدود: أصناف مراقَبة فقط، لا النظام كله).
 *
 * ⚠️ حركة "المشتريات" (من المورد إلى المستودع المركزي) غير مشمولة هنا
 * عمداً — بقرار صريح، لأن أي مصدر لهذه البيانات ينتمي لقسم المشتريات،
 * المرشَّح لإعادة هيكلة كاملة لاحقاً. ستُضاف لاحقاً بعد تلك الإعادة، لا
 * الآن، تفادياً لبناء اعتماد على بنية مرشَّحة للتغيير الجذري.
 */
class ProductMovementReportService
{
    /** قائمة الأصناف المراقَبة حالياً — لعنصر الاختيار بالواجهة */
    public function watchedProductsList(): Collection
    {
        return WatchedProduct::with('product:id,name,company,barcode')
            ->get()
            ->map(fn($w) => $w->product)
            ->filter()
            ->sortBy('name')
            ->values();
    }

    /**
     * @param int|null $productId مادة واحدة محدَّدة، أو null لعرض كل الأصناف المراقَبة معاً
     * @return Collection كل عنصر: (object) {date, product_name, movement_type, from_label, to_label, quantity}
     */
    public function build(?int $productId = null): Collection
    {
        $watchedIds = WatchedProduct::pluck('product_id');
        if ($watchedIds->isEmpty()) {
            return collect();
        }

        $targetIds = $productId ? [$productId] : $watchedIds->all();

        // 🎯 حركة صادرة من المستودع المركزي إلى صيدلية — أي شحن فعلي
        // (سواء مصدره مبيعات أو مخزون قياسي، لا فرق هنا؛ التقرير يهتم
        // بـ"أين تحرَّكت المادة"، لا بأي استراتيجية حرَّكتها)
        $forward = InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('pharmacies', 'pharmacies.id', '=', 'invoices.pharmacy_id')
            ->join('products', 'products.id', '=', 'invoice_items.product_id')
            ->whereIn('invoice_items.product_id', $targetIds)
            ->where('invoice_items.supplied_qty', '>', 0) // 🎯 سطر بلا شحن فعلي ليس حركة
            ->select(
                'invoices.date',
                'products.name as product_name',
                'pharmacies.name as pharmacy_name',
                'invoice_items.supplied_qty as quantity',
            )
            ->get()
            ->map(fn($row) => (object) [
                'date'          => $row->date,
                'product_name' => $row->product_name,
                'movement_type' => 'forward',
                'from_label'    => 'المستودع المركزي',
                'to_label'      => $row->pharmacy_name,
                'quantity'      => (float) $row->quantity,
            ]);

        // 🎯 حركة عكسية من صيدلية إلى المستودع المركزي
        $reverse = ReverseTransferItem::query()
            ->join('reverse_transfers', 'reverse_transfers.id', '=', 'reverse_transfer_items.reverse_transfer_id')
            ->join('pharmacies', 'pharmacies.id', '=', 'reverse_transfers.pharmacy_id')
            ->join('products', 'products.id', '=', 'reverse_transfer_items.product_id')
            ->whereIn('reverse_transfer_items.product_id', $targetIds)
            // 🆕 (ب.6) العكسية صارت "مقترحة" حتى تُنفَّذ — الحركة الفعلية = المنفَّذ فقط، وبنوده غير
            // الملغاة تلقائياً (NULL = سجلات ما قبل سير التنفيذ، طُبِّق أثرها وقتها).
            ->where('reverse_transfers.status', \App\Enums\ReverseTransferStatus::Executed->value)
            ->executedLines()
            ->select(
                DB::raw(ReverseTransfer::MOVEMENT_DATE_SQL.' as date'), // 🩹 يوم التنفيذ، لا يوم الاقتراح
                'products.name as product_name',
                'pharmacies.name as pharmacy_name',
                'reverse_transfer_items.quantity',
            )
            ->get()
            ->map(fn($row) => (object) [
                'date'          => $row->date,
                'product_name' => $row->product_name,
                'movement_type' => 'reverse',
                'from_label'    => $row->pharmacy_name,
                'to_label'      => 'المستودع المركزي',
                'quantity'      => (float) $row->quantity,
            ]);

        // 🆕 حركة مباشرة بين صيدليتين — صارت حركة فعلية مع زر "تنفيذ" (ب.7)، فتُحسب هنا كبقية الحركات.
        // المنفَّذ فقط، وبنوده التي تحرّكت فعلاً (لا "superseded"). التاريخ = يوم التنفيذ.
        $interPharmacy = PharmacyTransferItem::query()
            ->join('pharmacy_transfers', 'pharmacy_transfers.id', '=', 'pharmacy_transfer_items.pharmacy_transfer_id')
            ->join('pharmacies as from_ph', 'from_ph.id', '=', 'pharmacy_transfers.from_pharmacy_id')
            ->join('pharmacies as to_ph', 'to_ph.id', '=', 'pharmacy_transfers.to_pharmacy_id')
            ->join('products', 'products.id', '=', 'pharmacy_transfer_items.product_id')
            ->whereIn('pharmacy_transfer_items.product_id', $targetIds)
            ->where('pharmacy_transfers.status', PharmacyTransferStatus::Completed->value)
            ->where(fn ($q) => $q->whereNull('pharmacy_transfer_items.execution_status')
                ->orWhere('pharmacy_transfer_items.execution_status', TransferItemExecutionStatus::Executed->value))
            ->select(
                DB::raw('COALESCE(pharmacy_transfers.executed_date, DATE(pharmacy_transfers.updated_at)) as date'),
                'products.name as product_name',
                'from_ph.name as from_name',
                'to_ph.name as to_name',
                'pharmacy_transfer_items.quantity',
            )
            ->get()
            ->map(fn($row) => (object) [
                'date'          => $row->date,
                'product_name'  => $row->product_name,
                'movement_type' => 'inter_pharmacy',
                'from_label'    => $row->from_name,
                'to_label'      => $row->to_name,
                'quantity'      => (float) $row->quantity,
            ]);

        return $forward->concat($reverse)->concat($interPharmacy)->sortByDesc('date')->values();
    }
}
