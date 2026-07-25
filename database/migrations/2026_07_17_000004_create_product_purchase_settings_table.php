<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎓 قرار تصميمي يستحق الشرح: لماذا صف واحد لكل منتج، وليس جدول
 * تاريخي (Audit Log) يسجّل "متى أصبح هذا المنتج تارجت ومتى توقف"؟
 * ------------------------------------------------------------------
 * لأن مستندك لم يطلب صراحة تتبعاً تاريخياً لحالة "التارجت" (بعكس عروض
 * الموردين supplier_offers التي تحتاج starts_at/ends_at بوضوح لأنها
 * زمنية بطبيعتها). إضافة جدول تدقيق كامل الآن دون حاجة مؤكدة هو بالضبط
 * ما حذّرنا منه بالمرحلة الأولى (قاعدة "Rule of Three" — لا تُعمّم قبل
 * ظهور الحاجة الحقيقية). لو احتجت لاحقاً معرفة "متى تغيّرت حالة كل
 * منتج"، updated_at هنا يعطيك "آخر تغيير" على الأقل، وإضافة جدول
 * سجل تاريخي كامل لاحقاً = مايجريشن إضافي واحد بسيط، وليس كسراً لأي شيء.
 *
 * is_stagnant: هذا العمود بالذات لا يُحدَّث يدوياً من المشرف، بل يُحسَب
 * تلقائياً من محرك الفلترة (Exclusion Filter) بناءً على قرب تاريخ صلاحية
 * أحدث دفعة بـ inventory_batches. أبقيناه كعمود مخزَّن (وليس محسوباً
 * فقط وقت الطلب) لأننا نحتاج الاستعلام عنه بسرعة بلوحة "الأصناف الراكدة"
 * دون حساب مُكلف على كل الدفعات بكل مرة تُفتح فيها الشاشة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_purchase_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();

            $table->boolean('is_target')->default(false);
            $table->text('target_note')->nullable();

            // تجاوز يدوي لحساب النظام الديناميكي لمخزون الأمان — NULL يعني
            // "اترك النظام يحسبها تلقائياً حسب معدل الاستهلاك".
            $table->unsignedInteger('manual_minimum_stock')->nullable();
            $table->unsignedSmallInteger('safety_stock_days')->nullable();

            $table->boolean('is_stagnant')->default(false);
            $table->timestamp('stagnant_marked_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_purchase_settings');
    }
};
