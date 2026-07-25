<?php

namespace App\Services\Purchasing;

use App\Enums\InvoiceStatus;
use App\Models\InvoiceItem;

/**
 * 🎯 المسؤولية الوحيدة: حساب معدل الاستهلاك اليومي التاريخي لمادة معيّنة،
 * اعتماداً على بيانات invoice_items الموجودة فعلياً بالنظام (Phase A) —
 * لا حاجة لجدول "مبيعات" جديد منفصل، البيانات موجودة أصلاً.
 *
 * ⚠️ ==================== خطأ منطقي دقيق تجنّبته هنا عمداً ====================
 * قد يبدو منطقياً حساب المعدل من SUM(requested_qty) عبر *كل* أسطر
 * invoice_items لمادة معيّنة. هذا **خطأ فعلي** لسببين مختلفين:
 *
 * 1) الفواتير الإلحاقية (supplementary_invoice) تُنشأ من
 *    ShortageFulfillmentService عندما يُرمَّم نقص قديم — وrequested_qty
 *    بسطرها هو **نفس** الكمية التي كانت مطلوبة أصلاً بالفاتورة الرئيسية
 *    (processed) ولم تُشحَن وقتها. لو جمعنا الاثنتين معاً، نُحسب نفس
 *    "الطلب الحقيقي" **مرتين**. الحل: نحسب فقط من الفواتير الأساسية
 *    (InvoiceStatus::Processed)، ونستبعد الإلحاقية تماماً من هذا الحساب.
 *
 * 2) لماذا requested_qty وليس supplied_qty؟ لأن supplied_qty محدودة
 *    بالمخزون المتوفر وقتها — لو استخدمناها، أي نقص تاريخي سيُخفي
 *    الطلب الحقيقي، فيُنتج تنبؤاً أقل من الاحتياج الفعلي، فيتكرر النقص
 *    مجدداً بدورة مفرغة (Self-Reinforcing Shortage). requested_qty وحدها
 *    تمثّل **ماذا احتاجت الصيدلية فعلياً**، بغض النظر عمّا توفّر وقتها.
 * ================================================================================
 */
class ConsumptionRateCalculator
{
    public function dailyRateFor(int $productId, int $lookbackDays = 365): float
    {
        $windowStart = now()->subDays($lookbackDays)->toDateString();

        $row = InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.product_id', $productId)
            ->where('invoices.status', InvoiceStatus::Processed->value) // 🎯 استبعاد الإلحاقية عمداً — راجع الشرح أعلاه
            ->where('invoices.date', '>=', $windowStart)
            ->selectRaw('SUM(invoice_items.requested_qty) as total_requested, MIN(invoices.date) as first_date')
            ->first();

        if (!$row || !$row->total_requested) {
            return 0.0;
        }

        // 🩹 مادة جديدة نسبياً (أول ظهور لها أحدث من بداية نافذة البحث)؟
        // نقسم على الأيام المُلاحَظة فعلياً، لا على lookbackDays كاملة —
        // وإلا نُقلّل المعدل الحقيقي بشكل مصطنع لمادة لها تاريخ قصير فقط.
        $observedDays = max(1, now()->diffInDays($row->first_date) + 1);
        $effectiveDays = min($observedDays, $lookbackDays);

        return round($row->total_requested / $effectiveDays, 4);
    }
}
