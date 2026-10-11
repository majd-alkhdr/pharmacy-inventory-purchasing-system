<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 ملف "جرد الصيدليات" الجديد يُرفَع كملف ثالث اختياري بنفس فورم "رفع
 * المبيعات والجرد اليومي" (تماماً مثل ملف المبيعات الاختياري أصلاً) —
 * وليس شاشة منفصلة. يحتاج مساره الخاص بسجل الجلسة، بنفس نمط
 * inventory_file_path وsales_file_path الموجودين أصلاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->string('pharmacy_inventory_file_path')->nullable()->after('sales_file_path');
        });
    }

    public function down(): void
    {
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->dropColumn('pharmacy_inventory_file_path');
        });
    }
};
