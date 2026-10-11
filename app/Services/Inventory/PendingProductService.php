<?php
// app/Services/Inventory/PendingProductService.php

namespace App\Services\Inventory;

use App\Enums\RestockingMode;
use App\Models\CentralInventory;
use App\Models\ImportSession;
use App\Models\PendingInventoryItem;
use App\Models\Product;
use App\Models\TemporarySale;
use App\Services\Inventory\Support\BuildsReplenishmentSummary;
use Illuminate\Support\Facades\DB;

class PendingProductService
{
    use BuildsReplenishmentSummary;

    public function __construct(
        private readonly ShortageFulfillmentService $shortageFulfillment,
        private readonly SalesMappingService $salesMapping,
        private readonly RestockingStrategyResolver $strategyResolver, // 🆕 بديل ParLevelReplenishmentService المباشر
        private readonly CentralInventoryPriceRecorderService $priceRecorder, // 🆕
        private readonly SalesHistoryRecorderService $salesHistoryRecorder, // 🆕
        private readonly InvoiceScopeGate $scopeGate, // 🆕 (ب.4)
    ) {}

    public function createPendingProducts(string $sessionId): array
    {
        DB::beginTransaction();
        try {
            $pendingItems = PendingInventoryItem::where('session_id', $sessionId)->get();

            // 🩹 [إصلاح جوهري 2026-09] النسخة الأولى كانت تُنشئ كل منتج
            // وكل صف central_inventories عبر updateOrCreate() **داخل حلقة**
            // — استعلامان (SELECT ثم INSERT/UPDATE) لكل صف على حدة. بملف
            // 19,500 مادة جديدة، هذا يعني قرابة 78,000 استعلام متسلسل بنفس
            // الطلب الواحد — تجاوز مهلة التنفيذ (60 ثانية بـ PHP) بسهولة قبل
            // حتى الانتهاء من نصف الملف. الحل: نفس النتيجة النهائية بالضبط
            // (نفس شروط المطابقة، نفس الحقول المُحدَّثة)، لكن بدُفعات
            // (Batches) عبر upsert() — استعلام واحد فقط لكل 500 صف، بدل
            // 500 استعلام منفصل. الفرق: من ~78,000 استعلام إلى ~80 فقط.

            // 🎯 خطوة 1: تحضير صفوف upsert للمنتجات — barcode واحد فريد لكل
            // صف حتى لو تكرر نفس الباركود بالملف المرفوع أكثر من مرة (نادر،
            // لكن upsert بصف مكرَّر بنفس الدفعة يفشل بخطأ "duplicate key"
            // لو لم نُزِل التكرار هنا أولاً).
            $now = now();
            $productRows = $pendingItems
                ->unique('barcode')
                ->map(fn($item) => [
                    'barcode'    => $item->barcode,
                    'name'       => $item->material_name,
                    'company'    => $item->company,
                    // 🆕 حجم العبوة القادم من ملف الجرد المركزي إن ذُكر
                    // لهذا الباركود، وإلا 0 (بلا قيد تعبئة — السلوك القديم).
                    'pack_size'  => $item->pack_size ?? 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all();

            foreach (array_chunk($productRows, 500) as $chunk) {
                // 🎯 نفس دلالة updateOrCreate(['barcode'=>...], [...]) بالضبط،
                // لكن بدُفعة واحدة: باركود موجود فعلاً يُحدَّث بنفس الحقول،
                // باركود جديد يُدرَج — استعلام واحد يكفي لكل 500 صف معاً.
                Product::upsert($chunk, ['barcode'], ['name', 'company', 'pack_size', 'updated_at']);
            }

            // 🎯 خطوة 2: جلب دفعة واحدة لكل الـ IDs — upsert() لا يُعيد IDs
            // الصفوف المتعددة (بخلاف create() لصف واحد)، فنحتاج استعلاماً
            // صريحاً واحداً هنا للحصول عليها، بدل استعلام SELECT منفصل لكل صف.
            $barcodes = $pendingItems->pluck('barcode')->unique()->all();
            $productsByBarcode = Product::whereIn('barcode', $barcodes)->get()->keyBy('barcode');

            // 🎯 خطوة 3: نفس أسلوب الدُفعات لـ central_inventories
            $inventoryRows = $pendingItems
                ->unique('barcode')
                ->map(function ($item) use ($productsByBarcode, $now) {
                    $product = $productsByBarcode->get($item->barcode);
                    if (!$product) {
                        return null; // 🛡️ احتياط بعيد الاحتمال — لن يحدث عملياً بعد الخطوة 1
                    }
                    return [
                        'product_id' => $product->id,
                        'quantity'   => $item->quantity,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })
                ->filter()
                ->values()
                ->all();

            foreach (array_chunk($inventoryRows, 500) as $chunk) {
                CentralInventory::upsert($chunk, ['product_id'], ['quantity', 'updated_at']);
            }

            // 🆕 أول سعر لمنتج جديد كلياً — راجع CentralInventoryPriceRecorderService؛
            // نفس القاعدة تنطبق هنا أيضاً (سطر جديد فقط لو سعر مذكور فعلياً).
            // 🩹 يعتمد الآن على $productsByBarcode المجهَّزة أعلاه بدل $product
            // من داخل الحلقة القديمة المحذوفة.
            $newProductPrices = [];
            foreach ($pendingItems->unique('barcode') as $item) {
                if (!is_null($item->price)) {
                    $product = $productsByBarcode->get($item->barcode);
                    if ($product) {
                        $newProductPrices[$product->id] = (float) $item->price;
                    }
                }
            }

            if (!empty($newProductPrices)) {
                $this->priceRecorder->recordChanges($newProductPrices);
            }

            PendingInventoryItem::where('session_id', $sessionId)->delete();

            $session = ImportSession::where('session_id', $sessionId)->first();
            // 🆕 (2026-09) قائمة دائمة — راجع تعليق InvoiceGenerationService للسبب
            $excludedCompanies   = \App\Models\ExcludedCompany::currentList();
            $affectedPharmacyIds = $session->pharmacy_inventory_pharmacy_ids ?? [];
            $mode = $session->restockingMode();
            $scope = $session->invoiceScope(); // 🆕 (ب.4)

            // 🆕 جزء "المخزون القياسي" (إن كان الوضع يستخدمه) يُنفَّذ دائماً
            // فوراً هنا — بياناته (جرد صيدليات + مركزي، المُكتمل بالحلقة
            // أعلاه للتو) لا تحتاج أي مطابقة أسماء إطلاقاً. راجع
            // InventoryUploadService لشرح كامل لنفس هذا النمط بالضبط.
            $standardInventoryResult = null;
            if ($mode->usesStandardInventory() && $scope->includesBasic()) { // 🆕 (ب.4)
                $standardInventoryResult = $this->strategyResolver
                    ->resolve(RestockingMode::StandardInventory)
                    ->execute(new RestockingContext(
                        mode: RestockingMode::StandardInventory,
                        pharmacyIds: $affectedPharmacyIds,
                        excludedCompanies: $excludedCompanies,
                    ));
            }
            $shortageResult = $this->scopeGate->shortages($scope, $excludedCompanies); // 🆕 (ب.4)

            $hasSales = TemporarySale::forSession($sessionId)->exists();

            if (!$hasSales) {
                DB::commit();
                return [
                    'needs_mapping' => false,
                    'message' => $this->buildReplenishmentSummaryMessage($standardInventoryResult ?? RestockingResult::empty(), $shortageResult, $excludedCompanies),
                ];
            }

            $this->salesMapping->matchAutomatically($sessionId);
            DB::commit();

            $unmapped = $this->salesMapping->getUnmappedSales($sessionId);
            if ($unmapped['pharmacies']->isNotEmpty() || $unmapped['products']->isNotEmpty()) {
                // 🆕 جزء المبيعات (إن كان الوضع يستخدمه) يُستكمَل لاحقاً من
                // SalesMappingService::saveMapping() بعد مطابقة يدوية —
                // راجع تعليقها. جزء المخزون القياسي أعلاه نُفِّذ بالفعل، لا
                // يُعاد ولا يُلغى بانتظار جزء المبيعات.
                return ['needs_mapping' => true, ...$unmapped];
            }

            // ✅ بيانات المبيعات (إن استُخدمت بالوضع) مُطابَقة بالكامل الآن —
            // آمن تنفيذ جزء المبيعات فوراً بدل الانتظار.

            // 🆕 تسجيل تاريخي دائم — مرة واحدة هنا، قبل أي فرع لاحق (سواء
            // استخدم الوضع المبيعات بالترميم الفعلي أم لا). راجع
            // SalesHistoryRecorderService. ملاحظة: هذا المسار (تأكيد أصناف
            // معلَّقة) لا يُطرَح فيه سؤال "هل يوجد ملف جرد مركزي؟" أصلاً —
            // الوصول لهذه الدالة نفسها مستحيل بلا ملف جرد مركزي مُعالَج
            // مسبقاً (هو ما أنشأ الأصناف المعلَّقة أصلاً).
            $this->salesHistoryRecorder->recordFromSession($sessionId);

            if ($mode->usesSales() && $scope->includesBasic()) { // 🆕 (ب.4) — وإلا يسقط للحذف أدناه
                // 🩹 [إصلاح] كان resolve(RestockingMode::Sales) صريحاً بالاسم — يعمل
                // بالثلاثة أوضاع القديمة (Sales وHybrid كلاهما يريدان استراتيجية
                // المبيعات القديمة تحديداً هنا)، لكنه كان سيُوجِّه الوضع الرابع الجديد
                // خطأً لنفس الاستراتيجية القديمة أيضاً رغم أنه يحتاج استراتيجيته
                // الخاصة. resolveSalesComponent($mode) يقرأ الوضع الفعلي فيُصيب
                // الاستراتيجية الصحيحة لكل الأوضاع الأربعة — راجع تعليقها الكامل.
                $salesResult = $this->strategyResolver
                    ->resolveSalesComponent($mode)
                    ->execute(new RestockingContext(mode: $mode, sessionId: $sessionId));

                $combined = $standardInventoryResult ? $standardInventoryResult->mergeWith($salesResult) : $salesResult;

                return [
                    'needs_mapping' => false,
                    'message' => $this->buildReplenishmentSummaryMessage($combined, $shortageResult, $excludedCompanies, ' (تمت أيضاً مطابقة ملف المبيعات تلقائياً.)'),
                ];
            }

            // 🩹 الوضع لا يستخدم المبيعات بالترميم إطلاقاً — كانت بياناتها
            // مفيدة فقط لاكتشاف الأسماء الجديدة أعلاه، لا حاجة لها بعد الآن.
            TemporarySale::forSession($sessionId)->delete();

            return [
                'needs_mapping' => false,
                'message' => $this->buildReplenishmentSummaryMessage($standardInventoryResult ?? RestockingResult::empty(), $shortageResult, $excludedCompanies, ' (تمت مطابقة ملف المبيعات تلقائياً بلا أثر على الترميم — الوضع المختار لا يستخدم المبيعات.)'),
            ];
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e;
        }
    }
}
