<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يطبّق قرار المستخدم الصريح: "سنقوم بإضافة آخر سعر شراء إلى ملف الجرد
 * المرفوع، وسنعتمده لعمل مراقب تذبذب التكلفة."
 *
 * 🎓 لماذا جدول تاريخي كامل (سطر جديد بكل رفع ملف)، وليس عموداً واحداً
 * last_purchase_price على جدول products يُستبدَل بكل رفع؟
 * ------------------------------------------------------------------
 * هذه هي بالضبط النقطة التي نبّه إليها الشرح التقني قبل الكتابة: مراقب
 * تذبذب التكلفة (CostVarianceMonitorService) *بطبيعته* يحتاج مقارنة سعرين
 * مختلفين زمنياً — "آخر سعر مدفوع سابقاً" مقابل "السعر الحالي المُسجَّل".
 * لو استخدمنا عموداً واحداً يُستبدَل بكل رفع ملف جديد، فقدنا القيمة القديمة
 * *فور* الرفع التالي، ولن يبقى شيء نقارنه به — يصبح مراقب التذبذب عديم
 * الفائدة كلياً بمجرد ثاني رفع.
 *
 * هذا التصميم نسخة طبق الأصل فلسفياً (وليس حرفياً) من جدول
 * product_selling_prices الموجود أصلاً بالمشروع (مايجريشن
 * 2026_07_27_000001) — نفس المبدأ: "السعر الحالي" = آخر سطر بأحدث
 * effective_from، وكل سطر أقدم يبقى محفوظاً للمقارنة التاريخية.
 *
 * index(['product_id', 'effective_from']): يخدم الاستعلامين الأساسيين
 * لهذا الجدول: (1) "ما آخر سعر حالي لهذه المادة؟" و(2) "ما السعر الذي
 * كان سارياً قبل السعر الحالي مباشرة؟" (لحساب نسبة التذبذب).
 *
 * ⚠️ ملاحظة: لا نضع قيداً unique على product_id لوحده عمداً — يجب السماح
 * بتاريخ كامل من الأسعار المتعاقبة لنفس المادة، بنفس منطق أسعار البيع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_purchase_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->decimal('price', 10, 2);

            // 🎯 تاريخ رفع ملف جرد المشتريات الذي حمل هذا السعر تحديداً —
            // نفس فلسفة effective_from بجدول product_selling_prices.
            $table->date('effective_from');

            $table->timestamps();

            $table->index(['product_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_purchase_prices');
    }
};
