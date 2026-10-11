<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\FinalizeManualReceivingRequest;
use App\Http\Requests\Purchasing\FinalizePurchaseReceiptRequest;
use App\Http\Requests\Purchasing\UploadPurchaseReceiptRequest;
use App\Imports\PurchaseReceiptImport;
use App\Models\PurchaseOrder;
use App\Models\TemporaryPurchaseReceipt;
use App\Services\Purchasing\AutoMatchPurchaseReceiptsService;
use App\Services\Purchasing\ManualReceivingService;
use App\Services\Purchasing\ReceivingReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 🆕 إعادة هيكلة (2026-08، تعديل ثانٍ): بقرار صريح من صاحب المشروع،
 * أصبح لتسجيل الاستلام الفعلي **سيناريوهان متاحان معاً** (وليس استبدالاً):
 *
 *   1) رفع ملف Excel (السيناريو الأصلي، بلا أي تعديل — كل الدوال القديمة
 *      showUploadForm/upload/showMapping/finalize تبقى كما هي 100%).
 *   2) 🆕 تدقيق يدوي مباشر من جدول (selectOrderManual/showManualForm/
 *      finalizeManual) — عبر ManualReceivingService الجديدة.
 *
 * شاشة اختيار وسيطة (receiving-choose-method.blade.php) تعرض كلا
 * الخيارين للمشرف أولاً، وهو يختار الأنسب لكل عملية استلام على حدة.
 */
class ReceivingController extends Controller
{
    public function __construct(
        private readonly AutoMatchPurchaseReceiptsService $autoMatch,
        private readonly ReceivingReconciliationService $reconciliation,
        private readonly ManualReceivingService $manualReceiving,
        private readonly \App\Services\Shared\ImportHistory\ImportHistoryService $importHistory, // 🆕 (أ.1)
    ) {
    }

    /** 🆕 شاشة الاختيار بين السيناريوهين — نقطة الدخول الجديدة الرئيسية */
    public function chooseMethod()
    {
        return view('purchasing.receiving-choose-method');
    }

    // ══════════════════════════════════════════════════════════
    // السيناريو 1 — رفع ملف Excel (بلا أي تعديل عن النسخة الأصلية)
    // ══════════════════════════════════════════════════════════

    public function showUploadForm()
    {
        $eligibleOrders = PurchaseOrder::with('supplier')
            ->whereIn('status', [
                PurchaseOrderStatus::Approved->value,
                PurchaseOrderStatus::Sent->value,
                PurchaseOrderStatus::PartiallyReceived->value,
            ])
            ->orderByDesc('generated_at')
            ->get();

        return view('purchasing.receiving-upload', ['orders' => $eligibleOrders]);
    }

    public function upload(UploadPurchaseReceiptRequest $request)
    {
        $sessionId = uniqid('receipt_session_');
        $purchaseOrderId = (int) $request->validated('purchase_order_id');
        $filePath = $request->file('receipt_file')->store('imports');

        $history = $this->importHistory;
        $import = $history->track(\App\Enums\ImportFileType::PurchaseReceipt, $request->file('receipt_file'), function (\App\Models\ImportLog $log) use ($sessionId, $purchaseOrderId, $filePath, $history) {
            $import = new PurchaseReceiptImport($sessionId, $purchaseOrderId);
            Excel::import($import, Storage::path($filePath));
            Storage::delete($filePath);

            $import->rowsRead === 0
                ? $history->fail($log, 'الملف فارغ أو لا يحتوي على بيانات صالحة.')
                : $history->complete($log, \App\Services\Shared\ImportHistory\ImportStats::fromCounts($import->rowsRead));

            return $import;
        });

        if ($import->rowsRead === 0) {
            return back()->with('error', 'الملف فارغ أو لا يحتوي على بيانات صالحة.');
        }

        $this->autoMatch->handle($sessionId);

        return redirect()->route('purchasing.receiving.mapping', ['sessionId' => $sessionId]);
    }

    public function showMapping(string $sessionId)
    {
        $receipts = TemporaryPurchaseReceipt::forSession($sessionId)->get();

        if ($receipts->isEmpty()) {
            return redirect()->route('purchasing.receiving.upload-form')->with('error', 'انتهت صلاحية جلسة الاستلام، يرجى إعادة رفع الملف.');
        }

        $purchaseOrderId = $receipts->first()->purchase_order_id;
        $order = PurchaseOrder::with(['supplier', 'items.product'])->findOrFail($purchaseOrderId);

        $matched = $receipts->whereNotNull('matched_purchase_order_item_id');
        $unmatched = $receipts->whereNull('matched_purchase_order_item_id');

        return view('purchasing.receiving-mapping', [
            'sessionId' => $sessionId,
            'order'     => $order,
            'matched'   => $matched,
            'unmatched' => $unmatched,
        ]);
    }

    public function finalize(FinalizePurchaseReceiptRequest $request)
    {
        $sessionId = $request->validated('session_id');
        $mappings = $request->validated('mappings') ?? [];

        DB::transaction(function () use ($sessionId, $mappings) {
            foreach ($mappings as $receiptId => $orderItemId) {
                if ($orderItemId === 'skip' || !$orderItemId) {
                    TemporaryPurchaseReceipt::where('id', $receiptId)->delete();
                    continue;
                }

                TemporaryPurchaseReceipt::where('id', $receiptId)
                    ->update(['matched_purchase_order_item_id' => $orderItemId]);
            }
        });

        $stats = $this->reconciliation->finalize($sessionId, $request->boolean('order_is_finalized', true));

        return redirect()->route('purchasing.orders.index')->with(
            'success',
            "✅ تم تسجيل استلام {$stats['processed_items']} صنف بإجمالي {$stats['total_received_qty']} وحدة."
        );
    }

    // ══════════════════════════════════════════════════════════
    // 🆕 السيناريو 2 — تدقيق يدوي مباشر (بلا ملف، بلا جدول مؤقت)
    // ══════════════════════════════════════════════════════════

    public function selectOrderManual()
    {
        $eligibleOrders = PurchaseOrder::with('supplier')
            ->whereIn('status', [
                PurchaseOrderStatus::Approved->value,
                PurchaseOrderStatus::Sent->value,
                PurchaseOrderStatus::PartiallyReceived->value,
            ])
            ->orderByDesc('generated_at')
            ->get();

        return view('purchasing.receiving-manual-select-order', ['orders' => $eligibleOrders]);
    }

    public function showManualForm(PurchaseOrder $purchaseOrder)
    {
        if (!in_array($purchaseOrder->status, [
            PurchaseOrderStatus::Approved,
            PurchaseOrderStatus::Sent,
            PurchaseOrderStatus::PartiallyReceived,
        ], true)) {
            return redirect()->route('purchasing.receiving.manual.select')
                ->with('error', 'هذه الفاتورة ليست بحالة تسمح بتسجيل استلام حالياً.');
        }

        $purchaseOrder->load('items.product');

        return view('purchasing.receiving-manual-items-form', ['order' => $purchaseOrder]);
    }

    public function finalizeManual(FinalizeManualReceivingRequest $request, PurchaseOrder $purchaseOrder)
    {
        $stats = $this->manualReceiving->process($purchaseOrder, $request->validated('items'));

        return redirect()->route('purchasing.orders.index')->with(
            'success',
            "✅ تم تسجيل استلام {$stats['processed_items']} صنف بإجمالي {$stats['total_received_qty']} وحدة (تدقيق يدوي). أي كمية غير مستلَمة ستُحتسَب تلقائياً ضمن الاحتياج بأي توليد فواتير قادم."
        );
    }
}
