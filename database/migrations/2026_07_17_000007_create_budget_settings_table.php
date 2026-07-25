<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يطبّق قرارك بالضبط: "حد شهري عام + تحديد يدوي اختياري لكل فاتورة
 * + إمكانية إلغاء الحد العام أو عدم تحديد أي ميزانية".
 *
 * صف واحد لكل شهر (وليس "إعداد عام واحد ثابت") — لماذا؟
 * لأن هذا يعطينا مجاناً **سجلاً تاريخياً** لكل شهر: "كم كانت الميزانية
 * بشهر 6 مقابل شهر 7؟". لو استخدمنا صفاً واحداً فقط نُعدّله كل شهر،
 * نفقد هذا التاريخ بمجرد التعديل. effective_month فريد (unique) لضمان
 * عدم وجود صفّين متضاربين لنفس الشهر.
 *
 * is_enabled = false يعني بالضبط "لا يوجد أي سقف ميزانية هذا الشهر" —
 * تطبيق مباشر لجزء "إمكانية إلغاء الحد العام" من قرارك. الجزء الثاني
 * (التحديد اليدوي الاختياري لكل فاتورة) سيُطبَّق كعمودين على جدول
 * purchase_orders نفسه (budget_cap_amount + budget_cap_source) —
 * راجع Migration رقم 9 التالية مباشرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_settings', function (Blueprint $table) {
            $table->id();
            $table->date('effective_month'); // نُخزّن دائماً اليوم الأول من الشهر (مثال: 2026-07-01)
            $table->decimal('monthly_cap_amount', 12, 2)->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('effective_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_settings');
    }
};
