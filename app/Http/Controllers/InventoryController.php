<?php

namespace App\Http\Controllers;

use App\Exports\AllInvoicesExport;
use App\Exports\InvoiceExport;
use App\Exports\PreparationExport;
use App\Exports\PharmacyTransferExport;
use App\Exports\ReverseTransferExport;
use App\Exports\ReverseTransfersExport;
use App\Exports\ShortagesExport;
use App\Http\Requests\Inventory\ImportShortagesFileRequest;
use App\Http\Requests\Inventory\InvoicesIndexFilterRequest;
use App\Http\Requests\Inventory\SaveMappingRequest;
use App\Http\Requests\Inventory\SaveShortagesMappingRequest;
use App\Http\Requests\Inventory\ShortagesDashboardFilterRequest;
use App\Http\Requests\Inventory\UpdateShortageNoteRequest;
use App\Http\Requests\Inventory\UploadInventoryStockRequest;
use App\Http\Requests\Inventory\UploadMaterialMasterRequest;
use App\Http\Requests\Inventory\UploadSalesAndInventoryRequest;
use App\Http\Requests\Inventory\UploadWatchedProductsRequest;
use App\Imports\InventoryCompanyPeekImport;
use App\Imports\WatchedProductsImport;
use App\Jobs\ProcessInventoryStockUploadJob;
use App\Jobs\ProcessInventoryUploadJob;
use App\Jobs\ProcessSaveMappingJob;
use App\Jobs\ProcessSaveShortagesMappingJob;
use App\Jobs\ProcessShortagesImportJob;
use App\Models\ImportSession;
use App\Models\Invoice;
use App\Models\Pharmacy;
use App\Models\PendingInventoryItem;
use App\Models\Product;
use App\Models\PharmacyTransfer;
use App\Models\ReverseTransfer;
use App\Models\ReverseTransferItem;
use App\Models\Shortage;
use App\Models\WatchedProduct;
use App\Services\Inventory\Import\MaterialMaster\MaterialMasterImporter;
use App\Services\Inventory\Import\Matrix\WarehouseMatrixImporter;
use App\Services\Inventory\PendingProductService;
use App\Services\Inventory\PharmacyMappingRecorder;
use App\Services\Inventory\SalesMappingService;
use App\Services\Inventory\ShortageFulfillmentService;
use App\Services\Inventory\ShortageImportService;
use App\Services\Shared\Export\SafeExportFilename;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 🆕 (2026-08، الجولة الرابعة) upload() يستقبل الآن **مصفوفة** ملفات
 * جرد صيدليات اختيارية (pharmacy_inventory_files[]) بدل ملف واحد —
 * يدعم رفع عدة صيدليات معاً بنفس الدفعة الواحدة. راجع InventoryUploadService
 * لتفاصيل الدمج الفعلي لصفوف كل الملفات بذاكرة واحدة قبل المعالجة.
 *
 * 🆕 (2026-09، نظام استراتيجيات الترميم) upload() يستقبل الآن أيضاً
 * restocking_mode (sales / standard_inventory / hybrid) ويُخزِّنه على
 * سجل الجلسة نفسه — لا حاجة لتمريره صراحة بأي مكان آخر بهذا الكونترولر؛
 * كل فرع لاحق (تأكيد استثناء الشركات، معالجة الخلفية) يقرأه من الجلسة
 * وقت الحاجة (راجع InventoryUploadService::processInventoryUpload()).
 */
class InventoryController extends Controller
{
    public function __construct(
        private readonly PendingProductService $pendingProductService,
        private readonly SalesMappingService $salesMappingService,
        private readonly ShortageFulfillmentService $shortageFulfillmentService,
        private readonly ShortageImportService $shortageImportService,
        private readonly WarehouseMatrixImporter $matrixImporter, // 🆕
        private readonly PharmacyMappingRecorder $pharmacyMappingRecorder, // 🆕
        private readonly MaterialMasterImporter $materialMasterImporter, // 🆕
        private readonly \App\Services\Inventory\PharmacyTransferSuggestionService $transferSuggestionService, // 🆕
        private readonly \App\Services\Inventory\PharmacyTransferExecutionService $transferExecutionService, // 🆕
        private readonly \App\Services\Shared\ImportHistory\ImportHistoryService $importHistory, // 🆕 (أ.1)
        private readonly \App\Services\Inventory\Transfers\PendingTransfersQuery $pendingTransfers, // 🆕 (ب.6/ب.7)
        private readonly \App\Services\Inventory\Surplus\InventoryCycleResolver $cycleResolver, // 🆕 (أ.3)
        private readonly \App\Services\Inventory\InvoiceEditing\InvoiceExportLock $exportLock, // 🆕 (ب.2)
    ) {}

    // ══════════════════════════════════════════════════════════
    // 1) رفع المبيعات والجرد اليومي (+ جرد الصيدليات الاختياري — يدعم عدة ملفات معاً)
    // ══════════════════════════════════════════════════════════

    public function showUploadForm()
    {
        return view('inventory.upload');
    }

    public function upload(UploadSalesAndInventoryRequest $request)
    {
        $sessionId = uniqid('session_');
        // 🆕 أصبح اختيارياً بوضع "بناءً على المبيعات" — راجع
        // UploadSalesAndInventoryRequest للسبب الكامل.
        $invPath   = $request->hasFile('inventory_file') ? $request->file('inventory_file')->store('imports') : null;
        $salesPath = $request->hasFile('sales_file') ? $request->file('sales_file')->store('imports') : null;
        // 🆕 كشف المستودعات (مركزي + كل الصيدليات بملف واحد). يبقى ملفه حتى اكتمال الجلسة.
        $matrixPath = $request->hasFile('warehouse_matrix_file') ? $request->file('warehouse_matrix_file')->store('imports') : null;

        // 🆕 دعم رفع عدة ملفات جرد صيدليات معاً بنفس الدفعة — كل ملف
        // يُخزَّن على حدة، ومساراتها تُحفَظ كمصفوفة JSON بسجل الجلسة.
        // الدمج الفعلي لصفوفها كلها يحصل لاحقاً بالخلفية (Job واحد).
        $pharmacyInvPaths = collect($request->file('pharmacy_inventory_files', []))
            ->map(fn($file) => $file->store('imports'))
            ->values()
            ->all();

        ImportSession::create([
            'session_id'                        => $sessionId,
            'type'                                => 'sales_and_inventory',
            'status'                              => 'processing',
            'restocking_mode'                    => $request->restockingMode()->value, // 🆕
            'inventory_file_path'               => $invPath,
            'sales_file_path'                    => $salesPath,
            'pharmacy_inventory_file_paths'     => $pharmacyInvPaths, // 🆕
            'warehouse_matrix_file_path'        => $matrixPath, // 🆕
            'invoice_scope'                      => $request->invoiceScope()->value, // 🆕 (ب.4)
        ]);

        $this->beginSessionImportLogs($request, $sessionId);

        // 🩹 [إزالة 2026-09] لم تعد هناك شاشة "استثناء شركات" لكل دفعة —
        // الاستثناء أصبح قائمة دائمة (ExcludedCompany) تُدار من شاشتها
        // الخاصة، وتُطبَّق تلقائياً على أي رفع بلا أي خطوة تأكيد إضافية
        // هنا. راجع SalesImport/PharmacyInventoryImport (الفلترة الفعلية
        // تحدث هناك، عند الاستيراد مباشرة) وشاشة إدارة الاستثناء الجديدة.
        return $this->dispatchProcessing($sessionId, $invPath, $salesPath, $pharmacyInvPaths, $matrixPath);
    }

    /**
     * 🆕 (أ.1) سطر بسجل الرفع لكل ملف بهذه الدفعة — هنا تحديداً لأن الاسم الأصلي ومن
     * رفعه لا يتوفران إلا بطبقة HTTP. الإغلاق يحدث لاحقاً بالخلفية (InventoryUploadService
     * + RecordsImportSession) عبر session_id. ترتيب إنشاء سجلات جرد الصيدليات = ترتيب
     * الملفات نفسها، وتعتمد عليه الخدمة لإغلاق كل سجل بعدّاد ملفه.
     */
    private function beginSessionImportLogs(UploadSalesAndInventoryRequest $request, string $sessionId): void
    {
        $files = [
            'warehouse_matrix_file' => \App\Enums\ImportFileType::WarehouseMatrix,
            'inventory_file' => \App\Enums\ImportFileType::CentralInventory,
            'sales_file' => \App\Enums\ImportFileType::Sales,
        ];

        foreach ($files as $field => $type) {
            if ($request->hasFile($field)) {
                $this->importHistory->begin($type, $request->file($field), $sessionId);
            }
        }

        foreach ($request->file('pharmacy_inventory_files', []) as $file) {
            $this->importHistory->begin(\App\Enums\ImportFileType::PharmacyInventory, $file, $sessionId);
        }
    }

    /**
     * @param array<string> $pharmacyInvPaths
     */
    private function dispatchProcessing(string $sessionId, ?string $invPath, ?string $salesPath, array $pharmacyInvPaths = [], ?string $matrixPath = null)
    {
        ImportSession::where('session_id', $sessionId)->update(['status' => 'processing']);

        // 🆕 معامل رابع أصبح مصفوفة بدل مسار واحد
        ProcessInventoryUploadJob::dispatch($sessionId, $invPath, $salesPath, $pharmacyInvPaths, $matrixPath);

        return view('inventory.processing', [
            'sessionId'   => $sessionId,
            'title'       => 'جاري معالجة ملفات الجرد والمبيعات',
            'statusRoute' => route('inventory.import.status', $sessionId),
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // 2) جرد المستودع المركزي (شاشة منفصلة) — بلا تعديل
    // ══════════════════════════════════════════════════════════

    public function showInventoryUploadForm()
    {
        return view('inventory.upload-inventory');
    }

    public function uploadInventory(UploadInventoryStockRequest $request)
    {
        $sessionId = uniqid('session_');
        $invPath   = $request->file('inventory_file')->store('imports');

        ImportSession::create(['session_id' => $sessionId, 'type' => 'stock_only', 'status' => 'processing']);
        $this->importHistory->begin(\App\Enums\ImportFileType::CentralInventory, $request->file('inventory_file'), $sessionId); // 🆕 (أ.1)

        ProcessInventoryStockUploadJob::dispatch($sessionId, $invPath);

        return view('inventory.processing', [
            'sessionId'      => $sessionId,
            'title'          => 'جاري تحديث جرد المستودع المركزي',
            'statusRoute'    => route('inventory.import.status', $sessionId),
            'redirectOnDone' => route('inventory.stock.upload.form'),
        ]);
    }

    public function importStatus(string $sessionId)
    {
        $session = ImportSession::where('session_id', $sessionId)->firstOrFail();

        return response()->json([
            'status'       => $session->status,
            'message'      => $session->message,
            'redirect_url' => $session->resolveRedirectUrl(),
        ]);
    }

    public function showPendingInventory(string $sessionId)
    {
        $count   = PendingInventoryItem::where('session_id', $sessionId)->count();
        $session = ImportSession::where('session_id', $sessionId)->firstOrFail();

        if ($count === 0) {
            return redirect()->route('inventory.upload.form');
        }

        return view('inventory.pending-inventory', [
            'sessionId' => $sessionId,
            'count'     => $count,
            'hasSales'  => $session->has_sales,
        ]);
    }

    public function createPendingProducts(Request $request)
    {
        $sessionId = $request->session_id;

        try {
            $result = $this->pendingProductService->createPendingProducts($sessionId);
        } catch (\Exception $e) {
            return back()->with('error', 'حدث خطأ: ' . $e->getMessage());
        }

        if ($result['needs_mapping'] ?? false) {
            return view('inventory.mapping', [
                'sessionId'          => $sessionId,
                'unmappedPharmacies' => $result['pharmacies'],
                'unmappedProducts'   => $result['products'],
                'existingPharmacies' => Pharmacy::orderBy('name')->get(),
            ]);
        }

        return redirect()->route('inventory.upload.form')->with('success', $result['message']);
    }

    public function showMapping(string $sessionId)
    {
        // 🆕 جلسة كشف المستودعات: الأسماء المجهولة أعمدة بالملف نفسه (لا صفوف temporary_sales).
        // الشاشة نفسها تُستخدَم — تعرض الصيدليات فقط (لا مواد) — والقائمة تُشتقّ من الملف الآن،
        // فمطابقة حُفظت من مكان آخر تختفي من القائمة تلقائياً.
        $session = ImportSession::where('session_id', $sessionId)->first();
        if ($session?->isMatrixSession()) {
            $path = $session->warehouse_matrix_file_path;
            $headers = Storage::exists($path) ? $this->matrixImporter->pharmacyHeadersNeedingMapping(Storage::path($path)) : [];

            if ($headers !== []) {
                return view('inventory.mapping', [
                    'sessionId'          => $sessionId,
                    'unmappedPharmacies' => collect($headers),
                    'unmappedProducts'   => collect(),
                    'existingPharmacies' => Pharmacy::orderBy('name')->get(),
                ]);
            }

            // 🩹 [إصلاح جوهري] كان يُعيد التوجيه لشاشة الرفع فوراً هنا (بلا أي
            // رسالة) بمجرد أن كشف المستودعات نفسه سليماً — بافتراض ضمني خاطئ
            // أن "جلسة كشف مستودعات + needs_mapping" تعني بالضرورة "أعمدة كشف
            // المستودعات هي السبب". لكن نفس الدفعة قد تحوي **ملف مبيعات أيضاً**
            // بأسماء غير مطابَقة — وهذا بالضبط ما كان يحدث: كشف المستودعات كان
            // سليماً 100%، فتُعيد الدالة توجيه المستخدم لشاشة الرفع صامتة، تاركة
            // مشكلة المبيعات الحقيقية غير معروضة إطلاقاً ("جار الرفع ثم يعيدني
            // لصفحة الرفع دون أي رسالة" — بالضبط الأعراض المُبلَّغ عنها). الحل:
            // المتابعة للفحص التالي (أسماء المبيعات) بدل افتراض عدم وجود شيء
            // يحتاج مطابقة لمجرد أن كشف المستودعات تحديداً لا يحتاجها.
        }

        $unmapped = $this->salesMappingService->getUnmappedSales($sessionId);

        if ($unmapped['pharmacies']->isEmpty() && $unmapped['products']->isEmpty()) {
            return redirect()->route('inventory.upload.form');
        }

        return view('inventory.mapping', [
            'sessionId'          => $sessionId,
            'unmappedPharmacies' => $unmapped['pharmacies'],
            'unmappedProducts'   => $unmapped['products'],
            'existingPharmacies' => Pharmacy::orderBy('name')->get(),
        ]);
    }

    public function saveMapping(SaveMappingRequest $request)
    {
        if (!$request->hasAnyMappingData()) {
            return redirect()->route('inventory.upload.form')->with('error', 'لم يتم إرسال أي بيانات للمطابقة.');
        }

        // 🆕 جلسة كشف المستودعات: تُحفَظ قرارات الصيدليات (سريعة: بضعة صفوف) ثم يُعاد تشغيل الاستيراد
        // كاملاً بالملف المحفوظ — الاستبدال الكامل يجعل الإعادة آمنة (Idempotent).
        $matrixSession = ImportSession::where('session_id', $request->sessionId())->first();

        // 🩹 [إصلاح جوهري، يقابل نفس الإصلاح بـ showMapping() أعلاه بالضبط] كان
        // isMatrixSession() وحدها تكفي لافتراض "هذا الإرسال بالتأكيد مطابقة
        // أعمدة كشف المستودعات" — خطأ حين يكون كشف المستودعات نفسه سليماً
        // أصلاً (لم يحتج مطابقة قط) والمطابقة المُرسَلة فعلياً لأسماء مبيعات.
        // بلا هذا الفحص، كانت الأسماء المُطابَقة يدوياً بشاشة المبيعات (عبر
        // showMapping() بعد إصلاحه) ستُمرَّر خطأً لـ pharmacyMappingRecorder
        // (الذي يتوقع صيغة مختلفة تماماً)، فيُخفق بصمت أو يُسجِّل بيانات خاطئة.
        $stillNeedsMatrixMapping = $matrixSession?->isMatrixSession()
            && Storage::exists($matrixSession->warehouse_matrix_file_path)
            && $this->matrixImporter->pharmacyHeadersNeedingMapping(Storage::path($matrixSession->warehouse_matrix_file_path)) !== [];

        if ($stillNeedsMatrixMapping) {
            if ($this->pharmacyMappingRecorder->record($request->mappedPharmacies()) === 0) {
                return redirect()->route('inventory.mapping.show', $matrixSession->session_id)
                    ->with('error', 'لم تُحدَّد أي مطابقة — اختر صيدلية (أو «جديدة») لكل عمود.');
            }

            return $this->dispatchProcessing(
                $matrixSession->session_id,
                $matrixSession->inventory_file_path,
                $matrixSession->sales_file_path,
                $matrixSession->pharmacyInventoryFilePaths(),
                $matrixSession->warehouse_matrix_file_path,
            );
        }

        ImportSession::where('session_id', $request->sessionId())->update(['status' => 'processing', 'message' => null]);

        ProcessSaveMappingJob::dispatch($request->sessionId(), $request->mappedPharmacies(), $request->mappedProducts());

        return view('inventory.processing', [
            'sessionId'      => $request->sessionId(),
            'title'          => 'جاري حفظ المطابقات',
            'statusRoute'    => route('inventory.import.status', $request->sessionId()),
            'redirectOnDone' => route('inventory.upload.form'),
        ]);
    }

    public function showShortagesImportForm()
    {
        return view('inventory.import-shortages');
    }

    public function importShortages(ImportShortagesFileRequest $request)
    {
        $sessionId = uniqid('shortage_session_');
        $filePath  = $request->file('shortages_file')->store('imports');

        ImportSession::create(['session_id' => $sessionId, 'type' => 'shortages', 'status' => 'processing']);
        $this->importHistory->begin(\App\Enums\ImportFileType::Shortages, $request->file('shortages_file'), $sessionId); // 🆕 (أ.1)

        ProcessShortagesImportJob::dispatch($sessionId, $filePath);

        return view('inventory.processing', [
            'sessionId'      => $sessionId,
            'title'          => 'جاري قراءة ومطابقة ملف النواقص',
            'statusRoute'    => route('inventory.import.status', $sessionId),
            'redirectOnDone' => route('inventory.shortages.dashboard'),
        ]);
    }

    public function showShortagesMapping(string $sessionId)
    {
        $unmapped = $this->shortageImportService->getUnmappedShortages($sessionId);

        if ($unmapped['pharmacies']->isEmpty() && $unmapped['products']->isEmpty()) {
            return redirect()->route('inventory.shortages.dashboard');
        }

        return view('inventory.mapping-shortages', [
            'sessionId'          => $sessionId,
            'unmappedPharmacies' => $unmapped['pharmacies'],
            'unmappedProducts'   => $unmapped['products'],
            'existingPharmacies' => Pharmacy::orderBy('name')->get(),
        ]);
    }

    public function saveShortagesMapping(SaveShortagesMappingRequest $request)
    {
        ImportSession::where('session_id', $request->sessionId())->update(['status' => 'processing', 'message' => null]);

        ProcessSaveShortagesMappingJob::dispatch($request->sessionId(), $request->mappedPharmacies(), $request->mappedProducts());

        return view('inventory.processing', [
            'sessionId'      => $request->sessionId(),
            'title'          => 'جاري حفظ المطابقات وتوليد سجلات النواقص',
            'statusRoute'    => route('inventory.import.status', $request->sessionId()),
            'redirectOnDone' => route('inventory.shortages.dashboard'),
        ]);
    }

    public function fulfillShortages()
    {
        try {
            // 🆕 (2026-09) [الثغرة الأصلية] كانت تُستدعى بلا وسائط إطلاقاً —
            // زر يدوي منفصل عن أي دفعة رفع، فلم يكن له أي مصدر استثناء
            // ليقرأ منه بالتصميم القديم (خاص بكل جلسة). الآن، القائمة دائمة
            // (ExcludedCompany)، فكل استدعاء — بما فيه هذا الزر اليدوي —
            // يقرأ نفس المصدر الوحيد، بلا استثناء.
            $result = $this->shortageFulfillmentService->fulfillActiveShortages(\App\Models\ExcludedCompany::currentList());
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            return back()->with('error', 'حدث خطأ أثناء معالجة النواقص: ' . $e->getMessage());
        }

        if ($result['fulfilled_count'] === 0 && $result['partial_count'] === 0) {
            return back()->with('info', 'لا توجد نواقص يمكن ترميمها من الرصيد المتوفر حالياً.');
        }

        $msg = "🎉 تم ترميم {$result['fulfilled_count']} نقص بالكامل، و {$result['partial_count']} نقص جزئياً.";
        if (($result['blocked_by_minimum_count'] ?? 0) > 0) {
            $msg .= " ⚠️ تم تجاوز {$result['blocked_by_minimum_count']} نقص لأن رصيد الصيدلية لا يزال أعلى من حدها الأدنى المحدَّد.";
        }

        return back()->with('success', $msg);
    }

    public function invoicesIndex(InvoicesIndexFilterRequest $request)
    {
        $filters = $request->filters();

        $invoices = Invoice::with('pharmacy')
            ->filter($filters)
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        return view('inventory.invoices-index', [
            'invoices'    => $invoices,
            'pharmacies'  => Pharmacy::orderBy('name')->get(),
            'currentDate' => $filters['date'],
        ]);
    }

    public function showInvoice(Request $request, $id)
    {
        $invoice = Invoice::with(['pharmacy', 'items.product'])->findOrFail($id);

        $items = $invoice->items()->with('product')->availability($request->input('availability'))->get();

        // 🆕 (ب.2) سجل تعديلات هذه الفاتورة (المقترح ↔ النهائي) — بما فيها البنود المحذوفة.
        $editHistory = \App\Models\AuditLog::query()
            ->whereIn('action', [
                \App\Enums\AuditAction::InvoiceItemQuantityChanged,
                \App\Enums\AuditAction::InvoiceItemRemoved,
                \App\Enums\AuditAction::InvoiceItemAdded,
            ])
            ->whereContext('invoice_id', $invoice->id)
            ->with('user:id,name')
            ->latest('id')
            ->get();

        return view('inventory.invoice-details', compact('invoice', 'items', 'editHistory'));
    }

    public function exportInvoiceExcel(Request $request, $id)
    {
        $invoice      = Invoice::findOrFail($id);
        $availability = $request->input('availability');

        $suffix = match ($availability) {
            'available' => '-متوفر',
            'shortage'  => '-عجز',
            default     => '',
        };

        $fileName = SafeExportFilename::sanitize('فاتورة-' . $invoice->id . '-' . $invoice->pharmacy->name . $suffix . '.xlsx');

        $this->exportLock->lockOne($invoice); // 🆕 (ب.2) أول تصدير يقفل التعديل

        return Excel::download(new InvoiceExport($invoice->id, $availability), $fileName);
    }

    public function exportAllInvoices(Request $request)
    {
        $filters = $request->only(['status', 'pharmacy_id', 'availability', 'date']);
        $filters['date'] = $filters['date'] ?? now()->toDateString();

        $suffix = '';
        if (!empty($filters['status'])) {
            $suffix .= '-' . ($filters['status'] === 'processed' ? 'أساسية' : 'إلحاقية');
        }
        if (!empty($filters['availability'])) {
            $suffix .= '-' . ($filters['availability'] === 'shortage' ? 'عجز' : 'متوفر');
        }
        $suffix .= '-' . $filters['date'];

        $this->exportLock->lockMatching($filters); // 🆕 (ب.2)

        return Excel::download(new AllInvoicesExport($filters), 'فواتير-المناقلة' . $suffix . '.xlsx');
    }

    /**
     * 🆕 "كشف تجهيز الطلبيات" — راجع PreparationExport للشرح الكامل.
     * منفصل عمداً عن exportAllInvoices() أعلاه: ذاك تصدير محاسبي/تدقيقي
     * بمستوى الفاتورة، هذا تصدير تشغيلي مُجمَّع بمستوى (صيدلية+مادة).
     */
    public function exportPreparationList(Request $request)
    {
        $date = $request->input('date', now()->toDateString());
        $pharmacyId = $request->input('pharmacy_id');

        // 🩹 [إصلاح جوهري] كانت status/availability تُقرَآن من الطلب لكن لا
        // تُمرَّران للتصدير إطلاقاً — فلترة الشاشة (حالة التوفر، تصنيف الفاتورة)
        // تُعرَض بجدول invoices-index.blade.php الصحيح، لكن "كشف تجهيز" كان
        // يتجاهلها دائماً ويُصدِّر كل شيء مطابق للتاريخ/الصيدلية فقط، بصرف
        // النظر عمّا اختاره المستخدم فعلياً بالشاشة. الآن تُمرَّر بنفس الاسم
        // تماماً المُستخدَم أصلاً بـ exportAllInvoices() المجاورة أدناه.
        $this->exportLock->lockMatching(['date' => $date, 'pharmacy_id' => $pharmacyId, 'status' => $request->input('status') ?: null]); // 🆕 (ب.2)

        return Excel::download(
            new PreparationExport(
                date: $date,
                pharmacyId: $pharmacyId ? (int) $pharmacyId : null,
                status: $request->input('status') ?: null,
                availability: $request->input('availability') ?: null,
            ),
            'كشف-تجهيز-الطلبيات-' . $date . '.xlsx'
        );
    }

    public function searchProducts(Request $request)
    {
        $products = Product::select('id', 'name', 'company')
            ->search($request->input('q', ''))
            ->limit(30)
            ->get()
            ->map(fn($p) => $p->toSelect2Option());

        return response()->json(['results' => $products]);
    }

    public function searchPharmacies(Request $request)
    {
        $pharmacies = Pharmacy::select('id', 'name')
            ->search($request->input('q', ''))
            ->limit(30)
            ->get()
            ->map(fn($p) => $p->toSelect2Option());

        return response()->json(['results' => $pharmacies]);
    }

    public function shortagesDashboard(ShortagesDashboardFilterRequest $request)
    {
        $filters = $request->filters();

        $shortages = Shortage::filter($filters)
            ->with(['pharmacy', 'product'])
            ->orderBy('created_at', 'asc')
            ->paginate(50)
            ->withQueryString();

        $stats = [
            'unique_products'     => Shortage::filter($filters)->distinct('product_id')->count('product_id'),
            'total_quantity'      => Shortage::filter($filters)->sum('quantity'),
            'affected_pharmacies' => Shortage::filter($filters)->distinct('pharmacy_id')->count('pharmacy_id'),
        ];

        $summary = Shortage::pending()
            ->forPharmacy($filters['pharmacy_id'] ?? null)
            ->createdFrom($filters['date_from'] ?? null)
            ->createdTo($filters['date_to'] ?? null)
            ->with('product')
            ->select('product_id', DB::raw('SUM(quantity) as total'))
            ->groupBy('product_id')
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        $pharmacies = Pharmacy::orderBy('name')->get();

        return view('inventory.shortages', compact('shortages', 'pharmacies', 'summary', 'stats'));
    }

    public function resolveShortage(UpdateShortageNoteRequest $request, $id)
    {
        Shortage::findOrFail($id)->update([
            'status'   => 'fulfilled',
            'quantity' => 0,
            'notes'    => $request->input('notes', 'تم الحل يدوياً'),
        ]);

        return back()->with('success', 'تم تعليم النقص كمحلول.');
    }

    public function updateShortageNote(UpdateShortageNoteRequest $request, $id)
    {
        Shortage::findOrFail($id)->update(['notes' => $request->validated('notes')]);
        return back()->with('success', 'تم حفظ الملاحظة.');
    }

    public function exportShortages(ShortagesDashboardFilterRequest $request)
    {
        return Excel::download(
            new ShortagesExport($request->filters()),
            'تقرير-النواقص-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    // ══════════════════════════════════════════════════════════
    // 🆕 المناقلات العكسية — صيدليات تتجاوز مخزونها القياسي
    // ══════════════════════════════════════════════════════════

    public function reverseTransfersReport(Request $request)
    {
        $filters = $request->only(['pharmacy_id', 'date_from', 'date_to']);
        $filters = array_filter($filters, fn($v) => $v !== null && $v !== '');

        $items = ReverseTransferItem::filter($filters)
            ->with(['reverseTransfer.pharmacy', 'product'])
            ->join('reverse_transfers', 'reverse_transfers.id', '=', 'reverse_transfer_items.reverse_transfer_id')
            ->orderByRaw(\App\Models\ReverseTransfer::MOVEMENT_DATE_SQL.' DESC')
            ->select('reverse_transfer_items.*')
            ->paginate(50)
            ->withQueryString();

        $stats = [
            'total_quantity'      => ReverseTransferItem::filter($filters)->sum('quantity'),
            'unique_products'     => ReverseTransferItem::filter($filters)->distinct('product_id')->count('product_id'),
            'affected_pharmacies' => ReverseTransferItem::filter($filters)
                ->join('reverse_transfers as rt_stats', 'rt_stats.id', '=', 'reverse_transfer_items.reverse_transfer_id')
                ->distinct('rt_stats.pharmacy_id')->count('rt_stats.pharmacy_id'),
        ];

        $pharmacies = Pharmacy::orderBy('name')->get();

        // 🆕 (ب.6) مقترحات الدورة الحالية بانتظار "تنفيذ" — مُعلَّمة بتغطية الفائض الحالية.
        $pending = $this->pendingTransfers
            ->reverseTransfers(isset($filters['pharmacy_id']) ? (int) $filters['pharmacy_id'] : null);
        $cycle = $this->cycleResolver->current();

        return view('inventory.reverse-transfers', compact('items', 'stats', 'pharmacies', 'pending', 'cycle'));
    }

    public function exportReverseTransfers(Request $request)
    {
        $filters = $request->only(['pharmacy_id', 'date_from', 'date_to']);
        $filters = array_filter($filters, fn($v) => $v !== null && $v !== '');

        return Excel::download(
            new ReverseTransfersExport($filters),
            'تقرير-المناقلات-العكسية-' . now()->format('Y-m-d') . '.xlsx'
        );
    }

    // 🆕 ── مستند مناقلة عكسية واحدة (الطلب رقم 3: "فواتير" حقيقية للمناقلات
    // العكسية، لا سجل مسطَّح فقط) — نفس نمط showInvoice/exportInvoiceExcel أعلاه
    // بالضبط، بالاتجاه المعاكس (الصيدلية تُرسِل، المستودع يستلم).

    public function showReverseTransfer($id)
    {
        $transfer = ReverseTransfer::with(['pharmacy', 'items.product', 'executor'])->findOrFail($id);
        $cycle = $this->cycleResolver->current();

        return view('inventory.reverse-transfer-details', compact('transfer', 'cycle'));
    }

    public function exportReverseTransferExcel($id)
    {
        $transfer = ReverseTransfer::with('pharmacy')->findOrFail($id);
        $fileName = SafeExportFilename::sanitize('مناقلة-عكسية-' . $transfer->id . '-' . $transfer->pharmacy->name . '.xlsx');

        return Excel::download(new ReverseTransferExport($transfer->id), $fileName);
    }

    // 🆕 ── الطلب رقم 4: مناقلات مباشرة مقترَحة بين صيدليتين ──
    // (بلا مرور بالمستودع المركزي؛ راجع InterPharmacyTransferPlanner وتعليق
    // مايجريشن pharmacy_transfers لفلسفة "اقتراح يحتاج موافقة بشرية" الكاملة)

    public function pharmacyTransfersReview()
    {
        // 🆕 (ب.7) الدورة الحالية فقط، وما زال فائضه متاحاً — المستندات التي ألغتها مناقلة
        // عكسية منفَّذة (أو العكس) تختفي هنا تلقائياً بلا أي استعلام خاص بهذه الشاشة.
        $suggested = $this->pendingTransfers->pharmacyTransfers();
        $cycle = $this->cycleResolver->current();

        $recentDecisions = PharmacyTransfer::where('status', '!=', \App\Enums\PharmacyTransferStatus::Suggested)
            ->with(['fromPharmacy', 'toPharmacy', 'executor'])
            ->latest('updated_at')
            ->limit(20)
            ->get();

        return view('inventory.pharmacy-transfers-review', compact('suggested', 'recentDecisions', 'cycle'));
    }

    public function refreshPharmacyTransferSuggestions()
    {
        $count = $this->transferSuggestionService->refresh();

        $message = $count > 0
            ? "🔄 تم تحديث الاقتراحات: {$count} صنفاً عبر مستندات مُقترَحة جديدة — راجعها أدناه."
            : '🔄 تم التحديث — لا فرص مناقلة مباشرة بين الصيدليات حالياً.';

        return redirect()->route('inventory.pharmacy-transfers.review')->with('status', $message);
    }

    /**
     * 🧊 (ب.7) مُجمَّد — لم يعد مربوطاً بأي مسار (المسار نفسه يوجَّه الآن إلى
     * Inventory\TransferExecutionController::executePharmacy). أُبقي بلا حذف مع خدمته.
     */
    public function approvePharmacyTransfer($id)
    {
        try {
            $outcome = $this->transferExecutionService->approve((int) $id);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = $outcome->wasFullyAsSuggested()
            ? '✅ تمت الموافقة وتنفيذ المناقلة بالكامل كما اقتُرحت.'
            : "⚠️ تمت الموافقة، لكن الأرصدة تغيّرت منذ الاقتراح: {$outcome->fullyExecutedCount} صنف نُفِّذ بالكامل، {$outcome->partiallyExecutedCount} جزئياً، {$outcome->skippedCount} لم يُنفَّذ (لا رصيد متاح الآن).";

        return redirect()->route('inventory.pharmacy-transfers.review')->with('status', $message);
    }

    /** 🧊 (ب.7) مُجمَّد — راجع تعليق approvePharmacyTransfer() أعلاه. */
    public function dismissPharmacyTransfer($id)
    {
        try {
            $this->transferExecutionService->dismiss((int) $id);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.pharmacy-transfers.review')->with('status', '❌ رُفض الاقتراح — لم يُلمَس أي رصيد.');
    }

    public function showPharmacyTransfer($id)
    {
        $transfer = PharmacyTransfer::with(['fromPharmacy', 'toPharmacy', 'items.product', 'executor'])->findOrFail($id);
        $cycle = $this->cycleResolver->current();

        return view('inventory.pharmacy-transfer-details', compact('transfer', 'cycle'));
    }

    public function exportPharmacyTransferExcel($id)
    {
        $transfer = PharmacyTransfer::with(['fromPharmacy', 'toPharmacy'])->findOrFail($id);
        $fileName = SafeExportFilename::sanitize('مناقلة-مباشرة-' . $transfer->id . '-' . $transfer->fromPharmacy->name . '-الى-' . $transfer->toPharmacy->name . '.xlsx');

        return Excel::download(new PharmacyTransferExport($transfer->id), $fileName);
    }

    // ══════════════════════════════════════════════════════════
    // 🆕 أصناف للمراقبة — شاشة رفع بسيطة + إدارة القائمة الحالية.
    // مُعالَجة بشكل متزامن (Synchronous) عمداً، بلا Job/شاشة انتظار —
    // الملف بطبيعته صغير جداً (قائمة أصناف محدَّدة يختارها المستخدم
    // يدوياً، لا سجل مبيعات/جرد كامل)، فلا مبرر للتعقيد الإضافي.
    // ══════════════════════════════════════════════════════════

    // 🆕 ── بيانات المواد (ملف مستقل تماماً عن دفعة الترميم اليومية: يُرفع مرة ويُحدَّث عند الحاجة) ──

    public function showMaterialMasterUploadForm()
    {
        return view('inventory.material-master-upload');
    }

    public function uploadMaterialMaster(UploadMaterialMasterRequest $request)
    {
        $path = $request->file('material_master_file')->store('imports');

        try {
            // 🆕 (أ.1) track(): فشل الملف يُسجَّل "فشل" برسالته ثم يُعاد رميه لنفس الالتقاط أدناه.
            $outcome = $this->importHistory->track(
                \App\Enums\ImportFileType::MaterialMaster,
                $request->file('material_master_file'),
                function (\App\Models\ImportLog $log) use ($path) {
                    $outcome = $this->materialMasterImporter->import(Storage::path($path), $log->id);
                    $this->importHistory->complete($log, $outcome);

                    return $outcome;
                },
            );
        } catch (\Exception $e) {
            // 🩹 InvalidImportFileException (رؤوس غير مطابقة، ملف تالف) يُعرض كما هو —
            // رسالته مكتوبة أصلاً للمستخدم النهائي (راجع تعليق الكلاس نفسه). بلا هذا
            // الالتقاط، كان سيظهر خطأ خادم عام (500) بدل رسالة واضحة على نفس الشاشة —
            // نفس نمط الالتقاط المتّبع أصلاً بـ createPendingProducts() أعلاه.
            return back()->with('error', 'حدث خطأ: ' . $e->getMessage());
        } finally {
            Storage::delete($path);
        }

        return redirect()->route('inventory.material-master.form')
            ->with('status', implode(' ', $outcome->summaryLines()));
    }

    public function showWatchedProductsUploadForm()
    {
        $watchedProducts = WatchedProduct::with('product:id,name,company,barcode')
            ->get()
            ->sortBy(fn($w) => $w->product?->name);

        return view('inventory.watched-products-upload', compact('watchedProducts'));
    }

    public function uploadWatchedProducts(UploadWatchedProductsRequest $request)
    {
        $path = $request->file('watched_products_file')->store('imports');

        $import = $this->importHistory->track(
            \App\Enums\ImportFileType::WatchedProducts,
            $request->file('watched_products_file'),
            function (\App\Models\ImportLog $log) use ($path) {
                $import = new WatchedProductsImport();
                Excel::import($import, Storage::path($path));
                $this->importHistory->complete($log, \App\Services\Shared\ImportHistory\ImportStats::fromCounts($import->addedCount, count($import->unmatchedBarcodes)));

                return $import;
            },
        );
        Storage::delete($path);

        $msg = "✅ تمت إضافة {$import->addedCount} مادة لقائمة المراقبة.";
        if (!empty($import->unmatchedBarcodes)) {
            $msg .= ' ⚠️ الرموز التالية غير مسجَّلة بالنظام ولم تُضَف: ' . implode('، ', $import->unmatchedBarcodes) . '.';
        }

        return redirect()->route('inventory.watched-products.form')->with('status', $msg);
    }

    public function removeWatchedProduct(int $id)
    {
        WatchedProduct::where('id', $id)->delete();

        return redirect()->route('inventory.watched-products.form')->with('status', '🗑️ تمت إزالة المادة من قائمة المراقبة.');
    }

    // ══════════════════════════════════════════════════════════
    // 🆕 إدارة الشركات المستثناة من الترميم — قائمة دائمة الآن، لا
    // اختيار خاص بكل دفعة رفع. راجع الفصل ذي الصلة بالتقرير التعليمي
    // (إن وُجد) للسياق الكامل لهذا التغيير.
    //
    // 🎓 الإضافة عبر قائمة منسدلة من شركات موجودة فعلياً بجدول products،
    // لا حقل نصّي حر — بقصد صريح: كتابة اسم شركة يدوياً بخطأ إملائي بسيط
    // (فراغ زائد، حرف مختلف) يجعل الاستثناء عديم الأثر تماماً بصمت، بلا
    // أي رسالة خطأ تُنبِّه لذلك — بالضبط فئة الخطأ التي استغرق تشخيصها
    // هذه الجلسة الطويلة بالكامل. القائمة المنسدلة تجعل هذا الخطأ
    // مستحيلاً هيكلياً، لا مجرد أقل احتمالاً.
    // ══════════════════════════════════════════════════════════

    public function showExcludedCompaniesForm()
    {
        $excluded = \App\Models\ExcludedCompany::orderBy('company')->get();

        // 🎯 كل الشركات المعروفة فعلياً بالنظام، ناقصاً المستثناة أصلاً —
        // القائمة المنسدلة تعرض فقط ما يستحق فعلاً أن يُضاف.
        $excludedNames = $excluded->pluck('company');
        $availableCompanies = Product::select('company')->distinct()
            ->whereNotNull('company')->where('company', '!=', '-')
            ->orderBy('company')->pluck('company')
            ->reject(fn($c) => $excludedNames->contains($c))
            ->values();

        return view('inventory.excluded-companies', compact('excluded', 'availableCompanies'));
    }

    public function addExcludedCompany(Request $request)
    {
        $data = $request->validate([
            'company' => ['required', 'string', 'max:255'],
        ], [
            'company.required' => 'يرجى اختيار شركة.',
        ]);

        \App\Models\ExcludedCompany::firstOrCreate(['company' => $data['company']]);

        return redirect()->route('inventory.excluded-companies.form')
            ->with('status', "✅ تم استثناء شركة \"{$data['company']}\" — لن تظهر بيانات مبيعاتها أو جرد صيدلياتها بأي رفع قادم، ولن تُرمَّم نواقصها القديمة، حتى تُزال من هذه القائمة.");
    }

    public function removeExcludedCompany(int $id)
    {
        $company = \App\Models\ExcludedCompany::find($id);
        \App\Models\ExcludedCompany::where('id', $id)->delete();

        return redirect()->route('inventory.excluded-companies.form')
            ->with('status', "↩️ تمت إزالة \"{$company?->company}\" من قائمة الاستثناء — ستُعامَل بشكل طبيعي بأي رفع قادم.");
    }
}
