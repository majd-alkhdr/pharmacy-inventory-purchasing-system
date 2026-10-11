<?php

namespace App\Services\Inventory;

use App\Imports\CentralInventoryImport;
use App\Models\CentralInventory;
use App\Models\PendingInventoryItem;
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
 *
 * 🆕 (2026-09) تستدعي الآن أيضاً CentralInventoryPriceRecorderService —
 * ليست جزءاً من "تطبيق الكمية" منطقياً (سبب تغيير مختلف تماماً)، لذلك
 * استدعاء صريح منفصل بجوار applyInventoryReplace()، لا داخلها. راجع
 * توثيق تلك الخدمة نفسها للسبب الكامل.
 *
 * 🆕 (2026-09) [إضافة مطلوبة صراحة] هذه الشاشة المستقلة أصبحت الآن
 * الطريقة الرسمية لتعريف مواد جديدة كلياً بالنظام لأول مرة — بدون الحاجة
 * لرفع ملف مبيعات أو جرد صيدليات وهمي فقط لتمرير مواد جديدة عبر تدفق
 * الرفع المدمج. بقرار صريح: **لا تُعاد كتابة منطق "الأصناف المعلَّقة"
 * هنا من الصفر** — تُعاد استخدام نفس PendingInventoryItem/PendingProductService
 * الموجودتين أصلاً (نفس الجدول، نفس الشاشة، نفس زر التأكيد)، فقط بجلسة
 * لا تحمل أي بيانات مبيعات أو صيدليات مرفقة. PendingProductService تتعامل
 * مع هذه الحالة بأمان تام أصلاً: pharmacy_inventory_pharmacy_ids يبقى
 * فارغاً افتراضياً (لا صيدليات متأثرة = لا شيء يُرمَّم، بلا أي كود إضافي
 * هنا)، وTemporarySale::forSession() لن يجد شيئاً لهذه الجلسة إطلاقاً
 * (لا ملف مبيعات رُفع أصلاً) — فيتجاهل جزء المبيعات تلقائياً بنفس المنطق
 * الموجود مسبقاً، بلا أي حاجة لتمييز هذا المسار عن غيره صراحة.
 */
class CentralInventoryStockService
{
    use RecordsImportSession;

    public function __construct(
        private readonly CentralInventoryPriceRecorderService $priceRecorder,
    ) {}

    /**
     * تُستخدم من الشاشة المستقلة "جرد المستودع المركزي" (uploadInventory).
     */
    public function processStockUpload(string $sessionId, string $invPath): void
    {
        try {
            $import = new CentralInventoryImport();
            Excel::import($import, Storage::path($invPath));

            $this->applyInventoryReplace($import->matched);
            $pricesChanged = $this->priceRecorder->recordChanges($import->prices);

            // 🆕 بدل تجاهل المواد غير المطابَقة كتحذير نصي فقط — تمريرها
            // الآن عبر نفس تدفق "الأصناف المعلَّقة" الموجود أصلاً، ليتمكن
            // المستخدم من تأكيد إنشائها كمنتجات حقيقية بضغطة واحدة، بدل
            // إضافتها يدوياً بمكان آخر ثم إعادة رفع نفس الملف مجدَّداً.
            if (!empty($import->unmatched)) {
                foreach ($import->unmatched as $item) {
                    PendingInventoryItem::create([
                        'session_id'    => $sessionId,
                        'barcode'       => $item['barcode'],
                        'material_name' => $item['material_name'],
                        'company'       => $item['company'],
                        'quantity'      => $item['quantity'],
                        'pack_size'     => $item['pack_size'] ?? null,
                        'price'         => $item['price'] ?? null,
                    ]);
                }

                $count = count($import->unmatched);
                $this->markSession($sessionId, 'needs_pending_products', "تم تحديث الجرد للمواد المعروفة بنجاح، وتم العثور على {$count} مادة جديدة كلياً تحتاج تأكيداً.");
                Storage::delete($invPath);
                return;
            }

            $priceNote = $pricesChanged > 0 ? " 💰 تحديث سعر لـ {$pricesChanged} مادة." : '';
            $this->markSession($sessionId, 'completed', '🎉 تم تحديث جرد المستودع المركزي بنجاح لكل المواد.' . $priceNote);

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

        // 🩹 (إصلاح) بدل whereNotIn(...) بقائمة قد تبلغ عشرات الآلاف من
        // المعرِّفات — استعلام واحد بلا أي شرط WHERE يُصفِّر الجميع أولاً
        // (استبدال كامل حقيقي، بلا مخاطر حجم استعلام)، ثم upsert أدناه
        // يُعيد كتابة الرصيد الصحيح فوق الأصفار للمواد المذكورة بالملف فقط.
        // النتيجة النهائية مطابقة تماماً للسطر القديم، لكن بلا أي قائمة ضخمة.
        CentralInventory::query()->update(['quantity' => 0]);

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
