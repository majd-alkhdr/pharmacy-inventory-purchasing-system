<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 (ب.4) نوع الفواتير المطلوب توليدها من هذه الدفعة — يُخزَّن على الجلسة نفسها
 * (نفس نمط restocking_mode بالضبط): المسارات المؤجَّلة (تأكيد مواد جديدة، مطابقة
 * أسماء مبيعات) تُكمل لاحقاً بطلب HTTP مختلف، فتقرأ الاختيار من هنا لا من الطلب الأصلي.
 *
 * الافتراضي 'both' = السلوك القديم حرفياً لكل الجلسات السابقة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->string('invoice_scope', 20)->default('both')->after('restocking_mode');
        });
    }

    public function down(): void
    {
        Schema::table('import_sessions', fn (Blueprint $table) => $table->dropColumn('invoice_scope'));
    }
};
