<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 لماذا هذا الجدول أول شيء نبنيه بنظام المشتريات؟
 * ------------------------------------------------------------------
 * لأن كل شيء آخر بالنظام (الأسعار، العروض، فواتير الشراء، مراقبة تذبذب
 * التكلفة) يعتمد على "من هو المورد؟" كنقطة انطلاق. هذا الجدول غير موجود
 * إطلاقاً بالمشروع الحالي (تحققتُ من كل الـ Migrations المرفقة) — نظام
 * المناقلات الحالي يتعامل فقط مع "صيدلية ↔ مادة"، ولا يعرف شيئاً عن
 * "من ورّد هذه المادة أصلاً؟".
 *
 * lead_time_days: مهم جداً لمحرك التنبؤ لاحقاً — لو مورد معيّن يحتاج
 * 14 يوماً ليوصل الطلبية، يجب أن يحسب النظام هذه الفترة ضمن "متى نطلب؟"
 * وليس فقط "كم نطلب؟".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            // فترة التوريد المتوقعة بالأيام — تُستخدم بمحرك التنبؤ لحساب
            // "متى يجب أن نطلب" وليس فقط "كم يجب أن نطلب".
            $table->unsignedSmallInteger('lead_time_days')->default(7);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
