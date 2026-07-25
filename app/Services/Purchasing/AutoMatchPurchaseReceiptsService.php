<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Facades\DB;

/**
 * 🎯 نفس فلسفة AutoMatchShortagesAction بالمرحلة الأولى بالضبط، لكن
 * بخطوتَي مطابقة بدل واحدة:
 *   1) باركود → منتج (نفس منطق كل استيراداتك الأخرى).
 *   2) منتج + فاتورة الشراء المحدَّدة → أي سطر بهذه الفاتورة يخص هذا
 *      المنتج تحديداً (matched_purchase_order_item_id).
 *
 * أي صف لا يجتاز الخطوتين معاً (باركود غير معروف، أو منتج معروف لكن
 * ليس ضمن أصناف هذه الفاتورة أصلاً) يبقى "غير مُطابَق" وينتظر تدخلاً
 * يدوياً من المستخدم بشاشة المطابقة.
 */
class AutoMatchPurchaseReceiptsService
{
    public function handle(string $sessionId): void
    {
        $this->matchProductsByBarcode($sessionId);
        $this->matchAgainstOrderItems($sessionId);
    }

    private function matchProductsByBarcode(string $sessionId): void
    {
        DB::statement("
            UPDATE temporary_purchase_receipts tpr JOIN products pr ON tpr.barcode = pr.barcode
            SET tpr.product_id = pr.id
            WHERE tpr.session_id = ? AND tpr.barcode != '' AND tpr.product_id IS NULL
        ", [$sessionId]);
    }

    private function matchAgainstOrderItems(string $sessionId): void
    {
        DB::statement("
            UPDATE temporary_purchase_receipts tpr
                JOIN purchase_order_items poi
                    ON poi.purchase_order_id = tpr.purchase_order_id
                    AND poi.product_id = tpr.product_id
            SET tpr.matched_purchase_order_item_id = poi.id
            WHERE tpr.session_id = ?
                AND tpr.product_id IS NOT NULL
                AND tpr.purchase_order_id IS NOT NULL
                AND tpr.matched_purchase_order_item_id IS NULL
        ", [$sessionId]);
    }
}
