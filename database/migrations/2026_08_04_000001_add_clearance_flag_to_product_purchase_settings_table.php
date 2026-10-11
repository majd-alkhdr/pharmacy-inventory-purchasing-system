<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يسدّ فجوة كشفتها الإدارة بعد التجربة الفعلية: "صنف راكد رياضياً،
 * لكن تُجرى عليه حملة تصفية متعمَّدة — النظام قد يخطئ ويقترح إعادة
 * طلبه (لأن سرعة البيع أثناء التصفية تبدو 'دوران عالٍ' أو حتى 'نقص
 * حرج' لحظة نفاد الرصيد)، بينما النية الفعلية إنهاء وجوده لا تجديده."
 *
 * 🎓 لماذا عمودان (is_clearance + clearance_until) بدل عمود واحد؟
 * is_clearance هو المفتاح الفعلي المُستخدَم بمنطق الاستبعاد (فحص بسيط
 * وسريع). clearance_until اختياري تماماً — لو تُرك فارغاً، العلم يبقى
 * فعّالاً حتى يُلغيه المشرف يدوياً صراحة؛ لو حُدِّد تاريخ، النظام يتجاهل
 * العلم تلقائياً (بلا حاجة لأي مهمة مجدولة) بمجرد تجاوز ذلك التاريخ —
 * راجع Product::isInClearance() لمنطق الفحص الفعلي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_purchase_settings', function (Blueprint $table) {
            $table->boolean('is_clearance')->default(false)->after('is_stagnant');
            $table->date('clearance_until')->nullable()->after('is_clearance');
        });
    }

    public function down(): void
    {
        Schema::table('product_purchase_settings', function (Blueprint $table) {
            $table->dropColumn(['is_clearance', 'clearance_until']);
        });
    }
};
