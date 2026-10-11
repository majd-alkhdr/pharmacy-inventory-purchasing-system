<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 (ب.2) تعديل فاتورة المناقلة داخل التطبيق قبل تصديرها.
 *
 * invoices.exported_at: لحظة أول تصدير — بعدها تُقفل الفاتورة عن التعديل (المستودع يعمل
 * من النسخة المصدَّرة؛ تعديلها صامتاً بعد ذلك يخلق نسختين متعارضتين من "الحقيقة").
 *
 * invoice_items.system_supplied_qty: "ما اقترحه النظام" — يُملأ **عند أول تعديل فقط** (NULL
 * = لم يُعدَّل البند قط، فالكمية الحالية هي اقتراح النظام نفسه). لا نُعدِّل المولِّدات الثلاثة
 * القائمة لتكتبه عند الإنشاء — تعديل كود مُختبَر بلا حاجة.
 *
 * invoice_items.is_manually_added: بند أضافه المستخدم (لم يقترحه النظام أصلاً — اقتراحه 0).
 *
 * ⚖️ الحذف حذف فعلي للصف (لا SoftDeletes): ستة قرّاء خام لـ invoice_items (تصدير التجهيز،
 * تقارير الدوران والحركة والقيمة) كانوا سيحتاجون جميعاً whereNull('deleted_at') — ونسيان
 * واحد منهم يعيد البند المحذوف للتصدير بصمت. لقطة البند كاملة تُحفظ بسجل النشاط قبل حذفه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('exported_at')->nullable()->after('source');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->unsignedInteger('system_supplied_qty')->nullable()->after('shortage_qty');
            $table->boolean('is_manually_added')->default(false)->after('system_supplied_qty');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', fn (Blueprint $table) => $table->dropColumn(['system_supplied_qty', 'is_manually_added']));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('exported_at'));
    }
};
