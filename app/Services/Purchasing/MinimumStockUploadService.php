<?php

namespace App\Services\Purchasing;

use App\Models\Product;
use App\Models\ProductPurchaseSetting;
use Illuminate\Support\Collection;

/**
 * 🎯 خدمة مستقلة لتحديث الحد الأدنى لكل مادة — بنفس فلسفة SellingPriceImportService
 * تماماً: "آخر ملف مرفوع هو مصدر الحقيقة الوحيد"، محفوظ حتى رفع ملف جديد
 * يُعدِّله. لا يُنشئ منتجات جديدة (مطابقة بالباركود فقط، بلا إنشاء تلقائي).
 *
 * 🩹 نستخدم updateOrCreate لكل صف (وليس upsert خام دفعة واحدة) عمداً:
 * upsert خام يحتاج تحديد كل أعمدة الجدول بالمصفوفة، مما قد "يُصفِّر" عمود
 * is_target (الذي يُدار حصراً من ملف جرد المشتريات، لا علاقة له بهذا
 * الملف) لو أُدرِج بالخطأ ضمن مصفوفة الإدراج. updateOrCreate يضمن تحديث
 * manual_minimum_stock فقط، بلا أي مخاطرة بالكتابة فوق حقل آخر.
 */
class MinimumStockUploadService
{
    /**
     * @param Collection<int, array{barcode:string, name:string, min_stock:?int}> $rows
     * @return array{matched:int, unmatched:Collection}
     */
    public function process(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return ['matched' => 0, 'unmatched' => collect()];
        }

        $barcodes = $rows->pluck('barcode')->unique()->values();
        $productsByBarcode = Product::whereIn('barcode', $barcodes)->pluck('id', 'barcode');

        $matched = 0;
        $unmatched = collect();

        foreach ($rows as $row) {
            $productId = $productsByBarcode[$row['barcode']] ?? null;

            if (!$productId) {
                $unmatched->push("{$row['barcode']} — {$row['name']}");
                continue;
            }

            ProductPurchaseSetting::updateOrCreate(
                ['product_id' => $productId],
                ['manual_minimum_stock' => $row['min_stock']]
            );
            $matched++;
        }

        return ['matched' => $matched, 'unmatched' => $unmatched->unique()->values()];
    }
}
