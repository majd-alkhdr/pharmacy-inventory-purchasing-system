<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 نفس فلسفة pack_size بهذا الجدول بالضبط (راجع مايجريشن
 * 2026_08_25_061114): "الجرد المركزي" هو أيضاً المصدر الذي يُنشئ منتجات
 * جديدة كلياً بالنظام. لو ذُكر سعر لمادة جديدة بنفس السطر الذي يُعرِّفها
 * لأول مرة، يجب أن يصل هذا الرقم إلى PendingProductService وقت إنشاء
 * المنتج الفعلي — بدل أن يضيع بين خطوة "الرصد" وخطوة "الإنشاء الفعلي".
 *
 * nullable: يبقى فارغاً لو لم يُذكر بالملف؛ لا يُسجَّل أي central_inventory_prices
 * لذلك المنتج حينها (لا قيمة افتراضية للسعر كما هو الحال مع pack_size=0
 * — سعر غير معروف يجب أن يبقى "غير معروف"، لا صفراً مضلِّلاً).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_inventory_items', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->after('pack_size');
        });
    }

    public function down(): void
    {
        Schema::table('pending_inventory_items', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};
