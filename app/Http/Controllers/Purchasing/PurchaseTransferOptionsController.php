<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\BranchStockState;
use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\ExecutePurchaseTransferRequest;
use App\Models\Pharmacy;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\Inventory\Surplus\SurplusEngine;
use App\Services\Inventory\Transfers\TransferExecutionService;
use App\Services\Purchasing\BranchStockLookup;
use App\Services\Purchasing\BranchStockRow;
use App\Services\Shared\Stock\CentralStockReader;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * 🎯 (ج.3) لوحة "الفروع" (كانت "مناقلات؟") بجانب "لماذا؟" بتفاصيل فاتورة الشراء المقترحة: رصيد
 * المادة بكل فرع، مع إبراز من يملك فائضاً يمكن سحبه للمستودع بمناقلة عكسية قبل شرائها.
 *
 *  - options():  قراءة من SurplusEngine (نفس المصدر الوحيد لكل مستهلكي الفائض — بعد التزامات
 *                الدورة الحالية، فما حجزه موظف المناقلات قبل دقيقة لا يظهر هنا) + BranchStockLookup
 *                لأرصدة كل الفروع (فائضاً كان أو لا).
 *  - execute():  نفس مسار التنفيذ في ب.6 حرفياً (TransferExecutionService) — نفس الكيان، الحجز،
 *                الإلغاء المتبادل وسجل النشاط. **للمسودات فقط** (قرار مجد): الحارس هنا بالخادم،
 *                لا بإخفاء الزر وحده.
 *  - "تجاهل":    لا يصل للخادم إطلاقاً (يُغلق اللوحة بالمتصفح) — لا حفظ، لا سجل، لا تاريخ.
 */
class PurchaseTransferOptionsController extends Controller
{
    public function __construct(
        private readonly SurplusEngine $surplusEngine,
        private readonly TransferExecutionService $executionService,
        private readonly BranchStockLookup $branchStock,
        private readonly CentralStockReader $centralStock,
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
        // لا نسحب أكثر مما نحتاج شراءه، ولا أكثر من الفائض المتاح.
        $suggest = fn (int $available): int => max(1, min($available, max($needed, 1)));

        $branches = $this->branchStock->forProduct($productId, $snapshot);
        $surplusRows = array_filter($branches, fn (BranchStockRow $b) => $b->state === BranchStockState::Surplus);

        return response()->json([
            // شارة "آخر تحديث للبيانات": وقت رفع الكشف التفصيلي الذي بُنيت عليه الأرقام.
            'last_update' => $snapshot->cycle->startedAt?->format('Y-m-d H:i'),
            'needed' => $needed,
            'can_execute' => $purchaseOrder->status === PurchaseOrderStatus::Draft,
            'industry_stock' => $this->centralStock->quantityFor($productId),
            'pharmacies' => array_map(fn ($e) => [
                'pharmacy_id' => $e->pharmacyId,
                'name' => $names[$e->pharmacyId] ?? "#{$e->pharmacyId}",
                'available' => $e->available(),
                'current_stock' => $e->currentStock,
                'standard' => $e->standardQuantity,
                'suggested_qty' => $suggest($e->available()),
            ], $entries),
            'branches' => array_map(fn (BranchStockRow $b) => [
                'pharmacy_id' => $b->pharmacyId,
                'name' => $b->name,
                'current_stock' => $b->currentStock,
                'standard' => $b->standard,
                'difference' => $b->difference,
                'state' => $b->state->value,
                'state_label' => $b->state->label(),
                'available' => $b->available,
                'committed' => $b->committed,
                'suggested_qty' => $b->state === BranchStockState::Surplus ? $suggest($b->available) : null,
            ], $branches),
            'summary' => [
                'branches_total' => count($branches),
                'branches_stock_sum' => array_sum(array_map(fn (BranchStockRow $b) => $b->currentStock ?? 0, $branches)),
                'surplus_available' => array_sum(array_map(fn (BranchStockRow $b) => $b->available, $surplusRows)),
                'surplus_branches' => count($surplusRows),
            ],
        ]);
    }

    public function execute(ExecutePurchaseTransferRequest $request, PurchaseOrder $purchaseOrder, PurchaseOrderItem $purchaseOrderItem)
    {
        abort_unless((int) $purchaseOrderItem->purchase_order_id === (int) $purchaseOrder->id, 404);

        // قرار مجد: بعد خروج الفاتورة من المسودة لا سحب منها — قبل أي حجز أو أثر.
        if ($purchaseOrder->status !== PurchaseOrderStatus::Draft) {
            return back()->with('error', 'لا يمكن السحب من فاتورة غير مسودة.');
        }

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
