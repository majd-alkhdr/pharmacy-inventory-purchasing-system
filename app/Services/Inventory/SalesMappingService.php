<?php

namespace App\Services\Inventory;

use App\Enums\RestockingMode;
use App\Models\ImportSession;
use App\Models\Pharmacy;
use App\Models\Product;
use App\Models\TemporarySale;
use App\Services\Inventory\Support\BulkCaseUpdater;
use App\Services\Inventory\Support\BuildsReplenishmentSummary;
use App\Services\Inventory\Support\RecordsImportSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 المسؤولية الأساسية: مطابقة أسماء الصيدليات/المواد القادمة من ملف
 * المبيعات مع الجداول الحقيقية (pharmacies, products)، سواء تلقائياً (عبر
 * ذاكرة المطابقة بجدول pharmacy_mappings) أو يدوياً (من شاشة المطابقة).
 *
 * 🆕 (2026-09) مسؤولية إضافية: saveMapping() تُكمِل الآن **الجزء المؤجَّل
 * من الترميم بناءً على المبيعات** إن كانت الجلسة تنتظره — راجع
 * InventoryUploadService (المسار الشائع) وPendingProductService (مسار
 * الأصناف الجديدة) لتفاصيل متى يُؤجَّل هذا الجزء بالضبط ولماذا. هذا هو
 * المكان الوحيد بالتطبيق الذي "يعرف" أن مطابقة الأسماء انتهت بنجاح تواً،
 * فهو المكان الصحيح الوحيد لتشغيل الجزء الذي كان ينتظر بالضبط تلك المطابقة.
 */
class SalesMappingService
{
    use RecordsImportSession, BulkCaseUpdater, BuildsReplenishmentSummary;

    public function __construct(
        // 🩹 [إصلاح] كان SalesBasedRestockingStrategy مُحقَناً مباشرة (تحديد صريح
        // بالاسم) — يعمل فقط لأن "يستخدم مبيعات" و"استراتيجية المبيعات القديمة"
        // كانا مترادفين تماماً بالأوضاع الثلاثة الأصلية. الوضع الرابع كسر هذا
        // الترادف (يستخدم مبيعات، لكن باستراتيجية مختلفة كلياً). الحقن الآن عبر
        // الـ Resolver، الذي يقرأ الوضع الفعلي المخزَّن بالجلسة — راجع
        // resolveSalesComponent() وcompleteDeferredSalesRestocking() أدناه.
        private readonly RestockingStrategyResolver $strategyResolver,
        private readonly ShortageFulfillmentService $shortageFulfillment,
        private readonly SalesHistoryRecorderService $salesHistoryRecorder, // 🆕
        private readonly InvoiceScopeGate $scopeGate, // 🆕 (ب.4)
    ) {}

    public function getUnmappedSales(string $sessionId): array
    {
        return [
            'pharmacies' => TemporarySale::forSession($sessionId)->unmappedPharmacies()->distinct()->pluck('pharmacy_name'),
            'products'   => TemporarySale::forSession($sessionId)->unmappedProducts()->distinct()
                ->select('barcode', 'material_name', 'company')->get(),
        ];
    }

    /**
     * 🆕 لم تعد هذه الدالة تُولِّد أي فاتورة بنفسها — دورها اكتشاف
     * صيدليات/مواد جديدة عبر المطابقة فقط. $baseCompletionMessage تحمل
     * ملخص عملية الترميم الفعلية (القادمة من الاستراتيجية المختارة).
     */
    public function finishSalesMatching(string $sessionId, string $baseCompletionMessage = '✅ تمت العملية.'): void
    {
        $this->matchByExactNames($sessionId);
        $this->applyPharmacyMappingMemory($sessionId);

        $unmapped = $this->getUnmappedSales($sessionId);

        if ($unmapped['pharmacies']->isNotEmpty() || $unmapped['products']->isNotEmpty()) {
            $this->markSession($sessionId, 'needs_mapping', 'توجد أسماء غير مطابقة بملف المبيعات تحتاج مراجعة يدوية لإنشاء صيدليات/مواد جديدة (لن تؤثر على كميات الترميم).');
            return;
        }

        TemporarySale::forSession($sessionId)->delete();
        $this->markSession($sessionId, 'completed', $baseCompletionMessage . ' (تمت أيضاً مطابقة ملف المبيعات تلقائياً بلا أي أثر على كميات الترميم.)');
    }

    /** نسخة تُستخدم داخل transaction قائمة أصلاً (من PendingProductService) بدون تحديد حالة الجلسة النهائية */
    public function matchAutomatically(string $sessionId): void
    {
        $this->matchByExactNames($sessionId);
        $this->applyPharmacyMappingMemory($sessionId);
    }

    private function matchByExactNames(string $sessionId): void
    {
        DB::statement("
            UPDATE temporary_sales ts JOIN pharmacies p ON ts.pharmacy_name = p.name
            SET ts.pharmacy_id = p.id WHERE ts.session_id = ?
        ", [$sessionId]);

        DB::statement("
            UPDATE temporary_sales ts JOIN products pr ON ts.barcode = pr.barcode
            SET ts.product_id = pr.id WHERE ts.session_id = ?
        ", [$sessionId]);
    }

    private function applyPharmacyMappingMemory(string $sessionId): void
    {
        DB::statement("
            UPDATE temporary_sales ts JOIN pharmacy_mappings pm ON ts.pharmacy_name = pm.original_name
            SET ts.pharmacy_id = pm.pharmacy_id WHERE ts.session_id = ? AND ts.pharmacy_id IS NULL
        ", [$sessionId]);
    }

    /**
     * 🆕 لم تعد "تحفظ الكيانات الجديدة فقط ثم تنظّف" — الآن، بعد إتمام
     * المطابقة، تتحقق هل هذه الجلسة كانت بانتظار جزء مبيعات مؤجَّل من
     * الترميم (راجع تعليق الكلاس)، وتُكمله إن كان الأمر كذلك.
     */
    public function saveMapping(string $sessionId, array $pharmacies, array $products): array
    {
        DB::beginTransaction();
        try {
            [$skippedPharmacies, $newPharmacyNames, $manualPharmacyLinks] = $this->splitPharmacyChoices($pharmacies);
            $this->createNewPharmacies($newPharmacyNames);
            $this->persistPharmacyMappings($newPharmacyNames, $manualPharmacyLinks);

            DB::statement("
                UPDATE temporary_sales ts JOIN pharmacy_mappings pm ON ts.pharmacy_name = pm.original_name
                SET ts.pharmacy_id = pm.pharmacy_id WHERE ts.session_id = ?
            ", [$sessionId]);

            [$skippedProducts, $newProductsByBarcode, $manualProductLinks] = $this->splitProductChoices($products);
            $this->createNewProducts($newProductsByBarcode);

            DB::statement("
                UPDATE temporary_sales ts JOIN products pr ON ts.barcode = pr.barcode
                SET ts.product_id = pr.id WHERE ts.session_id = ? AND ts.product_id IS NULL
            ", [$sessionId]);

            $this->bulkUpdateByMap('temporary_sales', 'barcode', $manualProductLinks, 'product_id', $sessionId);

            // 🩹 لم نعد نحذف temporary_sales هنا مباشرة — الأسماء الآن
            // مُطابَقة بالكامل (pharmacy_id/product_id مملوءة)، وقد نحتاجها
            // فوراً لتنفيذ الجزء المؤجَّل من الترميم بناءً على المبيعات
            // أدناه. لو لم يكن هناك جزء مؤجَّل، completeDeferredSalesRestocking()
            // تحذفها بنفسها في كل الأحوال.

            DB::commit();

            if (count($skippedPharmacies) > 0 || count($skippedProducts) > 0) {
                $names = collect($skippedProducts)->pluck('material_name')
                    ->merge(collect($skippedPharmacies)->pluck('excel_name'))->filter()->implode('، ');
                TemporarySale::forSession($sessionId)->delete(); // 🩹 حالة الفشل الجزئي: ننظّف بلا محاولة ترميم بأسماء غير مكتملة
                return ['success' => false, 'message' => "⚠️ تعذّرت معالجة: {$names}"];
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return ['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()];
        }

        return $this->completeDeferredSalesRestocking($sessionId);
    }

    /**
     * 🎯 المكان الوحيد الذي يُكمِل فيه جزء "المبيعات" المؤجَّل من الترميم.
     * يتحقق من restocking_mode المُخزَّن بسجل الجلسة أصلاً (من طلب الرفع
     * الأول) ليقرر: هل هذه الجلسة كانت فعلياً بانتظار هذا الجزء تحديداً؟
     */
    private function completeDeferredSalesRestocking(string $sessionId): array
    {
        $session = ImportSession::where('session_id', $sessionId)->first();
        $mode = $session?->restockingMode();
        $scope = $session?->invoiceScope() ?? \App\Enums\InvoiceGenerationScope::Both; // 🆕 (ب.4)

        // 🩹 لا يوجد جزء مؤجَّل — إما جلسة لا علاقة لها بالترميم أصلاً
        // (مثلاً استيراد نواقص تاريخية)، أو وضعها المختار لا يستخدم
        // المبيعات بالترميم إطلاقاً. تنظيف عادي، لكن **نُسجِّل تاريخياً
        // أولاً** إن وُجدت مبيعات مُطابَقة فعلاً — حتى لو الوضع لا يستخدمها
        // بالترميم (مخزون قياسي + ملف مبيعات اختياري مرفَق معه، بعد مطابقة
        // يدوية) — وإلا تُفقَد بيانات كانت ستُفيد تقرير دوران المخزون.
        if (!$mode || !$mode->usesSales() || !TemporarySale::forSession($sessionId)->exists()) {
            if (TemporarySale::forSession($sessionId)->exists()) {
                $this->salesHistoryRecorder->recordFromSession($sessionId);
            }
            TemporarySale::forSession($sessionId)->delete();
            return ['success' => true, 'message' => '✅ تم حفظ مطابقة ملف المبيعات (صيدليات/مواد جديدة إن وُجدت).'];
        }

        try {
            // 🆕 (2026-09) قائمة دائمة — راجع تعليق InvoiceGenerationService للسبب
            $excludedCompanies = \App\Models\ExcludedCompany::currentList();

            // 🆕 تسجيل تاريخي دائم — مرة واحدة هنا، قبل قرار "هل يُشحَن
            // فعلياً؟" أدناه. راجع SalesHistoryRecorderService.
            $this->salesHistoryRecorder->recordFromSession($sessionId);

            // 🆕 [قرار صريح] وضع "بناءً على المبيعات" تحديداً (لا الهجين —
            // ذاك يفرض ملف جرد مركزي إلزامياً أصلاً) بلا ملف جرد مركزي
            // مرفَق بالجلسة الأصلية = تسجيل تاريخي فقط (تمّ للتو)، بلا أي
            // ترميم أو فاتورة، حتى بعد اكتمال المطابقة اليدوية للأسماء.
            // 🩹 [مُوسَّع] كان يقارن RestockingMode::Sales حصراً. الوضع الرابع
            // (SalesGatedStandardInventory) يشترك بنفس المنطق تماماً: بلا ملف جرد
            // مركزي مرفَق بالجلسة، لا مخزون نشحن منه أصلاً — تسجيل تاريخي فقط.
            // Hybrid عمداً غير مشمول (يفرض ملف جرد مركزي إلزامياً أصلاً بطلب الرفع).
            //
            // 🩹 [إصلاح جوهري إضافي] كان الشرط !$session->inventory_file_path
            // وحده — يفحص فقط عمود ملف الجرد المركزي **القديم** (مسار "ملفات
            // منفصلة"). لا يفحص warehouse_matrix_file_path إطلاقاً! كشف
            // المستودعات (الملف الواحد الجديد) يحمل بيانات المخزون المركزي
            // أيضاً، لكن بعمود مختلف تماماً — فجلسة رفعت كشف مستودعات فعلياً
            // (لا ملف جرد قديم منفصل) كانت تُصنَّف خطأً "بلا جرد مركزي مرفَق"،
            // رغم وجوده بالفعل، فتُعامَل كتسجيل تاريخي فقط ويُتجاهَل الترميم
            // الفعلي كلياً. هذا بالضبط سبب ظهور رسالة "بلا أي ترميم أو فاتورة"
            // رغم رفع كشف مستودعات حقيقي. الفحص الآن مطابق تماماً للفحص
            // المكافئ الصحيح أصلاً بـ InventoryUploadService::processInventoryUpload().
            $isPureHistoryMode = in_array($mode, [RestockingMode::Sales, RestockingMode::SalesGatedStandardInventory], true);
            if ($isPureHistoryMode && !$session->inventory_file_path && !$session->warehouse_matrix_file_path) {
                TemporarySale::forSession($sessionId)->delete();
                $shortageResult = $this->scopeGate->shortages($scope, $excludedCompanies); // 🆕 (ب.4)

                // 🩹 [إصلاح جوهري] $shortageResult كان يُحسَب (بآثاره الجانبية
                // الفعلية: فواتير إلحاقية حقيقية لنواقص قديمة تُنشأ بالفعل) لكن
                // نتيجته كانت تُهمَل كلياً بالرسالة المُعادة — التي كانت تقول
                // "بلا أي ترميم أو فاتورة" بصرف النظر عمّا حدث فعلياً. المستخدم
                // كان يرى فاتورة إلحاقية جديدة فعلياً بقاعدة البيانات، بينما
                // الرسالة تنفي حدوث أي شيء. نفس الإصلاح المُطبَّق أصلاً بالمسار
                // المكافئ بـ InventoryUploadService — $shortageNote هنا يطابقه حرفياً.
                $shortageNote = $shortageResult['fulfilled_count'] > 0 || $shortageResult['partial_count'] > 0
                    ? " كما تم ترميم نواقص قديمة مستقلة تماماً عن هذه الدفعة (لا علاقة لها بملف المبيعات المرفوع الآن): {$shortageResult['fulfilled_count']} بالكامل، {$shortageResult['partial_count']} جزئياً."
                    : '';

                return [
                    'success' => true,
                    'message' => '✅ تم تسجيل بيانات المبيعات بنجاح لاستخدامها بتقرير دوران المخزون — بلا أي ترميم أو فاتورة أساسية جديدة (لا يوجد ملف جرد مركزي أو كشف مستودعات مرفَق بهذه الجلسة).' . $shortageNote,
                ];
            }

            // 🆕 (ب.4) "نواقص فقط": جزء المبيعات لا يُنفَّذ، فتُحذف temporary_sales هنا صراحة —
            // استراتيجية المبيعات هي التي كانت تستهلكها وتحذفها، ولن تُشغَّل.
            $salesResult = $this->scopeGate->basic($scope, fn () => $this->strategyResolver->resolveSalesComponent($mode)->execute(new RestockingContext(
                mode: $mode,
                sessionId: $sessionId,
            )));
            TemporarySale::forSession($sessionId)->delete();
            $shortageResult = $this->scopeGate->shortages($scope, $excludedCompanies);

            $msg = $this->buildReplenishmentSummaryMessage(
                $salesResult,
                $shortageResult,
                $excludedCompanies,
                ' (اكتمل جزء المبيعات من الترميم بعد المطابقة اليدوية لأسماء الصيدليات/المواد.)'
            ) . $this->scopeGate->note($scope);

            return ['success' => true, 'message' => $msg];
        } catch (\Throwable $e) {
            // 🛡️ المطابقة نفسها نجحت وتُحفَظ بصرف النظر (already committed
            // أعلاه) — فشل تنفيذ الترميم بعدها لا يجب أن يُظهر للمستخدم أن
            // المطابقة نفسها فشلت، فقط أن جزء الترميم تحديداً واجه خطأ.
            TemporarySale::forSession($sessionId)->delete();
            return ['success' => false, 'message' => '⚠️ تم حفظ مطابقة الأسماء بنجاح، لكن حدث خطأ أثناء تنفيذ جزء الترميم بناءً على المبيعات: ' . $e->getMessage()];
        }
    }

    private function splitPharmacyChoices(array $pharmacies): array
    {
        $skipped = [];
        $new     = [];
        $manual  = [];

        foreach ($pharmacies as $item) {
            $excelName = $item['excel_name'] ?? null;
            $mappedId  = $item['mapped_id'] ?? null;
            if (!$excelName || !$mappedId) {
                $skipped[] = $item;
                continue;
            }
            $mappedId === 'NEW' ? $new[] = $excelName : $manual[$excelName] = $mappedId;
        }

        return [$skipped, array_values(array_unique($new)), $manual];
    }

    private function createNewPharmacies(array $newPharmacyNames): void
    {
        if (empty($newPharmacyNames)) {
            return;
        }
        $now  = now();
        $rows = array_map(fn($name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now], $newPharmacyNames);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('pharmacies')->insertOrIgnore($chunk);
        }
    }

    private function persistPharmacyMappings(array $newPharmacyNames, array $manualPharmacyLinks): void
    {
        $newPharmacyIdsByName = !empty($newPharmacyNames)
            ? Pharmacy::whereIn('name', $newPharmacyNames)->pluck('id', 'name')
            : collect();

        $now  = now();
        $rows = [];
        foreach ($newPharmacyNames as $name) {
            if ($pid = $newPharmacyIdsByName[$name] ?? null) {
                $rows[] = ['original_name' => $name, 'pharmacy_id' => $pid, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach ($manualPharmacyLinks as $name => $pid) {
            $rows[] = ['original_name' => $name, 'pharmacy_id' => $pid, 'created_at' => $now, 'updated_at' => $now];
        }
        if (!empty($rows)) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('pharmacy_mappings')->upsert($chunk, ['original_name'], ['pharmacy_id', 'updated_at']);
            }
        }
    }

    private function splitProductChoices(array $products): array
    {
        $skipped = [];
        $new     = [];
        $manual  = [];

        foreach ($products as $item) {
            $barcode      = $item['barcode'] ?? null;
            $materialName = $item['material_name'] ?? null;
            $company      = $item['company'] ?? null;
            $mappedId     = $item['mapped_id'] ?? null;

            if (!$barcode || !$mappedId) {
                $skipped[] = $item;
                continue;
            }

            if ($mappedId === 'NEW') {
                $new[$barcode] = [
                    'name'    => $materialName,
                    'company' => ($company && $company !== '-') ? $company : 'عام',
                ];
            } else {
                $manual[$barcode] = $mappedId;
            }
        }

        return [$skipped, $new, $manual];
    }

    private function createNewProducts(array $newProductsByBarcode): void
    {
        if (empty($newProductsByBarcode)) {
            return;
        }

        $now  = now();
        $rows = [];
        foreach ($newProductsByBarcode as $barcode => $p) {
            $rows[] = ['barcode' => $barcode, 'name' => $p['name'], 'company' => $p['company'], 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('products')->insertOrIgnore($chunk);
        }

        $newBarcodes         = array_keys($newProductsByBarcode);
        $productIdsByBarcode = Product::whereIn('barcode', $newBarcodes)->pluck('id', 'barcode');

        if ($productIdsByBarcode->isNotEmpty()) {
            $now     = now();
            $invRows = $productIdsByBarcode
                ->map(fn($id) => ['product_id' => $id, 'quantity' => 0, 'created_at' => $now, 'updated_at' => $now])
                ->values()->all();
            foreach (array_chunk($invRows, 500) as $chunk) {
                DB::table('central_inventories')->insertOrIgnore($chunk);
            }
        }
    }
}
