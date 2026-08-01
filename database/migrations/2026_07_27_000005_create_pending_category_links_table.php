<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يطبّق قرار المستخدم بدقة: "التصنيف الجديد [غير الموجود بالقائمة
 * الرسمية] يُتجاهَل، ويبقى المنتج دون ربط، مع تنبيه، ويمكن عرض المنتجات
 * التي بقيت دون ربط مع إمكانية ربطها بالتصنيفات الموجودة — مثل شاشة
 * المطابقة المستخدمة سابقاً".
 *
 * ⚠️ هذا الجدول يُخزّن فقط الحالة التي **الباركود فيها معروف** (المنتج
 * موجود بالنظام) لكن **اسم التصنيف بالملف غير موجود** بقائمة categories
 * الرسمية. أما الصفوف ذات الباركود غير المعروف أصلاً فتُبلَّغ كـ"غير
 * مطابَقة" بتقرير الاستيراد مباشرة (لا يمكن ربطها بأي شيء لأننا لا نعرف
 * حتى أي منتج تخص).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_category_links', function (Blueprint $table) {
            $table->id();
            $table->string('session_id');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('requested_category_name'); // الاسم الوارد بالملف والذي لم يُطابَق
            $table->timestamps();

            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_category_links');
    }
};
