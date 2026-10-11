<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 🩹 تصحيح بيانات: تعديل "الكمية المعتمدة" كان يُحدِّث إجمالي الفاتورة (سعر × الكمية النهائية) دون
 * إجمالي السطر نفسه، فبقي line_total_cost = سعر × الكمية **المقترحة** لكل سطر عُدِّل. يُعاد اشتقاقه هنا
 * من نفس المعادلة لكل سطر له كمية معتمدة — عمود مشتق بالكامل من عمودين آخرين بنفس الصف، فلا تُفقَد
 * أي معلومة. down() بلا أثر: القيمة السابقة كانت خطأً لا حالة تُستعاد.
 *
 * ⚠️ يُعدِّل بيانات مخزَّنة قائمة ولا يُستعاد بالتراجع — خذ نسخة احتياطية كاملة من قاعدة البيانات قبل
 * التشغيل (نفس معاملة حذف product_purchase_prices).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('purchase_order_items')
            ->whereNotNull('approved_qty')
            ->update(['line_total_cost' => DB::raw('ROUND(unit_cost * approved_qty, 2)')]);
    }

    public function down(): void
    {
        // لا شيء — راجع تعليق الكلاس.
    }
};
