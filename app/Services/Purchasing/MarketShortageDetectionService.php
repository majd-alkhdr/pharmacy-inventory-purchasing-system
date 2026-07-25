<?php

namespace App\Services\Purchasing;

use App\Models\MarketShortageFlag;
use App\Models\Product;
use App\Models\PurchaseOrderItem;

/**
 * 🎯 يطبّق قسم 6 من مستندك: *"عند مطابقة فاتورة الشراء مع مسودة الاستلام
 * الفعلي واكتشاف نقص في التوريد، يقوم النظام بوسم المادة كـ'مقطوعة من
 * السوق'."*
 *
 * 🎓 مسؤولية واحدة محدَّدة جداً هنا: "بناءً على نتيجة استلام سطر فاتورة
 * واحد، هل نُفعّل أو نُلغي وسم قنص السوق لهذا المنتج؟" — لا شيء غير هذا.
 * كل التفاصيل الأخرى (إنشاء دفعات مخزون، تحديث الفاتورة نفسها) مسؤولية
 * ReceivingReconciliationService المنسّق، وليست هذه الخدمة.
 *
 * ⚠️ orderIsFinalized: معامل مهم يستحق التوضيح. عجز الاستلام بمنتصف
 * عملية استلام لا يعني بالضرورة "مقطوع من السوق نهائياً" — قد تصل شحنة
 * تكميلية لاحقاً لنفس الفاتورة. الوسم الفعلي يُفعَّل فقط عندما يُعلن
 * المستخدم أن هذه الفاتورة "أُغلقت" (لا شحنات إضافية متوقعة)، وما زال
 * هناك عجز عند تلك اللحظة تحديداً.
 */
class MarketShortageDetectionService
{
    public function evaluate(PurchaseOrderItem $item, bool $orderIsFinalized): void
    {
        $product = $item->product;
        $shortfall = $item->receivingShortfall();

        if ($shortfall === 0) {
            $this->resolveActiveFlagIfAny($product);
            return;
        }

        if ($orderIsFinalized) {
            $this->flagIfNotAlreadyActive($product, $item, $shortfall);
        }
    }

    private function flagIfNotAlreadyActive(Product $product, PurchaseOrderItem $item, int $shortfall): void
    {
        $alreadyFlagged = $product->marketShortageFlags()->active()->exists();

        if ($alreadyFlagged) {
            return; // لا نُكرّر نفس الوسم لو كان نشطاً أصلاً من فاتورة سابقة
        }

        MarketShortageFlag::create([
            'product_id' => $product->id,
            'flagged_at' => now(),
            'note'       => "عجز استلام {$shortfall} وحدة — فاتورة شراء #{$item->purchase_order_id}، سطر #{$item->id}.",
        ]);
    }

    private function resolveActiveFlagIfAny(Product $product): void
    {
        $product->marketShortageFlags()->active()->get()->each->resolve();
    }
}
