<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 (أ.3) التزامات الفائض — "هذه الكمية من فائض (صيدلية، مادة) حُجزت لمناقلة نُفِّذت".
 *
 * 🎓 لماذا جدول التزامات بدل طرح الكمية من pharmacy_inventories فوراً عند التنفيذ؟
 * لقطة pharmacy_inventories هي **الحقيقة المرفوعة** لهذه الدورة — تُستبدل بالكامل
 * مع كل كشف جديد. لو عدّلناها عند كل تنفيذ (كما كان يفعل ReverseTransferRecorderService
 * سابقاً) لاختلطت "الحقيقة الميدانية" بـ"خطط التحريك"، ولأمكن طرح نفس الحركة مرتين
 * (مرة بالتعديل ومرة بالكشف التالي إن تأخر التنفيذ الفعلي). هنا الفائض المتاح =
 * فائض اللقطة − مجموع التزامات **الدورة الحالية** — والدورة الجديدة تبدأ بلا التزامات
 * تلقائياً (لا حذف، لا Job تنظيف) لأن الاستعلام مقيَّد بمعرّف الدورة.
 *
 * cycle_import_log_id: سجل الرفع (كشف/جرد صيدلية) الذي بدأ الدورة. NULL = بيانات
 * سبقت وجود سجل الرفع (أول تشغيل بعد النشر) — تُعامَل كدورة قائمة بذاتها.
 *
 * committable: بند المناقلة الذي حجز الكمية (بند عكسية أو بند بين صيدليتين).
 * فريد — نفس البند لا يُلتزَم به مرتين حتى لو أُرسل طلب التنفيذ مرتين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('surplus_commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_import_log_id')->nullable()->constrained('import_logs')->nullOnDelete();
            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->morphs('committable');
            $table->foreignId('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['committable_type', 'committable_id']);
            $table->index(['cycle_import_log_id', 'pharmacy_id', 'product_id'], 'surplus_commitments_cycle_pair_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('surplus_commitments');
    }
};
