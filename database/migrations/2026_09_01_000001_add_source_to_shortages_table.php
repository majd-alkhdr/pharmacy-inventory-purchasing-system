<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يضيف "مصدر النقص" (source: sales / standard_inventory) لجدول
 * shortages. بقرار صريح: جدول واحد موحَّد بدل جدولين منفصلين — كل شاشات
 * ولوحات النقص الحالية (الداشبورد shortagesDashboard()، التصدير
 * ShortagesExport، ShortageFulfillmentService، Shortage::scopeFilter())
 * مبنية على جدول واحد بالفعل. تقسيمه لجدولين كان يعني تعديل كل تلك
 * الأماكن معاً (وربما مضاعفة كل استعلام يحتاج النظرة الكاملة على كل
 * النواقص بصرف النظر عن مصدرها) بلا فائدة معمارية حقيقية، طالما عمود
 * فلترة واحد يكفي لتغطية نفس الحاجة (فرز/تصدير حسب المصدر).
 *
 * القيمة الافتراضية 'standard_inventory' لأنه المحرك النشط حالياً — أي
 * صف موجود مسبقاً بالجدول سيُصنَّف تلقائياً على هذا الأساس فور تشغيل
 * هذه المايجريشن (سلوك تلقائي لقاعدة البيانات عند إضافة عمود بقيمة
 * افتراضية، بلا حاجة لأي UPDATE يدوي إضافي).
 *
 * ⚠️ الافتراضي هنا شبكة أمان فقط — كل مسار إدراج جديد بالكود
 * (ShortageRecorderService لـ sales، ParLevelReplenishmentService لـ
 * standard_inventory) يُلزَم بتمرير source صراحة، لا الاعتماد عليه.
 *
 * ملاحظة: جدول shortages_archive (المُنشأ سابقاً) لا يحمل هذا العمود
 * بعد — لا شيء بالكود حالياً يكتب إليه فعلياً (تحقّقتُ: لا استدعاء واحد
 * لـ ShortageArchive::create() بكل المشروع)، فلا داعٍ لتعديله الآن. إن
 * فُعِّلت الأرشفة الفعلية مستقبلاً، على تلك المايجريشن نقل source معها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shortages', function (Blueprint $table) {
            $table->string('source')->default('standard_inventory')->after('status');
            $table->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('shortages', function (Blueprint $table) {
            $table->dropIndex(['source', 'status']);
            $table->dropColumn('source');
        });
    }
};
