<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 حد أدنى للفرق قبل الترميم — **افتراضي على مستوى المادة**، يكمل تصميم
 * pharmacy_minimum_stocks.min_reorder_gap الموجود أصلاً (تجاوز خاص بزوج
 * صيدلية+مادة). نفس بنية pack_size / pack_size_override بالضبط:
 *
 *   القيمة الفعلية = تجاوز الزوج (إن وُجد) وإلا افتراضي المادة وإلا صفر.
 *
 * 🎓 لماذا يحتاج هذا افتراضاً على مستوى المادة أصلاً، رغم وجود عمود بالزوج
 * من قبل؟ بقرار صاحب المشروع الصريح: "الحد الأدنى للفرق" ثابت عملياً — نفس
 * القيمة تقريباً لكل الصيدليات لنفس المادة، بعكس "المخزون القياسي" الذي
 * يختلف طبيعياً بين صيدلية وأخرى (صيدلية كبيرة تحتاج مخزوناً أكبر). فرض إدخاله
 * يدوياً لكل زوج (بيانات ضخمة: صيدليات × مواد) كان عبئاً لا داعي له لقيمة
 * متكررة أصلاً. الآن: يُدخَل مرة واحدة لكل مادة (عبر ملف "بيانات المواد" — راجع
 * MaterialMasterImporter)، ويبقى عمود الزوج للحالات الاستثنائية فقط.
 *
 * `nullable` (لا `default(0)`): NULL يعني "لا افتراضي مُعدّ لهذه المادة بعد"،
 * يتراجع لصفر عند الاستخدام الفعلي (PharmacyMinimumStock::effectiveMinReorderGap())
 * — يُفرَّق هنا أيضاً بين "غير مُعدّ" و"صفر مقصود" تماماً كما بعمود الزوج.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('min_reorder_gap')->nullable()->after('pack_size');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('min_reorder_gap');
        });
    }
};
