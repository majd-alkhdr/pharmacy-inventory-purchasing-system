<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 (أ.1) سجل الرفع الموحَّد — سطر لكل **ملف** مرفوع، من أي شاشة بالقسمين.
 *
 * 🎓 لماذا جدول جديد بدل توسيع import_sessions؟ import_sessions يمثّل "دفعة
 * معالجة خلفية" (قد تحوي 4 ملفات: كشف + مبيعات + ...) ويُستخدم كآلة حالات
 * لشاشات المطابقة، وأغلب شاشات الرفع المتزامنة (بيانات المواد، المخزون القياسي،
 * كل رفوعات المشتريات) لا تُنشئ جلسة أصلاً. سؤال "هل نجح ملف X؟" يحتاج حبيبة
 * أدق (ملف واحد) وتغطية أشمل (كل الشاشات). الربط بين الاثنين يبقى عبر session_id
 * (اختياري) — فتتزامن حالة الملفات مع حالة الجلسة تلقائياً (راجع RecordsImportSession).
 *
 * ⚖️ diagnostics: نفس صيغة ImportDiagnostics::toArray() بالضبط (عدّادات + عيّنات
 * محدودة السقف) — لا صيغة ثانية للتحذيرات بالنظام.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->string('file_type', 50);
            $table->string('original_filename');
            $table->string('session_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30);
            $table->unsignedInteger('rows_succeeded')->nullable();
            $table->unsignedInteger('rows_skipped')->nullable();
            $table->unsignedInteger('rows_warned')->nullable();
            $table->json('diagnostics')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // "آخر رفعة ناجحة لنوع X" — أكثر استعلام تكراراً (شارة الشاشات + تحديد دورة الفائض).
            $table->index(['file_type', 'status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_logs');
    }
};
