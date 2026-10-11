<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يضيف restocking_mode لجلسة الاستيراد — القيمة التي يختارها المستخدم
 * بشاشة "رفع المبيعات والجرد اليومي" (sales / standard_inventory / hybrid).
 * تُخزَّن على الجلسة (لا تُمرَّر كمعامل Job منفصل) لأنها تحتاج أن "تنتقل"
 * تلقائياً عبر كل الفروع الممكنة لنفس الجلسة (المعالجة المباشرة، بعد
 * تأكيد استثناء الشركات، بعد حل تعارض المطابقة...) دون تعديل توقيع أي
 * Job أو Controller method لتمريرها يدوياً بكل مكان — بنفس النمط
 * المتَّبع أصلاً مع excluded_restocking_companies بهذا الجدول تحديداً.
 *
 * الافتراضي 'standard_inventory' لأنه المحرك النشط حالياً لأي جلسة قديمة
 * قيد المعالجة وقت نشر هذا التحديث (نادر لكن ممكن — جلسة بدأت قبل النشر
 * وتُكمَل بعده)، ونفس افتراضي عمود shortages.source المُضاف بمايجريشن سابقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->string('restocking_mode')->default('standard_inventory')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->dropColumn('restocking_mode');
        });
    }
};
