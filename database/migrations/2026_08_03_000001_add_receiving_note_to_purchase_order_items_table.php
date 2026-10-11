<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يخدم سيناريو "التدقيق اليدوي" الجديد بالاستلام الفعلي — يسمح للمشرف
 * بإضافة ملاحظة نصية بجانب كل سطر أثناء تسجيل الاستلام (مثال: "المورد
 * أرسل بديلاً مختلف التغليف"، "سيوافينا بالباقي الأسبوع القادم"...الخ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->text('receiving_note')->nullable()->after('cancelled_qty');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('receiving_note');
        });
    }
};
