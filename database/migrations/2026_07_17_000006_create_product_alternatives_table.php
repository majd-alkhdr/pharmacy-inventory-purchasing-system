<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 علاقة "ذاتية" (Self-Referencing Relationship) — المنتج يرتبط بمنتجات
 * أخرى من نفس جدول products بالضبط.
 *
 * ⚠️ نقطة أمانة تقنية مهمة يجب أن تعرفها: **لا توجد أي طريقة آلية موثوقة
 * لمعرفة "أن دواءين لهما نفس التركيبة الفعالة أو نفس الاستطباب"** من
 * داخل بيانات مشروعك الحالية (اسم + شركة + باركود فقط، بلا معلومة عن
 * المادة الفعالة). هذا الجدول **يُعبَّأ يدوياً** من مشرف مختص (صيدلي)
 * يحدد يدوياً "هذه المادة بديل مقبول لتلك"، وليس عبر خوارزمية تلقائية.
 * ذكرتُ هذه النقطة بوضوح هنا لأنها قرار عمل يخصك، وليس تفصيلاً تقنياً:
 * **من سيُدخل هذه البدائل يدوياً بالنظام أول مرة، وهل نحتاج شاشة إدارة
 * مخصصة لها بمرحلة لاحقة؟** (سنعود لهذا السؤال عند بناء الواجهات).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_alternatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alternative_product_id')->constrained('products')->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'alternative_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_alternatives');
    }
};
