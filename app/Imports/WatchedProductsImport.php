<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\WatchedProduct;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;

/**
 * 🎯 ملف "أصناف للمراقبة" — عمودان فقط: رمز المادة، اسم المادة (الاسم
 * لغرض القراءة البشرية فقط بالملف، المطابقة الفعلية تتم بالرمز حصراً).
 *
 * 🎓 لماذا لا يمر هذا الملف عبر تدفق "الأصناف المعلَّقة" (PendingInventoryItem)
 * كباقي ملفات الاستيراد؟ لأن هذه الشاشة مختلفة جوهرياً بالغرض: هي
 * لمراقبة منتجات **موجودة بالفعل بالنظام**، لا لتعريف منتجات جديدة. رمز
 * غير موجود هنا ليس "منتجاً جديداً يستحق الإنشاء" — إنه على الأرجح خطأ
 * إملائي بالرمز، أو منتج لم يُسجَّل بالنظام بعد بأي طريق (لم يُشترَ ولم
 * يُبَع مطلقاً). في كلتا الحالتين، الإجراء الصحيح تنبيه المستخدم لا
 * إنشاء منتج فارغ بلا أي تاريخ حركة يستحق المراقبة أصلاً.
 */
class WatchedProductsImport implements ToCollection, WithStartRow
{
    public int $addedCount = 0;
    public array $unmatchedBarcodes = [];

    public function startRow(): int
    {
        return 2;
    }

    public function collection(Collection $rows)
    {
        $barcodes = $rows->map(fn($row) => trim($row[0] ?? ''))->filter()->unique()->values();

        if ($barcodes->isEmpty()) {
            return;
        }

        $productIdsByBarcode = Product::whereIn('barcode', $barcodes)->pluck('id', 'barcode');

        $alreadyWatched = WatchedProduct::whereIn('product_id', $productIdsByBarcode->values())
            ->pluck('product_id')->flip();

        $newRows = [];
        $now = now();

        foreach ($barcodes as $barcode) {
            $productId = $productIdsByBarcode->get($barcode);

            if (!$productId) {
                $this->unmatchedBarcodes[] = $barcode;
                continue;
            }
            if ($alreadyWatched->has($productId)) {
                continue; // 🎯 مراقَب أصلاً — لا نُنشئ صفاً مكرَّراً، ولا نعتبره خطأً
            }

            $newRows[] = ['product_id' => $productId, 'created_at' => $now, 'updated_at' => $now];
            $alreadyWatched->put($productId, true); // تفادي تكرار نفس الرمز داخل نفس الملف
        }

        if (!empty($newRows)) {
            WatchedProduct::insert($newRows);
            $this->addedCount = count($newRows);
        }
    }
}
