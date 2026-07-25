<?php

namespace App\Services\Purchasing;

use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 يُغذّي جدول supplier_products (وينشئ الموردين الجدد تلقائياً) من
 * ملف مشتريات تاريخي — يطبّق قسم 1 من مستندك: "مشتريات سنة كاملة (لمعرفة
 * الأسعار التاريخية والموردين)".
 *
 * 🩹 ==================== قرار أداء مُتعمَّد منذ البداية ====================
 * بعد إصلاح مشكلة "Maximum execution time exceeded" بملفَي
 * PendingProductService/ShortageFulfillmentService، طبّقتُ نفس الدرس هنا
 * **قبل** حصول أي مشكلة، لا بعدها: صفر استعلامات داخل أي حلقة foreach.
 * كل خطوة إما استعلام واحد (`whereIn`/`pluck`) أو إدراج/تحديث جماعي
 * مُجزَّأ (`upsert` بدفعات 500). حتى ملف بآلاف الصفوف (مشتريات سنة كاملة
 * فعلياً) يكتمل خلال ثوانٍ معدودة، بغض النظر عن حجمه.
 *
 * ⚠️ قرار مقصود بخصوص المنتجات غير المعروفة: **لا** يُنشئ منتجات جديدة
 * تلقائياً (بعكس CentralInventoryImport مثلاً) — لأن ملف مشتريات تاريخي
 * يُفترَض أن يخص مواد **موجودة أصلاً** بالنظام (وصلت للصيدليات عبر دورة
 * المبيعات اليومية). أي باركود غير معروف هنا الأرجح أنه خطأ كتابة بالملف
 * وليس مادة جديدة فعلاً — لذلك يُسجَّل كـ"غير مطابَق" ليراجعه المستخدم
 * يدوياً، بدل إنشاء منتج وهمي بصمت.
 * ================================================================================
 */
class HistoricalPurchaseImportService
{
    /**
     * @param Collection<int, array{supplier_name:string, barcode:string, material_name:string, company:string, unit_cost:float}> $rows
     * @return array{matched: int, suppliers_created: int, unmatched: Collection}
     */
    public function process(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return ['matched' => 0, 'suppliers_created' => 0, 'unmatched' => collect()];
        }

        $now = now();

        // ══ الخطوة 1: الموردون — إدراج جماعي لكل الأسماء الجديدة دفعة واحدة ══
        $supplierNames = $rows->pluck('supplier_name')->unique()->values();
        $existingSupplierNames = Supplier::whereIn('name', $supplierNames)->pluck('name')->all();
        $newSupplierNames = $supplierNames->diff($existingSupplierNames)->values();

        if ($newSupplierNames->isNotEmpty()) {
            $supplierRows = $newSupplierNames->map(fn ($name) => [
                'name'           => $name,
                'lead_time_days' => 7, // افتراضي — يُعدَّل لاحقاً يدوياً من شاشة إدارة الموردين
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ])->all();

            foreach (array_chunk($supplierRows, 500) as $chunk) {
                DB::table('suppliers')->insertOrIgnore($chunk);
            }
        }

        $supplierIdsByName = Supplier::whereIn('name', $supplierNames)->pluck('id', 'name');

        // ══ الخطوة 2: المنتجات — مطابقة فقط بالباركود (بدون إنشاء تلقائي) ══
        $barcodes = $rows->pluck('barcode')->unique()->values();
        $productIdsByBarcode = Product::whereIn('barcode', $barcodes)->pluck('id', 'barcode');

        // ══ الخطوة 3: بناء صفوف supplier_products للمطابَق فقط ══
        $matched = 0;
        $unmatched = collect();
        $supplierProductRows = [];

        foreach ($rows as $row) {
            $supplierId = $supplierIdsByName[$row['supplier_name']] ?? null;
            $productId  = $productIdsByBarcode[$row['barcode']] ?? null;

            if (!$supplierId || !$productId) {
                $unmatched->push("{$row['barcode']} — {$row['material_name']} ({$row['supplier_name']})");
                continue;
            }

            $supplierProductRows[] = [
                'supplier_id'            => $supplierId,
                'product_id'              => $productId,
                'unit_cost'                => $row['unit_cost'],
                'is_preferred'             => false,
                'last_price_updated_at'  => $now,
                'created_at'               => $now,
                'updated_at'               => $now,
            ];
            $matched++;
        }

        foreach (array_chunk($supplierProductRows, 500) as $chunk) {
            // 🎯 upsert (وليس insertOrIgnore): لو المادة مرتبطة بنفس المورد أصلاً
            // من استيراد سابق أو إدخال يدوي، نُحدّث سعرها لآخر قيمة بالملف
            // بدل تجاهلها — الملف التاريخي يمثّل "آخر معرفة موثوقة بالسعر".
            DB::table('supplier_products')->upsert(
                $chunk,
                ['supplier_id', 'product_id'],
                ['unit_cost', 'last_price_updated_at', 'updated_at']
            );
        }

        return [
            'matched'            => $matched,
            'suppliers_created' => $newSupplierNames->count(),
            'unmatched'          => $unmatched->unique()->values(),
        ];
    }
}
