<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يضيف "حجم العبوة" (pack_size) لكل مادة — يُستخدم من محرك ترميم
 * الصيدليات (ParLevelReplenishmentService) وخدمة تتميم النواقص
 * (ShortageFulfillmentService) لتقريب أي فجوة/كمية شحن للأسفل لأقرب
 * مضاعف لحجم العبوة، فلا تُشحن كسور عبوات (مثال: سيرنغ تُباع مفردة لكن
 * تُرمَّم بعلبة كاملة = 100 قطعة، أو سيتامول يُباع بالظرف 0.5 لكن يُرمَّم
 * بعلبة كاملة = 1).
 *
 * 🎯 مصدر القيمة: عمود اختياري خامس بملف "الجرد المركزي" (نفس الملف الذي
 * يُنشئ المنتجات الجديدة أصلاً) — وليس ملف جرد المشتريات، بقرار صريح
 * بإبقاء قسم المشتريات منفصلاً تماماً عن قسم العمليات (المناقلات).
 *
 * القيمة الافتراضية 0 تعني "بلا قيد تعبئة" — أي مادة لم يُذكر لها حجم
 * عبوة صراحة بالملف تستمر بالسلوك القديم تماماً (الفجوة الخام = الفجوة
 * الفعّالة، بلا أي تقريب). فقط pack_size > 0 يُفعِّل منطق التقريب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('pack_size')->default(0)->after('last_purchase_date');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('pack_size');
        });
    }
};
