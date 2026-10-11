<?php
// app/Services/Inventory/SalesHistoryRecorderService.php

namespace App\Services\Inventory;

use App\Models\PharmacySalesRecord;
use App\Models\TemporarySale;

/**
 * 🎯 المسؤولية الوحيدة: نسخ صفوف temporary_sales **المُطابَقة فعلياً**
 * (pharmacy_id وproduct_id غير فارغين) لسجل دائم، بصرف النظر تماماً عمّا
 * إذا كان أي ترميم أو فاتورة سيحدث بهذه الدفعة أم لا. تُستدعى **مرة واحدة
 * فقط** لكل جلسة، بعد اكتمال المطابقة (فورية أو بعد تصحيح يدوي)، **قبل**
 * أي حذف لـ temporary_sales.
 *
 * ⚠️ لا تُستدعى أكثر من مرة لنفس الجلسة — استدعاء ثانٍ سيُعيد قراءة نفس
 * الصفوف (لو لم تُحذف بعد) ويُسجِّلها مضاعَفة. كل نقاط الاستدعاء بهذا
 * المشروع (InventoryUploadService، PendingProductService، SalesMappingService)
 * مصمَّمة عمداً لاستدعائها مرة واحدة فقط، بالنقطة النهائية التي يكتمل
 * عندها الحل فعلياً لكل مسار.
 */
class SalesHistoryRecorderService
{
    public function recordFromSession(string $sessionId): int
    {
        $resolved = TemporarySale::forSession($sessionId)
            ->whereNotNull('pharmacy_id')
            ->whereNotNull('product_id')
            ->select('pharmacy_id', 'product_id', 'quantity')
            ->get();

        if ($resolved->isEmpty()) {
            return 0;
        }

        // 🎯 تجميع بالذاكرة حسب الزوج — قد يكون هناك أكثر من سطر خام لنفس
        // (صيدلية+مادة) بنفس الملف (بيع عدة مرات بنفس اليوم مثلاً)، ونريد
        // سطراً واحداً بالسجل الدائم لهذا اليوم لهذا الزوج، لا عدة سطور.
        $grouped = $resolved->groupBy(fn($row) => "{$row->pharmacy_id}_{$row->product_id}");

        $today = now()->toDateString();
        $rows = $grouped->map(function ($items) use ($today) {
            $first = $items->first();
            return [
                'pharmacy_id' => $first->pharmacy_id,
                'product_id'  => $first->product_id,
                'quantity'    => (float) $items->sum('quantity'),
                'sale_date'   => $today,
                'created_at'  => now(),
                'updated_at'  => now(),
            ];
        })->values()->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            PharmacySalesRecord::insert($chunk);
        }

        return count($rows);
    }
}
