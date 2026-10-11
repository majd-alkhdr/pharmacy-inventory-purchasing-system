<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 السيناريو الثاني للاستلام الفعلي — بديل مباشر لآلية "رفع ملف Excel"
 * (التي تبقى موجودة تماماً بلا أي تعديل، راجع ReceivingController القديم
 * وReceivingReconciliationService). هنا: المشرف يفتح الفاتورة، يرى كل
 * سطر مباشرة، ويحدّد لكل سطر إحدى ثلاث حالات — بلا أي ملف وسيط أو جدول
 * مؤقت (temporary_purchase_receipts غير مُستخدَم هنا إطلاقاً).
 *
 * 🎓 قرار تصميمي حاسم يجب توثيقه بوضوح: **كل عملية تدقيق يدوي هنا
 * "نهائية" (Finalized) دائماً** — بخلاف آلية الملف القديمة التي كانت
 * تسمح بـ"هذه ليست آخر شحنة" (checkbox order_is_finalized). السبب: طبيعة
 * هذه الشاشة نفسها (اختيار حالة كل سطر مرة واحدة، بلا مفهوم "شحنات
 * متعددة عبر الزمن") تجعل "لا حسم بعد" غير منطقي هنا — إما استلمتَ الآن
 * فعلياً أو لم تستلم، والقرار يُتّخذ فوراً وبشكل قاطع.
 *
 * الأثر العملي لهذا القرار: أي جزء غير مُستلَم (استلام جزئي أو "عدم
 * استلام") يُسجَّل فوراً كـ cancelled_qty (تماماً كأثر "إلغاء المتبقي"
 * اليدوي القديم، لكن بخطوة واحدة بدل خطوتين منفصلتين) — فيعود تلقائياً
 * ضمن الاحتياج المحسوب بأي توليد فواتير قادم (راجع
 * PurchaseOrderItem::outstandingQuantity() المُصلَح).
 */
class ManualReceivingService
{
    public function __construct(
        private readonly MarketShortageDetectionService $marketShortageDetection,
        private readonly LastPurchaseDateSynchronizer $lastPurchaseDate = new LastPurchaseDateSynchronizer(),
    ) {
    }

    /**
     * @param array<int, array{status:string, received_qty?:int, note?:?string}> $itemsInput
     *        [purchase_order_item_id => ['status' => 'full'|'partial'|'none', 'received_qty' => int|null, 'note' => string|null]]
     * @return array{processed_items:int, total_received_qty:int}
     */
    public function process(PurchaseOrder $order, array $itemsInput): array
    {
        $processedCount = 0;
        $totalReceived = 0;

        DB::transaction(function () use ($order, $itemsInput, &$processedCount, &$totalReceived) {
            $items = $order->items()->get()->keyBy('id');

            foreach ($itemsInput as $itemId => $input) {
                /** @var PurchaseOrderItem|null $item */
                $item = $items->get((int) $itemId);
                if (!$item) {
                    continue; // 🩹 حماية: تجاهل أي معرّف سطر لا ينتمي لهذه الفاتورة
                }

                $finalQty = $item->finalQuantity();
                $orderedQty = $item->ordered_qty ?? $finalQty;

                $receivedQty = match ($input['status'] ?? 'none') {
                    'full'    => $finalQty,
                    'partial' => max(0, min($finalQty, (int) ($input['received_qty'] ?? 0))),
                    default   => 0, // 'none' أو أي قيمة غير متوقَّعة → معاملة آمنة كـ"لم يُستلَم"
                };

                // 🎯 القرار المُوثَّق أعلاه: الفرق يُسجَّل ملغياً فوراً ونهائياً
                $cancelledQty = max(0, $orderedQty - $receivedQty);

                $item->update([
                    'received_qty'    => $receivedQty,
                    // 🆕 تأكيد يدوي نهائي = الكمية المطلقة؛ لا استلام ⇒ لا تاريخ شراء لهذا السطر.
                    'received_at'     => $receivedQty > 0 ? now() : null,
                    'ordered_qty'     => $orderedQty,
                    'cancelled_qty'   => $cancelledQty,
                    'receiving_note'  => $input['note'] ?? null,
                ]);

                // 🎯 نفس منطق "قنص نواقص السوق" — يُقيَّم دائماً كـ"نهائي"
                // بهذا السيناريو (راجع الشرح أعلى الملف).
                $this->marketShortageDetection->evaluate($item->fresh(), true);

                $processedCount++;
                $totalReceived += $receivedQty;
            }

            $this->syncOrderStatus($order);

            // 🆕 last_purchase_date يُشتَق من الاستلام الفعلي لهذه الفاتورة، داخل نفس المعاملة.
            $this->lastPurchaseDate->syncFor($items->pluck('product_id'));
        });

        return ['processed_items' => $processedCount, 'total_received_qty' => $totalReceived];
    }

    private function syncOrderStatus(PurchaseOrder $order): void
    {
        $allItems = $order->items()->get();

        $status = $allItems->every(fn (PurchaseOrderItem $i) => $i->isFullyReceived())
            ? PurchaseOrderStatus::Received
            : PurchaseOrderStatus::PartiallyReceived;

        $order->update(['status' => $status]);
    }
}
