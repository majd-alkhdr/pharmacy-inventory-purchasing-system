<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\ExecutePurchaseTransferRequest;
use App\Models\Pharmacy;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Inventory\Surplus\SurplusEngine;
use App\Services\Inventory\Transfers\TransferExecutionService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * 🎯 (ج.3) زر "مناقلات؟" بجانب "لماذا؟" بتفاصيل فاتورة الشراء المقترحة: قبل شراء مادة، هل
 * تملك صيدلية ما فائضاً منها يمكن سحبه للمستودع بمناقلة عكسية؟
 *
 *  - options():  قراءة من SurplusEngine (نفس المصدر الوحيد لكل مستهلكي الفائض — بعد التزامات
 *                الدورة الحالية، فما حجزه موظف المناقلات قبل دقيقة لا يظهر هنا).
 *  - execute():  نفس مسار التنفيذ في ب.6 حرفياً (TransferExecutionService) — نفس الكيان، الحجز،
 *                الإلغاء المتبادل وسجل النشاط.
 *  - "تجاهل":    لا يصل للخادم إطلاقاً (يُغلق اللوحة بالمتصفح) — لا حفظ، لا سجل، لا تاريخ.
 */
class PurchaseTransferOptionsController extends Controller
{
    public function __construct(
        private readonly SurplusEngine $surplusEngine,
        private readonly TransferExecutionService $executionService,
    ) {
    }

    public function options(PurchaseOrder $purchaseOrder, PurchaseOrderItem $purchaseOrderItem): JsonResponse
    {
        abort_unless((int) $purchaseOrderItem->purchase_order_id === (int) $purchaseOrder->id, 404);

        $productId = (int) $purchaseOrderItem->product_id;
        $snapshot = $this->surplusEngine->snapshot([$productId]);
        $entries = $snapshot->forProduct($productId);
        $names = Pharmacy::whereIn('id', array_map(fn ($e) => $e->pharmacyId, $entries))->pluck('name', 'id');
        $needed = $purchaseOrderItem->finalQuantity();

        return response()->json([
            // شارة "آخر تحديث للبيانات": وقت رفع الكشف التفصيلي الذي بُنيت عليه الأرقام.
            'last_update' => $snapshot->cycle->startedAt?->format('Y-m-d H:i'),
            'needed' => $needed,
            'pharmacies' => array_map(fn ($e) => [
                'pharmacy_id' => $e->pharmacyId,
                'name' => $names[$e->pharmacyId] ?? "#{$e->pharmacyId}",
                'available' => $e->available(),
                'current_stock' => $e->currentStock,
                'standard' => $e->standardQuantity,
                // لا نسحب أكثر مما نحتاج شراءه، ولا أكثر من الفائض المتاح.
                'suggested_qty' => max(1, min($e->available(), max($needed, 1))),
            ], $entries),
        ]);
    }

    public function execute(ExecutePurchaseTransferRequest $request, PurchaseOrder $purchaseOrder, PurchaseOrderItem $purchaseOrderItem)
    {
        abort_unless((int) $purchaseOrderItem->purchase_order_id === (int) $purchaseOrder->id, 404);

        try {
            $outcome = $this->executionService->createAndExecuteReverseTransfer(
                (int) $request->validated('pharmacy_id'),
                (int) $purchaseOrderItem->product_id,
                (int) $request->validated('quantity'),
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $outcome->message() . ' راجع الكمية المعتمدة للشراء إن لزم.');
    }
}
