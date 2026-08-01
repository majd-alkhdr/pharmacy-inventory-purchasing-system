<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrderItem;

/**
 * 🎓 "فحص الطلبيات قيد التوريد" — خصم مباشر ضمن معادلة الاحتياج
 * (DemandCalculationService)، وليس استبعاداً منفصلاً (راجع الشرح
 * الأصلي بالتقرير التعليمي).
 *
 * 🆕 أُضيفت PartiallyCancelled لقائمة الاستثناء: بمجرد أن يُلغي المشرف
 * "المتبقي" من فاتورة استُلمت جزئياً، تلك الكمية المتبقية لم تعد
 * "قادمة" فعلياً — يجب أن تعود فوراً ضمن الاحتياج الحقيقي بأي حساب
 * تالٍ، تماماً كما لو كانت الفاتورة أُلغيت بالكامل.
 */
class OpenPurchaseOrderQuantityService
{
    private const EXCLUDED_STATUSES = [
        PurchaseOrderStatus::Cancelled->value,
        PurchaseOrderStatus::PartiallyCancelled->value,
        PurchaseOrderStatus::Received->value,
    ];

    public function forProduct(int $productId): int
    {
        return PurchaseOrderItem::where('product_id', $productId)
            ->whereHas('purchaseOrder', fn ($q) => $q->whereNotIn('status', self::EXCLUDED_STATUSES))
            ->get()
            ->sum(fn (PurchaseOrderItem $item) => $item->outstandingQuantity());
    }
}
