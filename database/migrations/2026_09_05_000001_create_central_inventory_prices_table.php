<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 سجل تاريخي لسعر كل مادة كما وَرَد بملف "الجرد المركزي" الخاص بقسم
 * العمليات (المناقلات) تحديداً — **ليس** نفس مفهوم product_purchase_prices
 * (ذاك مصدره ملف جرد المشتريات المنفصل تماماً، قسم آخر بالتطبيق).
 *
 * 🎓 لماذا جدول منفصل تماماً، لا كتابة على product_purchase_prices
 * الموجود أصلاً؟ بقرار صريح من صاحب المشروع: لا يريد اعتماد تقارير
 * المناقلات على أي ملف يُرفَع من قسم المشتريات (رفعه غير منتظم، بخلاف
 * الجرد المركزي الذي يُرفَع بشكل دائم). لو كتبنا نفس السعر على
 * product_purchase_prices من مصدرين مختلفين (ملف المشتريات + ملف الجرد
 * المركزي)، وأمكن أن يختلفا يوماً ما على نفس المنتج بنفس اليوم — لا
 * توجد إجابة واضحة "أيّهما الصحيح؟". هذا الجدول يمثِّل مفهوماً مستقلاً
 * تماماً ("السعر كما سُجِّل عبر ملف الجرد المركزي تحديداً")، يُستخدَم
 * حصراً من تقارير المناقلات، بلا أي تقاطع مع مراقب تذبذب التكلفة
 * (CostVarianceMonitorService) التابع لقسم المشتريات.
 *
 * effective_from + append-only (سطر جديد بكل تغيير سعر، لا استبدال) —
 * نفس فلسفة product_selling_prices بالضبط، للسبب نفسه: تقرير عن فترة
 * ماضية يجب أن يستخدم السعر الذي كان سارياً وقتها فعلياً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_inventory_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->date('effective_from');
            $table->timestamps();

            $table->index(['product_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_inventory_prices');
    }
};
