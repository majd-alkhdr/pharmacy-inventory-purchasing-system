<?php
// app/Services/Inventory/CentralInventoryPriceRecorderService.php

namespace App\Services\Inventory;

use App\Models\CentralInventoryPrice;

/**
 * 🎯 المسؤولية الوحيدة: مقارنة الأسعار الواردة بملف الجرد المركزي بآخر
 * سعر مسجَّل فعلياً لكل منتج، وإدراج سطر تاريخي جديد **فقط عند الاختلاف**
 * — بالضبط كما طلب صاحب المشروع صراحة ("تحديثها عند كل عملية رفع في حال
 * اختلافها"). رفع نفس الملف بنفس الأسعار مرتين لا يُنشئ صفوفاً مكرَّرة
 * بلا فائدة بجدول تاريخي من المفترض أن يعكس "متى تغيّر السعر فعلياً"،
 * لا "متى رُفع ملف".
 *
 * 🎓 لماذا خدمة منفصلة عن CentralInventoryStockService رغم استدعائهما
 * معاً من نفس المكان بنفس اللحظة تقريباً؟ راجع توثيق CentralInventoryStockService
 * نفسه: "لا تعرف شيئاً عن المبيعات، المطابقة، أو الفواتير" — تسجيل تاريخ
 * الأسعار سبب مختلف تماماً للتغيير عن "طريقة تطبيق كمية الجرد"؛ خلطهما
 * بكلاس واحد يعني أن أي تعديل مستقبلي على منطق الأسعار (مثلاً: عتبة
 * تسامح صغيرة قبل اعتبار الفرق "تغيّراً حقيقياً") يُجبرك على قراءة/تعديل
 * كلاس مسؤول أصلاً عن شيء آخر تماماً.
 */
class CentralInventoryPriceRecorderService
{
    /**
     * @param array<int, float> $pricesByProductId [product_id => السعر كما ورد بالملف]
     * @return int عدد الأسعار التي تغيّرت فعلياً وسُجِّلت كسطر جديد
     */
    public function recordChanges(array $pricesByProductId): int
    {
        if (empty($pricesByProductId)) {
            return 0;
        }

        // 🩹 [إصلاح جوهري] كانت هذه الحلقة تستدعي latestFor() **بداخلها** — استعلام
        // SELECT منفصل لكل مادة سعرها تغيّر بالملف المرفوع. بملف جرد مركزي كامل
        // (~20 ألف مادة، أغلبها بسعر مذكور)، هذا يعني عشرات الآلاف من الاستعلامات
        // المتتالية لعملية "تسجيل تاريخ سعر" فقط — بالضبط نمط N+1 الذي أُصلِح بالفعل
        // بـ PendingProductService (راجع تعليقها). الحل مطابق: استعلام دفعة واحدة
        // (latestForMany) يجلب آخر سعر لكل المواد المعنيّة معاً، ثم الحلقة تقارن من
        // مصفوفة بالذاكرة فقط — من N استعلام إلى استعلامين ثابتين.
        $latestPrices = CentralInventoryPrice::latestForMany(array_keys($pricesByProductId));

        $today = now()->toDateString();
        $recorded = 0;
        $rows = [];

        foreach ($pricesByProductId as $productId => $newPrice) {
            $latest = $latestPrices[$productId] ?? null;

            // 🎯 null (لا سعر مسجَّل إطلاقاً من قبل) أو قيمة مختلفة فعلياً —
            // كلاهما يستحق سطراً جديداً. مقارنة float مباشرة آمنة هنا لأن
            // كلا الطرفين مصدرهما نفس التقريب (round(..., 2)) دائماً.
            if ($latest === null || $latest !== $newPrice) {
                $rows[] = [
                    'product_id'     => $productId,
                    'price'           => $newPrice,
                    'effective_from' => $today,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ];
                $recorded++;
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            CentralInventoryPrice::insert($chunk);
        }

        return $recorded;
    }
}
