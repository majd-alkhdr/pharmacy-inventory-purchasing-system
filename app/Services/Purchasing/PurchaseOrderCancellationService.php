<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

/**
 * 🆕 إصلاح (2026-08): كل من cancelFully() وcancelRemaining() أصبحا الآن
 * يسجّلان صراحة "كم أُلغي لكل سطر" بعمود cancelled_qty الجديد (بدل ترك
 * هذا مستنتَجاً ضمنياً بلا أي عرض بالواجهة). راجع مايجريشن
 * 2026_08_02_000001 للتبرير الكامل.
 */
class PurchaseOrderCancellationService
{
    public function __construct(
        private readonly MarketShortageDetectionService $marketShortageDetection,
    ) {
    }

    public function cancelFully(PurchaseOrder $order, ?string $reason = null): void
    {
        if (!$order->status->canBeFullyCancelled()) {
            throw new \RuntimeException('لا يمكن إلغاء هذه الفاتورة بالكامل بحالتها الحالية — راجع حالتها أولاً.');
        }

        DB::transaction(function () use ($order, $reason) {
            $order->update([
                'status' => PurchaseOrderStatus::Cancelled,
                'notes'  => $this->appendReason($order->notes, $reason, 'إلغاء كامل'),
            ]);

            // 🆕 إلغاء كامل يعني: كل الكمية المطلوبة (والتي لم تُستلَم شيئاً
            // منها أصلاً، لأن الإلغاء الكامل متاح فقط قبل أي استلام فعلي)
            // أصبحت ملغاة بالكامل — نُسجّلها صراحة لكل سطر.
            $order->items()->get()->each(function (PurchaseOrderItem $item) {
                $item->update([
                    'cancelled_qty' => max(0, $item->finalQuantity() - $item->received_qty),
                ]);
            });
        });
    }

    public function cancelRemaining(PurchaseOrder $order, ?string $reason = null): void
    {
        if (!$order->status->canCancelRemaining()) {
            throw new \RuntimeException('إلغاء المتبقي متاح فقط لفاتورة بحالة "استلام جزئي".');
        }

        DB::transaction(function () use ($order, $reason) {
            $order->update([
                'status' => PurchaseOrderStatus::PartiallyCancelled,
                'notes'  => $this->appendReason($order->notes, $reason, 'إلغاء المتبقي بعد استلام جزئي'),
            ]);

            $order->items()->get()->each(function (PurchaseOrderItem $item) {
                // 🆕 نُسجّل الكمية الملغاة صراحة = الفرق المتبقي وقت الإلغاء
                // بالضبط (نفس منطق receivingShortfall لكن "مثبَّتاً" الآن).
                $item->update(['cancelled_qty' => $item->receivingShortfall()]);

                // 🎯 نفس أثر "إغلاق نهائي" من شاشة الاستلام — لكل سطر ما زال
                // به عجز، نُفعّل تقييم "قنص نواقص السوق" الآن بالذات.
                $this->marketShortageDetection->evaluate($item->fresh(), true);
            });
        });
    }

    private function appendReason(?string $existingNotes, ?string $reason, string $action): string
    {
        $line = "[{$action}" . ($reason ? ": {$reason}" : '') . '] بتاريخ ' . now()->format('Y-m-d H:i');

        return $existingNotes ? "{$existingNotes}\n{$line}" : $line;
    }
}
