<?php

namespace App\Services\Purchasing;

use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\ProductPurchasePrice;
use App\Models\ProductPurchaseSetting;
use App\Models\ProductSellingPrice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 المسؤولية الوحيدة: تطبيق ملف جرد المشتريات الموحّد (بعد قراءته عبر
 * PurchaseInventoryImport) على قاعدة البيانات — استبدال كامل لرصيد
 * المشتريات (مثل CentralInventoryStockService بقسم المناقلات تماماً)،
 * بالإضافة لتحديث سعر البيع، آخر سعر شراء، تاريخ آخر شراء، الحد الأدنى،
 * وعلم "تارجت" لكل مادة.
 *
 * ⚠️ لا يُنشئ منتجات جديدة أبداً — أي باركود غير معروف بالنظام يُسجَّل
 * كـ"غير مطابَق" ليراجعه المستخدم يدوياً (بنفس نمط
 * HistoricalPurchaseImportService)، لأن ملف جرد المشتريات يُفترَض أن يخص
 * مواد موجودة أصلاً بالنظام (وصلت إليه عبر دورة المناقلات اليومية).
 *
 * ⚠️ لا يلمس جدول categories أو category_product إطلاقاً — قرار صريح من
 * المستخدم، راجع تعليق PurchaseInventoryImport للتفاصيل.
 *
 * 🆕 تفعيل "آخر سعر شراء" (2026-08، بعد تحديد رقم العمود صراحة من صاحب
 * المشروع = آخر عمود بالملف): يُخزَّن الآن فعلياً بجدول تاريخي
 * product_purchase_prices، ليصبح مراقب تذبذب التكلفة (CostVarianceMonitorService)
 * قادراً على المقارنة الفعلية بين آخر سعرين متتاليين.
 */
class PurchaseInventoryUploadService
{
    /**
     * @param Collection<int, array> $rows خام من PurchaseInventoryImport
     * @return array{matched:int, updated:int, unmatched:Collection}
     */
    public function process(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return ['matched' => 0, 'updated' => 0, 'unmatched' => collect()];
        }

        $grouped = $this->groupDuplicateBarcodes($rows);

        $barcodes = $grouped->keys();
        $productsByBarcode = Product::whereIn('barcode', $barcodes)->get()->keyBy('barcode');

        $unmatched = collect();
        $matched = 0;

        DB::transaction(function () use ($grouped, $productsByBarcode, &$unmatched, &$matched) {
            foreach ($grouped as $barcode => $data) {
                $product = $productsByBarcode->get($barcode);

                if (!$product) {
                    $unmatched->push("{$barcode} — {$data['name']} ({$data['company']})");
                    continue;
                }

                $this->applyToProduct($product, $data);
                $matched++;
            }
        });

        return [
            'matched'   => $matched,
            'updated'   => $matched,
            'unmatched' => $unmatched->values(),
        ];
    }

    /**
     * 🎯 يطبّق قرار المستخدم بالضبط: "تكرار الباركود بعدة صفوف → تُجمع
     * الكميات، ويُعتمَد تاريخ الصلاحية المرافق للسطر صاحب الكمية الأكبر
     * بين المكرَّرات." بقية الحقول (الاسم/الشركة/الأسعار/تاريخ الشراء/الحد
     * الأدنى/تارجت) تُؤخَذ أيضاً من نفس السطر "الممثِّل" (صاحب أكبر كمية)
     * لضمان الاتساق الداخلي بين كل الحقول المرتبطة بنفس الدفعة.
     */
    private function groupDuplicateBarcodes(Collection $rows): Collection
    {
        return $rows
            ->groupBy('barcode')
            ->map(function (Collection $group) {
                $representativeRow = $group->sortByDesc('quantity')->first();
                $totalQuantity = $group->sum('quantity');

                return [
                    'name'                    => $representativeRow['name'],
                    'company'                 => $representativeRow['company'],
                    'quantity'                => $totalQuantity,
                    'selling_price'          => $representativeRow['selling_price'],
                    'expiry_date'            => $representativeRow['expiry_date'],
                    'purchase_date'          => $representativeRow['purchase_date'],
                    'min_stock'              => $representativeRow['min_stock'],
                    'is_target'              => $representativeRow['is_target'],
                    'last_purchase_price'   => $representativeRow['last_purchase_price'] ?? null,
                ];
            });
    }

    private function applyToProduct(Product $product, array $data): void
    {
        $today = now()->toDateString();

        // 1) الاسم والشركة — يُحدَّثان مباشرة (قرار صريح: نفس مصدر السحب
        // مع قسم المناقلات، فلا مشكلة من تحديثهما هنا).
        $product->update([
            'name'                => $data['name'],
            'company'             => $data['company'],
            'last_purchase_date' => $data['purchase_date'] ?? $product->last_purchase_date ?? $today,
        ]);

        // 2) الرصيد — استبدال كامل لدفعات هذا المنتج القادمة من الجرد
        // فقط (source='purchase_inventory_upload')، بدون لمس أي دفعة
        // مصدرها استلام فعلي قديم (إن وُجدت من قبل هذا التغيير).
        InventoryBatch::where('product_id', $product->id)
            ->where('source', 'purchase_inventory_upload')
            ->delete();

        InventoryBatch::create([
            'product_id'   => $product->id,
            'quantity'      => $data['quantity'],
            'expiry_date'   => $data['expiry_date'],
            'received_at'   => $today,
            'source'         => 'purchase_inventory_upload',
        ]);

        // 3) سعر البيع — سطر جديد بجدول تاريخي (فقط إن وُجدت قيمة بالملف)
        if ($data['selling_price'] !== null) {
            ProductSellingPrice::create([
                'product_id'      => $product->id,
                'price'            => $data['selling_price'],
                'effective_from'  => $today,
            ]);
        }

        // 4) 🆕 آخر سعر شراء — مُفعَّل الآن فعلياً (كان معلَّقاً بانتظار
        // تحديد رقم العمود). نفس فلسفة سعر البيع تماماً: سطر جديد بجدول
        // تاريخي product_purchase_prices، ويُغذّي مراقب تذبذب التكلفة
        // مباشرة عبر المقارنة بين آخر سطرين متتاليين لكل منتج.
        //
        // 🩹 حماية بسيطة لكن مهمة: لا نُنشئ سطراً جديداً إن كان السعر
        // مطابقاً تماماً لآخر سعر مسجَّل أصلاً — هذا يمنع "تضخّم" الجدول
        // بسطور متطابقة عند رفع نفس الملف (أو ملف بنفس الأسعار) عدة مرات
        // متتالية بلا أي تغيير فعلي، ويحافظ على معنى "تذبذب حقيقي" لمراقب
        // التكلفة (فرق بين قيمتين مختلفتين فعلياً، لا تكراراً صناعياً).
        if ($data['last_purchase_price'] !== null) {
            $currentLatest = ProductPurchasePrice::latestFor($product->id);

            if ($currentLatest === null || round($currentLatest, 2) !== round($data['last_purchase_price'], 2)) {
                ProductPurchasePrice::create([
                    'product_id'      => $product->id,
                    'price'            => $data['last_purchase_price'],
                    'effective_from'  => $today,
                ]);
            }
        }

        // 5) الحد الأدنى وعلم تارجت — المصدر الوحيد للحقيقة = آخر ملف
        // مرفوع. "-" (min_stock = null) تعني "لا حد أدنى" فعلياً، فنُصفِّر
        // الإعداد اليدوي القديم بدل تركه معلَّقاً بقيمة قديمة قد تكون خاطئة.
        ProductPurchaseSetting::updateOrCreate(
            ['product_id' => $product->id],
            [
                'is_target'             => $data['is_target'],
                'manual_minimum_stock' => $data['min_stock'],
            ]
        );
    }
}
