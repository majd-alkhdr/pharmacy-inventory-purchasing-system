<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يسدّ فجوة اكتُشفت بمراجعة شاشة تفاصيل الفاتورة: عند إلغاء "المتبقي"
 * من فاتورة استُلمت جزئياً (PurchaseOrderCancellationService::cancelRemaining)،
 * لا يوجد أي عمود صريح يخزّن "كم أُلغي بالضبط لكل سطر" — الكمية كانت
 * تُستنتَج ضمنياً فقط من الفرق بين ordered_qty وreceived_qty، دون أي حقل
 * معروض بالواجهة يوضّح هذا للمستخدم.
 *
 * 🎓 لماذا عمود صريح (cancelled_qty) بدل الاستمرار بالاستنتاج الضمني؟
 * لأن الاستنتاج الضمني (ordered_qty - received_qty) قد "يتحرك" لاحقاً لو
 * تغيّرت بيانات الفاتورة لأي سبب مستقبلي، بينما عمود صريح يُثبِّت القيمة
 * **كما كانت بالضبط لحظة الإلغاء** — سجل تاريخي دقيق لا يتأثر بأي تعديل
 * لاحق، بنفس فلسفة الحقول الأخرى بهذا الجدول (suggested_qty مقابل
 * approved_qty، كلاهما "لقطة" (Snapshot) لحظة معينة، وليسا محسوبين حياً).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->unsignedInteger('cancelled_qty')->default(0)->after('received_qty');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('cancelled_qty');
        });
    }
};
