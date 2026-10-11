<?php
// app/Services/Inventory/InventoryUploadService.php

namespace App\Services\Inventory;

use App\Enums\RestockingMode;
use App\Imports\CentralInventoryImport;
use App\Imports\SalesImport;
use App\Models\PendingInventoryItem;
use App\Models\TemporarySale;
use App\Models\ImportSession;
use App\Services\Inventory\Import\Matrix\WarehouseMatrixImporter;
use App\Services\Inventory\Support\RecordsImportSession;
use App\Services\Inventory\Support\BuildsReplenishmentSummary;
use App\Enums\ImportFileType;
use App\Services\Shared\ImportHistory\ImportHistoryService;
use App\Services\Shared\ImportHistory\ImportStats;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 🆕 (2026-09، نظام استراتيجيات الترميم) — القلب الفعلي للترميم لم يعد
 * استدعاءً مباشراً لـ ParLevelReplenishmentService؛ أصبح
 * RestockingStrategyResolver يختار الاستراتيجية المناسبة بناءً على
 * restocking_mode المُخزَّن بسجل الجلسة (اختيار المستخدم بالشاشة).
 *
 * 🎯 نقطة حرجة اقتضت إعادة ترتيب الخطوات (لم تكن موجودة قبل هذا التحديث،
 * لأن ملف المبيعات لم يكن يُغذِّي أي محرك ترميم إطلاقاً سابقاً):
 * SalesBasedRestockingStrategy تقرأ temporary_sales مُجمَّعة حسب
 * (pharmacy_id, product_id) — الأعمدة **المُطابَقة** لا الأسماء الخام.
 * لو استدعيناها قبل مطابقة الأسماء (matchAutomatically)، كانت ستُجمِّع
 * صفوفاً بمعرِّفات NULL، منتجة فواتير/نواقص خاطئة بصمت. لذلك الترتيب الآن:
 *
 *   1. استيراد ملف المبيعات (إن وُجد) + مطابقة تلقائية فورية.
 *   2. تنفيذ جزء "المخزون القياسي" فوراً إن كان الوضع يستخدمه — بياناته
 *      (جرد صيدليات + مركزي) لا تحتاج أي مطابقة أسماء إطلاقاً، فلا داعي
 *      لانتظار أي شيء لتنفيذه.
 *   3. فقط لو تبقّت أسماء مبيعات غير مطابَقة **والوضع المختار يحتاج
 *      بيانات المبيعات فعلاً** — نُوقف هنا وننتظر مطابقة يدوية؛ جزء
 *      المخزون القياسي (لو نُفِّذ بالخطوة 2) يبقى كما هو، لا يُعاد ولا
 *      يُلغى. الجزء الوحيد المؤجَّل هو نصف المبيعات تحديداً، ويُستكمَل من
 *      SalesMappingService::saveMapping() بعد انتهاء المطابقة اليدوية.
 *   4. المسار الشائع (لا حاجة لانتظار أي مطابقة، أو الوضع لا يستخدم
 *      المبيعات إطلاقاً): تنفيذ الاستراتيجية الكاملة المختارة دفعة واحدة
 *      عبر RestockingStrategyResolver — الهجين (Hybrid) يبقى **ذرّياً
 *      بمعاملة واحدة** هنا تماماً كما صُمِّم (راجع HybridRestockingStrategy).
 *
 * ⚠️ الاستثناء الوحيد لمبدأ "الهجين معاملة واحدة ذرّية" هو تحديداً حالة
 * الخطوة 3 أعلاه: لا يمكن لأي معاملة قاعدة بيانات "التعليق" بانتظار
 * مطابقة بشرية قد تستغرق دقائق أو ساعات. في تلك الحالة النادرة فقط،
 * الهجين يُنفَّذ كنصفين منفصلين بدل معاملة واحدة — موثَّق صراحة هنا
 * ليست مفاجأة لأي مطوِّر يقرأ الكود لاحقاً.
 */
class InventoryUploadService
{
    use RecordsImportSession, BuildsReplenishmentSummary;

    public function __construct(
        private readonly CentralInventoryStockService $stockService,
        private readonly ShortageFulfillmentService $shortageFulfillment,
        private readonly SalesMappingService $salesMapping,
        private readonly PharmacyInventoryUploadService $pharmacyInventoryUpload,
        private readonly RestockingStrategyResolver $strategyResolver, // 🆕 بديل ParLevelReplenishmentService المباشر
        private readonly CentralInventoryPriceRecorderService $priceRecorder, // 🆕
        private readonly SalesHistoryRecorderService $salesHistoryRecorder, // 🆕
        private readonly WarehouseMatrixImporter $matrixImporter, // 🆕 كشف المستودعات (المصفوفة)
        private readonly ImportHistoryService $importHistory, // 🆕 (أ.1)
        private readonly InvoiceScopeGate $scopeGate, // 🆕 (ب.4)
    ) {}

    /**
     * @param array<string> $pharmacyInvPaths مسارات ملف واحد أو أكثر لجرد الصيدليات (اختياري تماماً)
     * @param string|null $invPath 🆕 اختياري بوضع "بناءً على المبيعات" تحديداً —
     *        غيابه يعني استخدام آخر رصيد مركزي مسجَّل بالفعل كما هو، بلا أي
     *        محاولة تحديثه أو اكتشاف مواد جديدة بهذه الدفعة (ذاك حصراً دور
     *        رفع ملف جرد مركزي فعلي، عبر أي شاشة).
     * @param string|null $matrixPath 🆕 كشف المستودعات (مركزي + كل الصيدليات بملف واحد) — يحل محل
     *        $invPath و$pharmacyInvPaths معاً (الطلب يمنع الجمع بينها). راجع WarehouseMatrixImporter.
     */
    public function processInventoryUpload(string $sessionId, ?string $invPath, ?string $salesPath, array $pharmacyInvPaths = [], ?string $matrixPath = null): void
    {

        $hasSales = (bool) $salesPath;

        try {
            $invImport = null;
            $pricesChanged = 0;

            if ($invPath !== null) {
                $invImport = new CentralInventoryImport();
                Excel::import($invImport, Storage::path($invPath));
                $this->stockService->applyInventoryReplace($invImport->matched);
                $pricesChanged = $this->priceRecorder->recordChanges($invImport->prices); // 🆕

                // 🆕 (أ.1) غير المطابَق يُعدّ متجاهَلاً هنا (يتحول لمسار التأكيد، لم يُكتب رصيده بعد).
                $this->importHistory->completeForSession($sessionId, ImportFileType::CentralInventory, ImportStats::fromCounts(
                    count($invImport->matched),
                    count($invImport->unmatched) + count($invImport->failedUpdates),
                ));
            }

            $affectedPharmacyIds = [];
            $pharmacyInventoryResultMsg = $pricesChanged > 0 ? " 💰 تحديث سعر لـ {$pricesChanged} مادة (جرد مركزي)." : null; // 🆕

            // 🆕 [إصلاح جوهري] صفوف فشل تحديث اسمها/شركتها (راجع CentralInventoryImport
            // للسبب — غالباً نص صيغة Excel خام لم تُحسَب) تظهر الآن صراحة بالرسالة، بدل
            // اختفائها بصمت أو — قبل الإصلاح — إسقاط الملف كله بخطأ قاعدة بيانات خام.
            if ($invImport !== null && !empty($invImport->failedUpdates)) {
                $failedList = collect($invImport->failedUpdates)->keys()->take(15)->implode('، ');
                $more = count($invImport->failedUpdates) - min(15, count($invImport->failedUpdates));
                $pharmacyInventoryResultMsg = ($pharmacyInventoryResultMsg ?? '')
                    . ' ⚠️ ' . count($invImport->failedUpdates) . ' رمز لم يُحدَّث اسمه/شركته (تحقق من الملف — غالباً صيغة Excel خارجية غير محسوبة): '
                    . $failedList . ($more > 0 ? "، و{$more} غيرها" : '') . '.';
            }

            if (!empty($pharmacyInvPaths)) {
                $mergedRows = $this->importAndMergePharmacyInventoryFiles($pharmacyInvPaths, $rowCountsPerFile);

                $pharmacyResult = $this->pharmacyInventoryUpload->process($mergedRows);
                $affectedPharmacyIds = $pharmacyResult['pharmacy_ids']->all();

                // 🆕 (أ.1 + أ.3) إغلاق سجل كل ملف **فور** كتابة اللقطة — جرد الصيدلية يبدأ دورة
                // فائض جديدة، والمناقلات العكسية التي يُنشئها الترميم بعد قليل تُوسَم بها.
                $this->completePharmacyInventoryLogs($sessionId, $rowCountsPerFile, $pharmacyResult['unmatched']->count());

                if ($pharmacyResult['unmatched']->isNotEmpty()) {
                    $pharmacyInventoryResultMsg = " ⚠️ {$pharmacyResult['unmatched']->count()} صف من ملفات جرد الصيدليات لم يُطابَق (باركود غير معروف).";
                }
                if (($pharmacyResult['overrides_applied'] ?? 0) > 0) {
                    $pharmacyInventoryResultMsg = ($pharmacyInventoryResultMsg ?? '')
                        . " ⚙️ تم تحديث {$pharmacyResult['overrides_applied']} تجاوز اختياري (حد أدنى و/أو حجم عبوة) لأزواج (صيدلية+مادة) مُعدَّة مسبقاً بشاشة المخزون القياسي.";
                }
                // 🆕 تنبيه غير حاجب — راجع تعليق PharmacyInventoryUploadService::applyOverrides()
                // للسبب الكامل: تجاوز حجم عبوة يختلف عن افتراضي المادة قد يكون
                // مقصوداً، أو خطأ إملائي بملف صيدلية واحدة — لا نرفض الرفع، فقط
                // نجعل الاختلاف مرئياً بدل أن يبقى صامتاً حتى يُلاحَظ بشحنات غريبة لاحقاً.
                if (!empty($pharmacyResult['pack_size_divergences'])) {
                    $lines = collect($pharmacyResult['pack_size_divergences'])
                        ->map(fn($d) => "{$d['pharmacy']}/{$d['product']}: {$d['override']} (افتراضي المادة: {$d['default']})")
                        ->implode('، ');
                    $pharmacyInventoryResultMsg = ($pharmacyInventoryResultMsg ?? '')
                        . " 🔍 تجاوز حجم عبوة يختلف عن افتراضي المادة — تأكد أنه مقصود: {$lines}.";
                }
            }

            // ══ 🆕 كشف المستودعات: المركزي + كل الصيدليات بمرور واحد ومعاملة واحدة ══
            if ($matrixPath !== null) {
                // 🆕 (أ.4) معرّف سجل هذا الكشف يُمرَّر ليُوسَم به كل مادة ظهرت فيه.
                $matrixLog = $this->importHistory->openSessionLogs($sessionId, ImportFileType::WarehouseMatrix)->last();
                $matrixOutcome = $this->matrixImporter->import(Storage::path($matrixPath), $matrixLog?->id);

                if ($matrixOutcome->needsMapping()) {
                    // لم يُكتب شيء بعد. الملفات تبقى كلها: إعادة التشغيل بعد قرارك تحتاجها (والاستيراد
                    // استبدال كامل فلا خطر من التكرار). شاشة المطابقة تقرأ الرؤوس من الملف نفسه.
                    $count = count($matrixOutcome->headersNeedingMapping);
                    $this->markSession(
                        $sessionId,
                        'needs_mapping',
                        "🏥 {$count} عمود بكشف المستودعات لا يطابق صيدلية معروفة — لم يُكتب أي شيء بعد. حدّد صيدلية كل عمود لإكمال الاستيراد."
                    );

                    return;
                }

                $affectedPharmacyIds = $matrixOutcome->affectedPharmacyIds;
                $pharmacyInventoryResultMsg = ' ' . implode(' ', $matrixOutcome->summaryLines());

                // 🆕 (أ.1 + أ.3) الكشف كُتب ⇒ سجله "ناجح" الآن، لا عند نهاية الجلسة: هو بداية
                // دورة الفائض الجديدة، والترميم أدناه يوسم مناقلاته العكسية بهذه الدورة.
                if ($matrixLog !== null) {
                    $this->importHistory->complete($matrixLog, $matrixOutcome);
                }

                ImportSession::where('session_id', $sessionId)->update([
                    'import_diagnostics' => $matrixOutcome->diagnostics->toArray(),
                ]);

                // مواد مجهولة لها رصيد → نفس مسار التأكيد الموجود (PendingInventoryItem).
                $this->savePendingInventoryItems($sessionId, $matrixOutcome->pendingProducts);
            }

            if ($invImport !== null && !empty($invImport->unmatched)) {
                $this->savePendingInventoryItems($sessionId, $invImport->unmatched);
            }

            if (PendingInventoryItem::where('session_id', $sessionId)->count() > 0) {
                $this->handlePendingProductsBranch($sessionId, $affectedPharmacyIds, $hasSales, $salesPath, $invPath, $pharmacyInvPaths, $matrixPath, $pharmacyInventoryResultMsg);
                return;
            }

            // 🩹 [إصلاح] Model::value() على Eloquent (بخلاف DB::table()->value())
            // لا يزال يُمرِّر عبر الـ Model الكامل داخلياً — أي الـ casts
            // تُطبَّق بالفعل. بما أن restocking_mode مُعرَّف كـ Enum Cast
            // بموديل ImportSession، القيمة العائدة من value() هنا هي بالفعل
            // كائن RestockingMode جاهز، لا نص خام. استدعاء RestockingMode::from()
            // عليها مجدَّداً كان يحاول تحويل Enum إلى Enum فيفشل فوراً — بالضبط
            // الخطأ الذي ظهر. الحل: جلب الجلسة كاملة مرة واحدة (بدل استعلامين
            // منفصلين لعمودين)، واستخدام الدالتين الآمنتين الجاهزتين بالموديل.
            $session = ImportSession::where('session_id', $sessionId)->first();
            $mode = $session->restockingMode();
            $scope = $session->invoiceScope(); // 🆕 (ب.4)
            // 🆕 (2026-09) قائمة دائمة — راجع تعليق InvoiceGenerationService للسبب
            $excludedCompanies = \App\Models\ExcludedCompany::currentList();

            // ══ الخطوة 1: استيراد ومطابقة المبيعات فوراً (إن وُجدت) ══
            if ($hasSales) {
                Excel::import(new SalesImport($sessionId), Storage::path($salesPath));
                $this->salesMapping->matchAutomatically($sessionId);
            }

            // ══ الخطوة 2: هل نحتاج انتظار مطابقة يدوية لجزء المبيعات؟ ══
            $salesNeedsManualMapping = false;
            if ($hasSales && $mode->usesSales()) {
                $unmapped = $this->salesMapping->getUnmappedSales($sessionId);
                $salesNeedsManualMapping = $unmapped['pharmacies']->isNotEmpty() || $unmapped['products']->isNotEmpty();
            }

            if ($salesNeedsManualMapping) {
                // 🆕 فقط بهذا المسار (النادر) نُنفِّذ المخزون القياسي بمعزل
                // عن المبيعات — لأن جزء المبيعات لا يمكن تنفيذه الآن (أسماء
                // غير مطابَقة)، ولا داعي لتعطيل جزء جاهز فعلاً بانتظار جزء
                // غير جاهز. هذا هو الاستثناء الوحيد لمبدأ "الهجين معاملة
                // واحدة ذرّية" الموضَّح بتعليق الكلاس أعلاه.
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

                ImportSession::where('session_id', $sessionId)->update([
                    'has_sales'                        => true,
                    'pharmacy_inventory_pharmacy_ids' => $affectedPharmacyIds,
                ]);

                $partialNote = $standardInventoryResult
                    ? " (نُفِّذ بالفعل جزء المخزون القياسي من هذه الدفعة: {$standardInventoryResult->invoicesCreated} فاتورة، {$standardInventoryResult->shortagesRecorded} نقص مُسجَّل. الجزء المتبقي هو \"المبيعات\" فقط.)"
                    : '';
                $this->markSession(
                    $sessionId,
                    'needs_mapping',
                    'توجد أسماء غير مطابقة بملف المبيعات تحتاج مراجعة يدوية قبل إتمام الترميم بناءً على المبيعات.' . $partialNote
                );

                if ($invPath) {
                    Storage::delete($invPath);
                }
                $this->deletePharmacyInventoryFiles($pharmacyInvPaths);
                $this->deleteMatrixFile($matrixPath); // 🆕
                // 🩹 ملف المبيعات لا يُحذف هنا عمداً — SalesImport بالخطوة 1
                // نسخ بياناته بالفعل إلى temporary_sales، فالملف الخام لم يعد
                // ضرورياً، لكن إبقاؤه حتى اكتمال المطابقة يسهّل أي تشخيص لاحق.
                return;
            }

            // 🆕 تسجيل تاريخي دائم — يحدث دائماً متى وُجدت مبيعات مُطابَقة
            // بهذه النقطة، بصرف النظر عمّا إذا كان أي ترميم فعلي سيحدث لاحقاً
            // أم لا. راجع SalesHistoryRecorderService — هذه نقطة الاستدعاء
            // الوحيدة لهذا المسار (لا يُستدعى مجدَّداً لاحقاً بنفس الجلسة).
            if ($hasSales) {
                $this->salesHistoryRecorder->recordFromSession($sessionId);
            }

            // 🆕 [قرار صريح، مُوسَّع للوضع الرابع] وضع "بناءً على المبيعات" أو
            // "مخزون قياسي مُفعَّل بالمبيعات" بلا ملف جرد مركزي مرفَق بهذه الدفعة
            // (ولا كشف مستودعات) = تسجيل تاريخي فقط (تمّ للتو أعلاه)، بلا أي
            // ترميم أو فاتورة إطلاقاً — كلاهما يحتاج مخزوناً مركزياً ليشحن منه.
            // الوضعان الآخران (مخزون قياسي، مدمج) لا يُطرَح فيهما هذا السؤال
            // أصلاً — ملف الجرد المركزي إلزامي لهما دائماً (UploadSalesAndInventoryRequest).
            $isPureHistoryMode = in_array($mode, [RestockingMode::Sales, RestockingMode::SalesGatedStandardInventory], true);
            if ($hasSales && $isPureHistoryMode && $invPath === null && $matrixPath === null) {
                TemporarySale::forSession($sessionId)->delete();

                $shortageResult = $this->scopeGate->shortages($scope, $excludedCompanies); // 🆕 (ب.4)
                $shortageNote = $shortageResult['fulfilled_count'] > 0 || $shortageResult['partial_count'] > 0
                    ? " كما تم ترميم نواقص قديمة مستقلة تماماً عن هذه الدفعة (لا علاقة لها بملف المبيعات المرفوع الآن): {$shortageResult['fulfilled_count']} بالكامل، {$shortageResult['partial_count']} جزئياً."
                    : '';

                $this->markSession(
                    $sessionId,
                    'completed',
                    '✅ تم تسجيل بيانات المبيعات بنجاح لاستخدامها بتقرير دوران المخزون — بلا أي ترميم أو فاتورة، لأن لا ملف جرد مركزي مرفَق بهذه الدفعة تحديداً.' . $shortageNote . $this->scopeGate->note($scope)
                );

                $this->deletePharmacyInventoryFiles($pharmacyInvPaths);

                $this->deleteMatrixFile($matrixPath); // 🆕
                if ($salesPath) {
                    Storage::delete($salesPath);
                }
                return;
            }

            // ══ الخطوة 3: المسار الشائع — تنفيذ الاستراتيجية الكاملة دفعة
            // واحدة (Hybrid ذرّية بمعاملة واحدة هنا فعلياً، بلا أي انقسام
            // أو تكرار — هذا هو الفرق عن المسار أعلاه) ══
            // 🆕 (ب.4) كل خطوة تمر عبر InvoiceScopeGate — التخطّي يُعيد نتيجة فارغة بنفس الشكل،
            // وشبكة أمان حذف temporary_sales أدناه تبقى تغطي حالة "نواقص فقط" (لم تُستهلك المبيعات).
            $replenishResult = $this->scopeGate->basic($scope, fn () => $this->strategyResolver->resolve($mode)->execute(new RestockingContext(
                mode: $mode,
                pharmacyIds: $affectedPharmacyIds,
                excludedCompanies: $excludedCompanies,
                sessionId: $hasSales ? $sessionId : null,
            )));

            $shortageResult = $this->scopeGate->shortages($scope, $excludedCompanies);

            // 🆕 شبكة أمان: بوضع "مخزون قياسي" الصرف مع ملف مبيعات مرفَق
            // اختيارياً معه (يُسجَّل تاريخياً فقط أعلاه)، لا شيء آخر
            // بهذا المسار يحذف temporary_sales — الاستراتيجية المُشغَّلة
            // هنا (StandardInventoryRestockingStrategy) لا تلمسها إطلاقاً.
            // هذا يضمن حذفها دائماً بلا استثناء، بصرف النظر عن أي استراتيجية
            // شُغِّلت فعلياً (لو حذفتها InvoiceGenerationService بالفعل
            // بمسار المبيعات/الهجين، هذا الاستدعاء يصبح ببساطة بلا أثر).
            if ($hasSales) {
                TemporarySale::forSession($sessionId)->delete();
            }

            $msg = $this->buildReplenishmentSummaryMessage($replenishResult, $shortageResult, $excludedCompanies, $pharmacyInventoryResultMsg);
            $this->markSession($sessionId, 'completed', $msg . $this->scopeGate->note($scope));

            if ($invPath) {
                Storage::delete($invPath);
            }
            if ($salesPath) {
                Storage::delete($salesPath);
            }
            $this->deletePharmacyInventoryFiles($pharmacyInvPaths);
            $this->deleteMatrixFile($matrixPath); // 🆕
        } catch (\Throwable $e) {
            // 🩹 [إصلاح احترازي] كان \Exception فقط — لا يلتقط \Error (TypeError
            // وأخواتها). Laravel يلتقط \Throwable الهارب من handle() عند مستوى
            // الطابور فعلياً (راجع ProcessInventoryUploadJob::failed()) فتصل
            // الجلسة لحالة 'failed' برسالة في الغالب حتى بلا هذا الإصلاح — لكن
            // بلا هذا الالتقاط المحلي، يُفقَد أيضاً التنظيف المحلي هنا (حذف
            // temporary_sales/PendingInventoryItem وRollback أي معاملة مفتوحة)
            // قبل أن يصل الخطأ لذلك المستوى الأعلى. التقاطه هنا أوسع وأكثر أماناً.
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            TemporarySale::forSession($sessionId)->delete();
            PendingInventoryItem::where('session_id', $sessionId)->delete();

            Log::error("Inventory upload failed [{$sessionId}]: " . $e->getMessage());
            $this->markSession($sessionId, 'failed', 'حدث خطأ: ' . $e->getMessage());
        }
    }

    /**
     * 🆕 يستورد كل ملف جرد صيدليات على حدة (كل ملف يُقرأ مُجزَّأً عبر
     * WithChunkReading الخاص بـ PharmacyInventoryImport — بلا تحميل كل
     * الملفات الخام معاً بالذاكرة دفعة واحدة)، ثم يدمج صفوفها المُقروءة
     * فقط بـ Collection واحدة نهائية.
     *
     * @param array<string> $paths
     * @param list<int>|null $rowCounts 🆕 (أ.1) يُملأ بعدد صفوف كل ملف بنفس ترتيب $paths
     * @return Collection
     */
    private function importAndMergePharmacyInventoryFiles(array $paths, ?array &$rowCounts = null): Collection
    {
        $merged = collect();
        $rowCounts = [];

        foreach ($paths as $path) {
            $pharmacyImport = new \App\Imports\PharmacyInventoryImport();
            Excel::import($pharmacyImport, Storage::path($path));
            $merged = $merged->concat($pharmacyImport->rows);
            $rowCounts[] = $pharmacyImport->rows->count();
        }

        return $merged;
    }

    /**
     * 🆕 (أ.1) عدد صفوف كل ملف معروف (بنفس ترتيب سجلاته — راجع beginSessionImportLogs
     * بالكونترولر)، أما غير المطابَق فإجمالي للدفعة (الملفات دُمجت قبل المطابقة) — يُذكر
     * برسالة كل سجل بدل توزيعه اعتباطياً على الملفات.
     *
     * @param list<int> $rowCountsPerFile
     */
    private function completePharmacyInventoryLogs(string $sessionId, array $rowCountsPerFile, int $unmatchedRows): void
    {
        $note = $unmatchedRows > 0 ? "⚠️ {$unmatchedRows} صف غير مطابَق (باركود غير معروف) بمجمل ملفات جرد الصيدليات بهذه الدفعة." : null;

        foreach ($this->importHistory->openSessionLogs($sessionId, ImportFileType::PharmacyInventory)->values() as $index => $log) {
            $this->importHistory->complete($log, new ImportStats(
                succeeded: $rowCountsPerFile[$index] ?? 0,
                skipped: 0,
                warned: $unmatchedRows,
                message: $note,
            ));
        }
    }

    /** 🆕 يُحذف الملف عند اكتمال المسار فقط — عند التوقف لمطابقة صيدلية يبقى لإعادة التشغيل. */
    private function deleteMatrixFile(?string $path): void
    {
        if ($path !== null) {
            Storage::delete($path);
        }
    }

    private function deletePharmacyInventoryFiles(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::delete($path);
        }
    }

    private function savePendingInventoryItems(string $sessionId, array $unmatched): void
    {
        foreach ($unmatched as $item) {
            PendingInventoryItem::create([
                'session_id'    => $sessionId,
                'barcode'       => $item['barcode'],
                'material_name' => $item['material_name'],
                'company'       => $item['company'],
                'quantity'      => $item['quantity'],
                // 🆕 يُرحَّل من CentralInventoryImport إن ذُكر بالملف، وإلا
                // يبقى NULL هنا (PendingProductService يتراجع لـ 0 لاحقاً).
                'pack_size'     => $item['pack_size'] ?? null,
                // 🆕 نفس منطق pack_size بالضبط — راجع مايجريشن 2026_09_05_000002
                'price'         => $item['price'] ?? null,
            ]);
        }
    }

    /**
     * @param array<string> $pharmacyInvPaths
     * @param string|null $invPath 🩹 مضمون عدم كونه null بمسار الاستدعاء
     *        الحالي فعلياً (الأصناف المعلَّقة مصدرها الوحيد استيراد جرد
     *        مركزي حقيقي) — لكن يبقى Nullable هنا دفاعاً عن أي تغيير
     *        مستقبلي بمنطق الاستدعاء لا يُلاحَظ فوراً.
     */
    private function handlePendingProductsBranch(
        string $sessionId,
        array $affectedPharmacyIds, // 🆕
        bool $hasSales,
        ?string $salesPath,
        ?string $invPath,
        array $pharmacyInvPaths = [],
        ?string $matrixPath = null, // 🆕
        ?string $note = null // 🆕 ملخّص كشف المستودعات (يُلحَق برسالة الحالة)
    ): void {
        ImportSession::where('session_id', $sessionId)->update([
            'has_sales'                        => $hasSales,
            'pharmacy_inventory_pharmacy_ids' => $affectedPharmacyIds, // 🆕 يُستكمَل لاحقاً من PendingProductService
        ]);

        if ($hasSales) {
            Excel::import(new SalesImport($sessionId), Storage::path($salesPath));
        }

        $pendingCount = PendingInventoryItem::where('session_id', $sessionId)->count();
        $this->markSession($sessionId, 'needs_pending_products', "تم العثور على {$pendingCount} مادة جديدة بملف الجرد." . ($note ?? ''));

        if ($invPath) {
            Storage::delete($invPath);
        }
        if ($salesPath) {
            Storage::delete($salesPath);
        }
        $this->deleteMatrixFile($matrixPath); // 🆕
        // 🩹 ملفات جرد الصيدليات لا تُحذَف هنا عمداً — تمت معالجتها بالفعل قبل هذا الفرع.
    }
}
