<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrderItem;
use App\Models\TemporaryPurchaseReceipt;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 المسؤولية الوحيدة: مطابقة استلام فعلي مع فاتورة شراء، وتحديث بيانات
 * الفاتورة نفسها (received_qty, ordered_qty, الحالة) + تفعيل "قنص نواقص
 * السوق" عند وجود عجز — **بدون أي تأثير على أي جرد بالنظام إطلاقاً**.
 *
 * ⚠️ إعادة هيكلة المشتريات (2026-08) — تغيير جوهري بقرار صريح من صاحب
 * المشروع: "عند الاستلام فقط سوف تتأثر فواتير الشراء والمنتجات بداخلها
 * لتتغيّر حالتها إلى (تم الاستلام)، ولكن لن تُضاف إلى جرد بالنظام — جرد
 * النظام هو الملف الذي أرفعه أنا (المشرف) حصراً."
 *
 * لذلك تمت إزالة استدعاءَي createInventoryBatch() وsyncCentralInventoryCache()
 * القديمين بالكامل (الدالتان نفسهما حُذفتا من هذا الملف). المنطق المتبقي
 * (increment('received_qty'), تحديث ordered_qty، MarketShortageDetectionService)
 * يبقى تماماً كما كان — لأنه أصلاً لا يعتمد على أي جرد، فقط على الفرق
 * الداخلي بين المطلوب والمستلم بسطر الفاتورة نفسها.
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

        // 🆕 new_batches يبقى صفراً دائماً الآن (لا يُنشأ أي InventoryBatch
        // من الاستلام بعد اليوم) — أُبقيه بمخرجات الدالة لتوافق الواجهة
        // القديمة (receiving-mapping وresponse الكونترولر) بلا كسرها.
        $stats = ['processed_items' => 0, 'total_received_qty' => 0, 'new_batches' => 0];

        DB::transaction(function () use ($receiptsByItem, $orderIsFinalized, $sessionId, &$stats) {
            foreach ($receiptsByItem as $itemId => $rows) {
                $item = PurchaseOrderItem::findOrFail($itemId);

                foreach ($rows as $row) {
                    $stats['total_received_qty'] += $row->received_qty;
                }

                $item->increment('received_qty', $rows->sum('received_qty'));

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

    private function syncOrderStatus($receiptsByItem, bool $orderIsFinalized): void
    {
        if (!$orderIsFinalized) {
            return;
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
