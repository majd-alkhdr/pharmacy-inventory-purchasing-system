<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يحوّل pharmacy_minimum_stocks.min_reorder_gap من unsignedInteger('default 0')
 * إلى nullable — ليحمل نفس دلالة pack_size_override تماماً: NULL = "لا تجاوز،
 * استخدم افتراضي المادة" (products.min_reorder_gap، المُضاف بالمايجريشن السابق).
 *
 * ⚠️ [قرار مع فقدان معلومة موثَّق] كل صف حالي بقيمة 0 يتحوّل إلى NULL هنا.
 * السبب: قبل هذه الهجرة، 0 كانت **قيمة افتراضية بالعمود نفسه** — لا توجد أي
 * طريقة لتمييز "0 أُدخلت عمداً" عن "لم تُدخَل قط، بقيت على افتراضي العمود".
 * (المسارات التي تكتب هذا العمود صراحة — PharmacyMinimumStockImport،
 * PharmacyInventoryUploadService — كانت أصلاً تحفظ NULL للفراغ ورقماً حقيقياً
 * فقط عند ذكره صراحة بالملف؛ فالقيم الحقيقية المقصودة سليمة. المشكوك بها هي فقط
 * الصفوف التي لم يُذكر فيها هذا العمود إطلاقاً بأي رفعة سابقة.) الأثر العملي:
 * أي زوج بقيمة 0 حالياً سيتراجع بعد هذه الهجرة لافتراضي المادة الجديد (إن
 * أُدخل لاحقاً عبر ملف بيانات المواد) بدل البقاء عند صفر صراحة. إن كان لديك
 * زوج تقصد فيه صفراً حقيقياً (يعني: رمِّم عند أي فرق مهما صغر)، أعد إدخاله
 * صراحة بعد الترقية عبر شاشة "المخزون القياسي للصيدليات".
 */
return new class extends Migration
{
    public function up(): void
    {
        // 🩹 الترتيب إلزامي: العمود not-null حتى هذا السطر — تحويل صف إلى NULL قبل
        // ->change() يخالف القيد الحالي نفسه ويفشل (رسالة قاعدة البيانات صريحة بهذا).
        Schema::table('pharmacy_minimum_stocks', function ($table) {
            $table->unsignedInteger('min_reorder_gap')->nullable()->default(null)->change();
        });

        DB::table('pharmacy_minimum_stocks')->where('min_reorder_gap', 0)->update(['min_reorder_gap' => null]);
    }

    public function down(): void
    {
        DB::table('pharmacy_minimum_stocks')->whereNull('min_reorder_gap')->update(['min_reorder_gap' => 0]);

        Schema::table('pharmacy_minimum_stocks', function ($table) {
            $table->unsignedInteger('min_reorder_gap')->default(0)->change();
        });
    }
};
