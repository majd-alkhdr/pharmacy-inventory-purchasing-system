<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 جلسة الاستيراد تحتاج عمودين لكشف المستودعات:
 *
 * - warehouse_matrix_file_path: مسار الملف. **يبقى محفوظاً حتى اكتمال الجلسة** (بخلاف
 *   بقية الملفات التي تُحذف مبكراً) لأن الاستيراد قد يتوقف بانتظار مطابقة اسم صيدلية
 *   مجهول، ثم يُعاد تشغيله كاملاً بعد قرارك — وإعادة التشغيل تحتاج الملف نفسه.
 *   (إعادة التشغيل آمنة لأن الاستيراد استبدال كامل، أي idempotent.)
 *
 * - import_diagnostics: تحذيرات الاستيراد كـ JSON (كم سالب صُفِّر، كم كسر خُفِّض...).
 *   حقل message النصي لا يتسع لمئات الحالات ولا يقبل الفرز/التنزيل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->string('warehouse_matrix_file_path')->nullable()->after('pharmacy_inventory_file_paths');
            $table->json('import_diagnostics')->nullable()->after('warehouse_matrix_file_path');
        });
    }

    public function down(): void
    {
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->dropColumn(['warehouse_matrix_file_path', 'import_diagnostics']);
        });
    }
};
