<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\FinalizePurchaseReceiptRequest;
use App\Http\Requests\Purchasing\UploadPurchaseReceiptRequest;
use App\Imports\PurchaseReceiptImport;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\TemporaryPurchaseReceipt;
use App\Services\Purchasing\AutoMatchPurchaseReceiptsService;
use App\Services\Purchasing\ReceivingReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 🎓 لماذا هذا الكنترولر "متزامن" (Synchronous) بدل استخدام Job كباقي
 * شاشات الاستيراد بمشروعك (SalesImport، ShortagesImport)؟
 * ------------------------------------------------------------------
 * ملفات الاستلام تخص فاتورة شراء **واحدة محدَّدة** (عشرات الأسطر عادةً،
 * وليس آلافاً كملفات المبيعات/النواقص الشاملة لكل الصيدليات). حجم
 * البيانات هنا صغير بما يكفي ليكتمل خلال ثوانٍ، فلا داعٍ لتعقيد إضافي
 * (Job + Polling + شاشة انتظار) لعملية تكتمل فوراً عملياً. لو أثبت
 * الاستخدام الفعلي لاحقاً أن بعض الموردين يرسلون ملفات استلام ضخمة،
 * التحويل لنمط Job غير المتزامن يكون تعديلاً موضعياً بسيطاً.
 */
class ReceivingController extends Controller
{
    public function __construct(
        private readonly AutoMatchPurchaseReceiptsService $autoMatch,
        private readonly ReceivingReconciliationService $reconciliation,
    ) {
    }

    public function showUploadForm()
    {
        // فقط الفواتير التي أُرسلت فعلياً للمورد ولم تُستلَم بالكامل بعد
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

        $import = new PurchaseReceiptImport($sessionId, $purchaseOrderId);
        Excel::import($import, Storage::path($filePath));
        Storage::delete($filePath);

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
            return redirect()->route('purchasing.receiving.form')->with('error', 'انتهت صلاحية جلسة الاستلام، يرجى إعادة رفع الملف.');
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
            "✅ تم تسجيل استلام {$stats['processed_items']} صنف بإجمالي {$stats['total_received_qty']} وحدة، وإنشاء {$stats['new_batches']} دفعة مخزون جديدة."
        );
    }
}
