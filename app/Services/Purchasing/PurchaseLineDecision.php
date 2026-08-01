<?php

namespace App\Services\Purchasing;

/**
 * 🎯 "لغة مشتركة" لنتيجة القرار على مستوى السطر الواحد بفاتورة الشراء.
 *
 * 🆕 supplierId: أُضيف صراحة بعد إعادة هيكلة المشتريات — سابقاً كان
 * المورد يُستنتَج ضمنياً من supplierProductId (عبر product->supplierProducts
 * ->firstWhere('id', ...)) لأن الاختيار كان مادة↔مورد. الآن الاختيار
 * أصبح شركة↔مورد (SupplierSelectionService)، فقد لا يوجد أي سطر
 * supplier_products إطلاقاً يربط هذا المنتج بهذا المورد تحديداً — لذلك
 * supplierId أصبح المصدر المباشر والوحيد الموثوق لتحديد المورد.
 *
 * supplierProductId يبقى موجوداً (nullable) لتوافق البنية القديمة فقط
 * — لا يُملأ من محرك القرار الجديد (PriorityBasedPurchaseDecisionService).
 *
 * unitCost = 0 مؤقتاً بكل قرارات المحرك الجديد — لا أسعار على مستوى
 * السطر بعد (راجع وثيقة التصميم §9)، ستُفعَّل عند عودة نظام العروض.
 */
final class PurchaseLineDecision
{
    public function __construct(
        public readonly int $productId,
        public readonly ?int $supplierId = null,
        public readonly ?int $supplierProductId = null,
        public readonly int $finalQty = 0,
        public readonly float $unitCost = 0.0,
        public readonly int $bonusQty = 0,
        public readonly float $lineTotalCost = 0.0,
        public readonly ?string $scenarioNote = null,
        public readonly bool $hasActiveOffer = false,
        public readonly ?float $marginPerUnit = null,
    ) {
    }
}
