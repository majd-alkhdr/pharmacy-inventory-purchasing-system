<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يضيف pack_size لجدول pending_inventory_items — لازم لأن "الجرد
 * المركزي" هو أيضاً المصدر الذي يُنشئ منتجات جديدة كلياً (باركود غير
 * معروف بالنظام إطلاقاً). لو ذُكر حجم عبوة لمادة جديدة بنفس السطر الذي
 * يُعرِّفها لأول مرة، يجب أن يصل هذا الرقم إلى PendingProductService
 * وقت إنشاء المنتج الفعلي لاحقاً — بدل أن يضيع بين خطوة "الرصد" وخطوة
 * "الإنشاء الفعلي" (تأكيد المستخدم بشاشة pending-inventory).
 *
 * nullable: يبقى فارغاً لو لم يُذكر بالملف؛ PendingProductService يتراجع
 * حينها للقيمة الافتراضية 0 (بلا قيد تعبئة) عند إنشاء المنتج.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_inventory_items', function (Blueprint $table) {
            $table->unsignedInteger('pack_size')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('pending_inventory_items', function (Blueprint $table) {
            $table->dropColumn('pack_size');
        });
    }
};
