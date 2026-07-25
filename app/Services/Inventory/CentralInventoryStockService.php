<?php

namespace App\Services\Inventory;

use App\Imports\CentralInventoryImport;
use App\Models\CentralInventory;
use App\Services\Inventory\Support\RecordsImportSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 🎯 المسؤولية الوحيدة لهذه الخدمة: قراءة ملف إكسل الجرد وتطبيقه على
 * central_inventories. لا تعرف شيئاً عن المبيعات، المطابقة، أو الفواتير —
 * وهذا بالضبط معنى "سبب واحد للتغيير" (لو تغيّر شكل ملف الجرد، هذا هو
 * الملف الوحيد الذي يتأثر).
 */
class CentralInventoryStockService
{
    use RecordsImportSession;

    /**
     * تُستخدم من الشاشة المستقلة "جرد المستودع المركزي" (uploadInventory).
     */
    public function processStockUpload(string $sessionId, string $invPath): void
    {
        try {
            $import = new CentralInventoryImport();
            Excel::import($import, Storage::path($invPath));

            $this->applyInventoryReplace($import->matched);

            if (!empty($import->unmatched)) {
                $names = collect($import->unmatched)->pluck('material_name')->implode('، ');
                $this->markSession(
                    $sessionId,
                    'completed',
                    '✅ تم تحديث الجرد للمواد المعروفة بنجاح. ⚠️ لكن هذه المواد غير مسجلة بالنظام ولم تُحدَّث: '
                        . $names . '. يجب إضافتها أولاً كمنتجات ثم إعادة رفع الجرد لها.'
                );
            } else {
                $this->markSession($sessionId, 'completed', '🎉 تم تحديث جرد المستودع المركزي بنجاح لكل المواد.');
            }

            Storage::delete($invPath);
        } catch (\Exception $e) {
            Log::error("Stock upload failed [{$sessionId}]: " . $e->getMessage());
            $this->markSession($sessionId, 'failed', 'حدث خطأ أثناء معالجة ملف الجرد: ' . $e->getMessage());
            Storage::delete($invPath);
        }
    }

    /**
     * 🎯 عامة (public) بقصد: تستخدمها أيضاً InventoryUploadService عند
     * معالجة الجرد ضمن تدفق "مبيعات + جرد"، بدل تكرار نفس منطق upsert.
     *
     * @param array<int,int> $matchedQuantities [product_id => quantity]
     */
    public function applyInventoryReplace(array $matchedQuantities): void
    {
        if (empty($matchedQuantities)) {
            return;
        }

        // كل مادة غير موجودة بالملف الجديد تُعتبر كميتها صفر (استبدال كامل، وليس جمع)
        CentralInventory::whereNotIn('product_id', array_keys($matchedQuantities))->update(['quantity' => 0]);

        $rows = [];
        $now  = now()->toDateTimeString();
        foreach ($matchedQuantities as $productId => $qty) {
            $rows[] = ['product_id' => $productId, 'quantity' => $qty, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('central_inventories')->upsert($chunk, ['product_id'], ['quantity', 'updated_at']);
        }
    }
}
