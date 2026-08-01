<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يُخزّن أسماء الشركات المصنّعة (products.company) التي يوزّعها هذا
 * المورد تحديداً. لماذا JSON بسيط بدل جدول Pivot منفصل؟ لأن هذه قائمة
 * وصفية (Descriptive) لا تحتاج استعلامات علائقية معقّدة (لا Join، فقط
 * "هل تحتوي هذه القائمة على قيمة معيّنة؟") — استخدامها الوحيد حالياً هو
 * كشف تداخل موردَين على نفس الشركة (راجع CrossSupplierOverlapDetector).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->json('distributed_companies')->nullable()->after('lead_time_days');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('distributed_companies');
        });
    }
};
