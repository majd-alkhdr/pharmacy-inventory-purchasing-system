<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 هذا هو "المخرج" (Output) الذي وصفه قسم 8 من مستندك: "مسودات فواتير
 * مقترحة جاهزة للاعتماد والطباعة". كل صف هنا = فاتورة شراء واحدة مقترحة
 * لمورد واحد.
 *
 * status: نستخدم string عادي بالمايجريشن (بدل ENUM على مستوى قاعدة
 * البيانات) بنفس الأسلوب المتبع تماماً بجدولَي invoices وshortages
 * بمشروعك الحالي — والحماية الفعلية من القيم الخاطئة تأتي من PHP Enum
 * (App\Enums\PurchaseOrderStatus) على مستوى الموديل، تماماً كما فعلتَ
 * بتقرير [012]. اتساق كامل مع الأسلوب المعتمَد فعلياً بمشروعك.
 *
 * budget_cap_amount + budget_cap_source: يُطبّقان الجزء الثاني من قرارك
 * بشأن الميزانية — "تحديد يدوي اختياري لكل فاتورة". لو كانت هذه الفاتورة
 * وُلّدت وميزانيتها = من الإعداد الشهري العام، budget_cap_source='monthly'.
 * لو المشرف حدّد رقماً مخصصاً وقت التوليد لهذه الفاتورة تحديداً،
 * budget_cap_source='manual'. لو لم يُحدَّد أي سقف، كلا العمودين NULL.
 *
 * scenario: أي سيناريو استخدمه محرك السيناريوهات لتوليد هذه الفاتورة
 * بالذات (ترميم فقط / ربحية أعلى / متوازن) — جزء أساسي من "لوحة التبرير"
 * لاحقاً: "لماذا اقتُرحت هذه الكميات بالذات؟ لأن السيناريو كان X".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained();

            $table->string('status')->default('draft');
            $table->string('scenario'); // راجع App\Enums\PurchaseScenario

            $table->unsignedSmallInteger('forecast_period_days');
            $table->timestamp('generated_at');
            $table->timestamp('approved_at')->nullable();
            $table->string('approved_by')->nullable(); // اسم المستخدم — سيصبح foreignId('approved_by_user_id') عند تفعيل نظام الصلاحيات مستقبلاً (راجع توصية [4] بالتقرير التعليمي السابق)

            $table->decimal('total_estimated_cost', 12, 2)->default(0);

            $table->decimal('budget_cap_amount', 12, 2)->nullable();
            $table->string('budget_cap_source')->nullable(); // 'monthly' | 'manual' | null

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'supplier_id']);
            $table->index('generated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
