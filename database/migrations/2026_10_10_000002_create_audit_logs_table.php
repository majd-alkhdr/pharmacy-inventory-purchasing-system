<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 (أ.2) سجل النشاط الشامل — Polymorphic: يقبل أي نموذج (بند فاتورة مناقلة،
 * بند فاتورة شراء، مناقلة عكسية...) بلا عمود مخصص لكل نوع.
 *
 * 🎓 لماذا سجل مركزي واحد بدل عمود "edited_by" بكل جدول؟ أربع ميزات بالمرحلتين
 * ب وج تحتاج "من فعل ماذا ومتى وما القيمة قبل/بعد". أعمدة متفرقة كانت ستحفظ
 * **آخر** تعديل فقط (تُكتب فوقها)، وتتطلب مايجريشن لكل ميزة جديدة. هنا: كل
 * إجراء سطر مستقل غير قابل للتعديل (append-only) — لذلك لا updated_at عمداً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80)->index();
            $table->morphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            // سياق إضافي يساعد القراءة لاحقاً (رقم الفاتورة، اسم المادة وقت الإجراء...)
            // حتى لو حُذف الكائن الأصلي أو تغيّر اسمه بعدها.
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
