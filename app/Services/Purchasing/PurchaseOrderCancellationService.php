<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

/**
 * 🎯 يطبّق قرار المستخدم بدقة: زر إلغاء صريح، بمسارين مختلفين حسب حالة
 * الفاتورة الحالية (راجع PurchaseOrderStatus::canBeFullyCancelled() /
 * canCancelRemaining()):
 *
 *   1) إلغاء كامل (قبل أي استلام): الفاتورة تصبح Cancelled. لا يوجد أي
 *      استلام فعلي، فلا داعي لتشغيل منطق "قنص نواقص السوق" — لم يحدث
 *      شيء بالمستودع أصلاً.
 *
 *   2) إلغاء المتبقي (بعد استلام جزئي): الفاتورة تصبح PartiallyCancelled.
 *      هنا **يجب** تشغيل MarketShortageDetectionService لكل سطر ما زال
 *      به عجز استلام — لأن هذا فعلياً "إغلاق نهائي" لهذه الفاتورة، بنفس
 *      دلالة checkbox "هذه آخر شحنة" بشاشة الاستلام، لكن كإجراء مستقل
 *      يُشغَّل يدوياً بدل انتظار رفع ملف استلام جديد لن يأتي أصلاً.
 *
 * في كلا الحالتين: مجرد تغيير الحالة كافٍ لإخراج الكمية غير المستلَمة
 * تلقائياً من حساب "قيد التوريد" (راجع OpenPurchaseOrderQuantityService
 * المحدَّث) — فتعود ضمن الاحتياج الحقيقي بأول توليد فواتير قادم، دون
 * أي كود إضافي هنا خاص بهذه النقطة تحديداً.
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

        $order->update([
            'status' => PurchaseOrderStatus::Cancelled,
            'notes'  => $this->appendReason($order->notes, $reason, 'إلغاء كامل'),
        ]);
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

            // 🎯 نفس أثر "إغلاق نهائي" من شاشة الاستلام — لكل سطر ما زال
            // به عجز، نُفعّل تقييم "قنص نواقص السوق" الآن بالذات.
            $order->items()->get()->each(
                fn (PurchaseOrderItem $item) => $this->marketShortageDetection->evaluate($item, true)
            );
        });
    }

    private function appendReason(?string $existingNotes, ?string $reason, string $action): string
    {
        $line = "[{$action}" . ($reason ? ": {$reason}" : '') . '] بتاريخ ' . now()->format('Y-m-d H:i');

        return $existingNotes ? "{$existingNotes}\n{$line}" : $line;
    }
}
