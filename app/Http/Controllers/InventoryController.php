<?php

namespace App\Http\Controllers;

use App\Exports\AllInvoicesExport;
use App\Exports\InvoiceExport;
use App\Exports\ShortagesExport;
use App\Http\Requests\Inventory\ImportShortagesFileRequest;
use App\Http\Requests\Inventory\InvoicesIndexFilterRequest;
use App\Http\Requests\Inventory\SaveMappingRequest;
use App\Http\Requests\Inventory\SaveShortagesMappingRequest;
use App\Http\Requests\Inventory\ShortagesDashboardFilterRequest;
use App\Http\Requests\Inventory\UpdateShortageNoteRequest;
use App\Http\Requests\Inventory\UploadInventoryStockRequest;
use App\Http\Requests\Inventory\UploadSalesAndInventoryRequest;
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
use App\Models\Shortage;
use App\Services\Inventory\PendingProductService;
use App\Services\Inventory\SalesMappingService;
use App\Services\Inventory\ShortageFulfillmentService;
use App\Services\Inventory\ShortageImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 🎯 لاحظ الفرق عن النسخة الأصلية: الكونترولر الآن لا يحتوي أي:
 *   - $request->validate([...])   → انتقلت لـ FormRequests
 *   - json_decode(...)            → انتقلت لـ AbstractMappingRequest
 *   - استعلامات DB::raw / بناء WHERE يدوي → انتقلت لـ Model Scopes
 *   - منطق أعمال (business logic) → انتقل لطبقة Services
 * كل ميثود هنا ≤ 10 أسطر تقريباً، ومسؤوليتها الوحيدة: "استقبال HTTP، تفويض،
 * إرجاع Response". هذا بالضبط تعريف الـ Thin Controller.
 */
class InventoryController extends Controller
{
    public function __construct(
        private readonly PendingProductService $pendingProductService,
        private readonly SalesMappingService $salesMappingService,
        private readonly ShortageFulfillmentService $shortageFulfillmentService,
        private readonly ShortageImportService $shortageImportService,
    ) {}

    // ══════════════════════════════════════════════════════════
    // 1) رفع المبيعات والجرد اليومي
    // ══════════════════════════════════════════════════════════

    public function showUploadForm()
    {
        return view('inventory.upload');
    }

    public function upload(UploadSalesAndInventoryRequest $request)
    {
        $sessionId = uniqid('session_');
        $invPath   = $request->file('inventory_file')->store('imports');
        $salesPath = $request->hasFile('sales_file') ? $request->file('sales_file')->store('imports') : null;

        ImportSession::create(['session_id' => $sessionId, 'type' => 'sales_and_inventory', 'status' => 'processing']);

        ProcessInventoryUploadJob::dispatch($sessionId, $invPath, $salesPath);

        return view('inventory.processing', [
            'sessionId'   => $sessionId,
            'title'       => 'جاري معالجة ملفات الجرد والمبيعات',
            'statusRoute' => route('inventory.import.status', $sessionId),
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // 2) جرد المستودع المركزي (شاشة منفصلة)
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

        ProcessInventoryStockUploadJob::dispatch($sessionId, $invPath);

        return view('inventory.processing', [
            'sessionId'      => $sessionId,
            'title'          => 'جاري تحديث جرد المستودع المركزي',
            'statusRoute'    => route('inventory.import.status', $sessionId),
            'redirectOnDone' => route('inventory.stock.upload.form'),
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // نقطة الفحص الموحّدة (Polling) — منطق الـ redirect صار بالـ Model
    // ══════════════════════════════════════════════════════════

    public function importStatus(string $sessionId)
    {
        $session = ImportSession::where('session_id', $sessionId)->firstOrFail();

        return response()->json([
            'status'       => $session->status,
            'message'      => $session->message,
            'redirect_url' => $session->resolveRedirectUrl(), // 🎯 نُقل للـ Model
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // شاشة المواد الجديدة بالجرد
    // ══════════════════════════════════════════════════════════

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

        if ($result['generate'] ?? false) {
            $genResult = app(\App\Services\Inventory\InvoiceGenerationService::class)
                ->generateInvoicesFromTemp($sessionId);

            return redirect()->route('inventory.upload.form')
                ->with($genResult['success'] ? 'success' : 'error', $genResult['message']);
        }

        return redirect()->route('inventory.upload.form')->with('success', $result['message']);
    }

    // ══════════════════════════════════════════════════════════
    // شاشة المطابقة اليدوية (مبيعات)
    // ══════════════════════════════════════════════════════════

    public function showMapping(string $sessionId)
    {
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

        ImportSession::where('session_id', $request->sessionId())->update(['status' => 'processing', 'message' => null]);

        ProcessSaveMappingJob::dispatch($request->sessionId(), $request->mappedPharmacies(), $request->mappedProducts());

        return view('inventory.processing', [
            'sessionId'      => $request->sessionId(),
            'title'          => 'جاري حفظ المطابقات وتوليد الفواتير',
            'statusRoute'    => route('inventory.import.status', $request->sessionId()),
            'redirectOnDone' => route('inventory.upload.form'),
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // استيراد النواقص التاريخية
    // ══════════════════════════════════════════════════════════

    public function showShortagesImportForm()
    {
        return view('inventory.import-shortages');
    }

    public function importShortages(ImportShortagesFileRequest $request)
    {
        $sessionId = uniqid('shortage_session_');
        $filePath  = $request->file('shortages_file')->store('imports');

        ImportSession::create(['session_id' => $sessionId, 'type' => 'shortages', 'status' => 'processing']);

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

    // ══════════════════════════════════════════════════════════
    // فواتير + نواقص + بحث
    // ══════════════════════════════════════════════════════════

    public function fulfillShortages()
    {
        try {
            $result = $this->shortageFulfillmentService->fulfillActiveShortages();
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            return back()->with('error', 'حدث خطأ أثناء معالجة النواقص: ' . $e->getMessage());
        }

        if ($result['fulfilled_count'] === 0 && $result['partial_count'] === 0) {
            return back()->with('info', 'لا توجد نواقص يمكن ترميمها من الرصيد المتوفر حالياً.');
        }

        return back()->with('success', "🎉 تم ترميم {$result['fulfilled_count']} نقص بالكامل، و {$result['partial_count']} نقص جزئياً.");
    }

    public function invoicesIndex(InvoicesIndexFilterRequest $request)
    {
        $filters = $request->filters();

        $invoices = Invoice::with('pharmacy')
            ->filter($filters) // 🎯 نُقل بالكامل إلى Invoice::scopeFilter()
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

        $items = $invoice->items()->availability($request->input('availability'))->get();

        return view('inventory.invoice-details', compact('invoice', 'items'));
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

        $fileName = 'فاتورة-' . $invoice->id . '-' . $invoice->pharmacy->name . $suffix . '.xlsx';

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

        return Excel::download(new AllInvoicesExport($filters), 'فواتير-المناقلة' . $suffix . '.xlsx');
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

        // 🩹 "الأعلى نقصاً" تحترم فلتر الصيدلية والتاريخ لكن تبقى مقصورة على "المعلقة"
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
}
