<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\CentralInventory;
use App\Models\InventoryBatch;
use App\Models\PurchaseOrderItem;
use App\Models\TemporaryPurchaseReceipt;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 يطبّق طلبك المباشر: "رفع ملف مسودة المشتريات المستلمة... مطابقتها
 * مع الفاتورة المطلوبة، وتسجيل ما تم ترميمه وما لم يتم لاستنتاج الأصناف
 * غير المتوفرة بالسوق حالياً".
 *
 * 🎓 هذا الصنف "منسّق" (Orchestrator) بنفس نمط InventoryUploadService
 * وDemandCalculationService بالضبط: يُركّب خطوات متعددة بترتيب واضح دون
 * تنفيذ التفاصيل الدقيقة لكل خطوة بنفسه — يفوّضها.
 *
 * ⚠️ افتراض تصميمي صريح: تُستدعى هذه الخدمة **بعد** أن أصبحت كل صفوف
 * temporary_purchase_receipts لهذه الجلسة مُطابَقة (matched_purchase_
 * order_item_id مملوءة) — عبر شاشة مطابقة يدوية ستُبنى لاحقاً، بنفس
 * نمط شاشات مطابقة المبيعات/النواقص الموجودة أصلاً بمشروعك.
 */
class ReceivingReconciliationService
{
    public function __construct(
        private readonly MarketShortageDetectionService $marketShortageDetection,
    ) {
    }

    /**
     * @return array{processed_items: int, total_received_qty: int, new_batches: int}
     */
    public function finalize(string $sessionId, bool $orderIsFinalized = true): array
    {
        $receiptsByItem = TemporaryPurchaseReceipt::forSession($sessionId)
            ->whereNotNull('matched_purchase_order_item_id')
            ->get()
            ->groupBy('matched_purchase_order_item_id');

        $stats = ['processed_items' => 0, 'total_received_qty' => 0, 'new_batches' => 0];

        DB::transaction(function () use ($receiptsByItem, $orderIsFinalized, $sessionId, &$stats) {
            foreach ($receiptsByItem as $itemId => $rows) {
                $item = PurchaseOrderItem::findOrFail($itemId);

                foreach ($rows as $row) {
                    $this->createInventoryBatch($row, $item);
                    $this->syncCentralInventoryCache($row->product_id, $row->received_qty);
                    $stats['new_batches']++;
                    $stats['total_received_qty'] += $row->received_qty;
                }

                $item->increment('received_qty', $rows->sum('received_qty'));

                // 🎯 أول مرة تُستلَم فيها هذه الفاتورة فعلياً — نُثبّت الكمية
                // المطلوبة النهائية كـ"الكمية المُرسَلة للمورد" إن لم تكن مُثبَّتة أصلاً.
                if ($item->ordered_qty === null) {
                    $item->update(['ordered_qty' => $item->finalQuantity()]);
                }

                $this->marketShortageDetection->evaluate($item->fresh(), $orderIsFinalized);

                $stats['processed_items']++;
            }

            $this->syncOrderStatus($receiptsByItem, $orderIsFinalized);

            TemporaryPurchaseReceipt::forSession($sessionId)->delete();
        });

        return $stats;
    }

    private function createInventoryBatch(TemporaryPurchaseReceipt $row, PurchaseOrderItem $item): void
    {
        InventoryBatch::create([
            'product_id'             => $row->product_id,
            'quantity'                => $row->received_qty,
            'expiry_date'             => $row->expiry_date,
            'received_at'             => now()->toDateString(),
            'purchase_order_item_id' => $item->id,
        ]);
    }

    /**
     * 🎯 يُحافظ على central_inventories.quantity مُتزامناً كـ"كاش مُلخَّص"
     * — راجع القرار التصميمي بالتقرير التعليمي الثاني (قسم inventory_batches):
     * هذا يضمن أن خدمات Phase A الحالية (ShortageFulfillmentService،
     * InvoiceGenerationService) تستمر بالعمل دون أي تعديل عليها إطلاقاً،
     * لأنها تقرأ فقط من central_inventories ولا تعرف بوجود الدفعات أصلاً.
     */
    private function syncCentralInventoryCache(int $productId, int $addedQty): void
    {
        CentralInventory::where('product_id', $productId)->increment('quantity', $addedQty);
    }

    /**
     * تحديث حالة فاتورة الشراء نفسها بناءً على نتيجة الاستلام الإجمالية:
     * مستلمة بالكامل، أم استلام جزئي فقط.
     */
    private function syncOrderStatus($receiptsByItem, bool $orderIsFinalized): void
    {
        if (!$orderIsFinalized) {
            return; // لا نُغيّر حالة الفاتورة إلا عند إغلاقها فعلياً من المستخدم
        }

        $itemIds = $receiptsByItem->keys();
        $items = PurchaseOrderItem::whereIn('id', $itemIds)->with('purchaseOrder')->get();

        foreach ($items->groupBy('purchase_order_id') as $order) {
            $purchaseOrder = $order->first()->purchaseOrder;
            $allItems = $purchaseOrder->items;

            $status = $allItems->every(fn (PurchaseOrderItem $i) => $i->isFullyReceived())
                ? PurchaseOrderStatus::Received
                : PurchaseOrderStatus::PartiallyReceived;

            $purchaseOrder->update(['status' => $status]);
        }
    }
}
