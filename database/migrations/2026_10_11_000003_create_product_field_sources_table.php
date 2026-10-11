<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 "من آخر من كتب هذا الحقل لهذه المادة؟" — سطر واحد لكل (مادة، حقل)، يُستبدَل عند كل كتابة.
 * لا يُخزِّن القيمة نفسها (القيمة في مكانها الوحيد: products / central_inventory_prices) — فقط
 * مرجعاً لسجلات النظام الموجودة: import_log_id (رفع، من سجل الرفع أ.1) أو audit_log_id (تصحيح
 * يدوي، من سجل النشاط أ.2). العرض يقرأ اسم الملف/الرافع/الوقت من تلك السجلات نفسها، فلا تتباعد
 * "آخر تحديث" بهذه الشاشة عن شاشة سجل الرفع.
 *
 * ⚠️ ليس علم حماية: لا شيء يقرأ هذا الجدول ليقرر تخطي حقل عند الرفع. الرفع يكتب فوق التصحيح دائماً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_field_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('field', 32);
            $table->foreignId('import_log_id')->nullable()->constrained('import_logs')->nullOnDelete();
            $table->foreignId('audit_log_id')->nullable()->constrained('audit_logs')->nullOnDelete();
            $table->dateTime('recorded_at');

            $table->unique(['product_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_field_sources');
    }
};
