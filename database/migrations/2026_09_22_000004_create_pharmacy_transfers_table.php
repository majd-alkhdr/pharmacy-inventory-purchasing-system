<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 الطلب رقم 4 (الأكبر): مناقلة **مباشرة بين صيدليتين**، بلا مرور بالمستودع
 * المركزي — لا تُنشئ هذا الجدول أي خدمة تلقائياً بمجرد رصد فرصة؛ نظام
 * الاقتراحات (InterPharmacyTransferPlanner) يقترح فقط (بلا كتابة لقاعدة
 * البيانات إطلاقاً — راجع تعليقه)، وموظف العمليات يوافق صراحة قبل أي كتابة
 * هنا أو أي تحريك فعلي للمخزون. هذا يفسِّر `status`: كل صف يبدأ Suggested،
 * ولا يتحول Completed إلا بفعل بشري صريح.
 *
 * 🎓 لماذا جدول مستقل عن reverse_transfers رغم التشابه الكبير بالبنية؟
 * reverse_transfers دائماً صيدلية→مستودع، يُنفَّذ فوراً تلقائياً بلا مراجعة
 * بشرية (جزء من محرك الترميم نفسه). هذا الجدول: صيدلية→صيدلية أخرى، **يبدأ
 * دائماً كاقتراح ينتظر قراراً بشرياً**. الفرق بالسير (Workflow) لا البنية
 * وحدها هو ما يبرِّر جدولاً منفصلاً — دمجهما كان سيفرض عمود from/to اختياري
 * غريب على reverse_transfers (أحدهما دائماً "المستودع" ثابتاً) وحالة Workflow
 * لا معنى لها هناك (المناقلة العكسية تُنفَّذ فوراً، لا "تُقتَرح" أبداً).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            $table->foreignId('to_pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            // 🎯 مُقتَرحة دائماً عند الإنشاء — راجع تعليق الكلاس. لا default هنا
            // عمداً (القيمة تُكتب صراحة دائماً بكود الإنشاء، لا تعتمد على افتراض ضمني).
            $table->string('status');
            // تاريخ التنفيذ الفعلي — NULL طالما الحالة Suggested. يُملأ فقط عند الموافقة.
            $table->date('executed_date')->nullable();
            $table->timestamps();

            $table->index(['status', 'from_pharmacy_id']);
            $table->index(['status', 'to_pharmacy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_transfers');
    }
};
