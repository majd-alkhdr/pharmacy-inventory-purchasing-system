<?php

namespace App\Http\Controllers\Inventory;

use App\Enums\ImportFileType;
use App\Exports\PreparationTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\SetDiscrepancyReasonRequest;
use App\Http\Requests\Inventory\UploadPreparationFileRequest;
use App\Models\DiscrepancyReason;
use App\Models\ImportLog;
use App\Models\Invoice;
use App\Models\TransferReconciliation;
use App\Models\TransferReconciliationItem;
use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use App\Services\Inventory\Reconciliation\TransferReconciliationService;
use App\Services\Shared\Export\SafeExportFilename;
use App\Services\Shared\ImportHistory\ImportHistoryService;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

/**
 * 🎯 (ب.3) شاشة مطابقة فاتورة مناقلة مع التحضير الفعلي: قالب ← رفع ← فروقات ← سبب لكل فرق.
 * نحيل: التحليل والمقارنة والإحالة للمالية كلها بـ TransferReconciliationService.
 */
class TransferReconciliationController extends Controller
{
    public function __construct(
        private readonly TransferReconciliationService $reconciliationService,
        private readonly ImportHistoryService $importHistory,
    ) {
    }

    public function show(int $id)
    {
        $invoice = Invoice::with('pharmacy')->findOrFail($id);

        $latest = TransferReconciliation::where('invoice_id', $invoice->id)
            ->with(['items' => fn ($q) => $q->orderByRaw('difference = 0')->orderBy('difference'), 'items.product', 'items.reason', 'items.settlement', 'user', 'importLog'])
            ->latest('id')
            ->first();

        return view('inventory.transfer-reconciliation', [
            'invoice' => $invoice,
            'reconciliation' => $latest,
            'previousCount' => TransferReconciliation::where('invoice_id', $invoice->id)->count() - ($latest ? 1 : 0),
            'reasons' => DiscrepancyReason::active()->get(),
        ]);
    }

    public function template(int $id)
    {
        $invoice = Invoice::with('pharmacy')->findOrFail($id);

        return Excel::download(new PreparationTemplateExport($invoice->id), SafeExportFilename::sanitize("قالب-تحضير-مناقلة-{$invoice->id}-{$invoice->pharmacy->name}.xlsx"));
    }

    public function upload(UploadPreparationFileRequest $request, int $id)
    {
        $invoice = Invoice::findOrFail($id);
        $path = $request->file('preparation_file')->store('imports');

        try {
            $outcome = $this->importHistory->track(ImportFileType::TransferPreparation, $request->file('preparation_file'), function (ImportLog $log) use ($invoice, $path) {
                $outcome = $this->reconciliationService->reconcile($invoice, Storage::path($path), $log);
                $this->importHistory->complete($log, $outcome);

                return $outcome;
            });
        } catch (InvalidImportFileException $e) {
            return back()->with('error', $e->getMessage());
        } finally {
            Storage::delete($path);
        }

        return redirect()->route('inventory.invoice.reconciliation', $invoice->id)
            ->with($outcome->diagnostics->hasWarnings() ? 'warning' : 'success', $outcome->summary());
    }

    public function setReason(SetDiscrepancyReasonRequest $request, int $itemId)
    {
        $item = TransferReconciliationItem::findOrFail($itemId);

        try {
            $this->reconciliationService->setReason($item, $request->reason());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = $request->reason()?->requires_finance_settlement
            ? '✅ حُفظ السبب — وأُحيلت المادة لشاشة "مواد تحتاج تسوية من المالية".'
            : '✅ حُفظ السبب.';

        return back()->with('success', $message);
    }
}
