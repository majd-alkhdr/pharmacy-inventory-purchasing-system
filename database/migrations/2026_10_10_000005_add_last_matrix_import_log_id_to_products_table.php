<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 (أ.4) "هل ظهرت هذه المادة بآخر كشف مستودعات؟" — يُكتب بمعاملة الكشف نفسها.
 *
 * 🎓 لماذا عمود على products بدل حفظ قائمة رموز الكشف كـ JSON؟ الكشف يحمل عشرات
 * آلاف الرموز؛ تخزينها بكل رفعة يضخّم سجل الرفع، ومقارنتها لاحقاً تحتاج تحميلها كلها
 * بالذاكرة. العمود هنا يجعل فحص ملف الأصناف (أ.4) استعلاماً واحداً مفهرساً:
 * "رموز ملف الأصناف التي last_matrix_import_log_id لها ≠ آخر كشف ناجح".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('last_matrix_import_log_id')->nullable()->constrained('import_logs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropConstrainedForeignId('last_matrix_import_log_id'));
    }
};
