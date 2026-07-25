<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrderItem;

/**
 * 🎓 نقطة تعليمية مهمة قبل الكود مباشرة:
 * ------------------------------------------------------------------
 * مستندك يصف "فحص الطلبيات قيد التوريد" ضمن "الطبقة الثانية" من فلتر
 * الاستبعاد (Exclusion Filter)، لكن اقرأ وصفه بدقة: *"إذا كان الصنف
 * مطلوباً مسبقاً وبكمية تغطي الاحتياج، يتم استبعاده"* — هذا في الحقيقة
 * ليس استبعاداً منفصلاً بحد ذاته، بل **نتيجة طبيعية لخصم** الكمية قيد
 * التوريد من معادلة الاحتياج (الموصوفة صراحة بقسم "محرك الاحتياج"
 * التالي مباشرة بنفس مستندك). لو خصمنا هذه الكمية والنتيجة صفر أو أقل،
 * "استُبعد" المنتج تلقائياً بحكم أن requiredQty = 0 — دون الحاجة
 * لمنطق استبعاد منفصل ومكرر بمكانين مختلفين.
 * لذلك هذه الخدمة تُستدعى من DemandCalculationService مباشرة (كخصم)،
 * وليست جزءاً من PurchaseEligibilityFilter (الذي يقتصر على الركود فقط).
 */
class OpenPurchaseOrderQuantityService
{
    /** حالات فاتورة الشراء التي لا تُحتسَب "قيد التوريد" لأنها انتهت فعلياً */
    private const EXCLUDED_STATUSES = [
        PurchaseOrderStatus::Cancelled->value,
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
