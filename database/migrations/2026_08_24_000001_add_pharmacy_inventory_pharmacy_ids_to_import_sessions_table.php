<?php
// database/migrations/2026_08_24_000001_add_pharmacy_inventory_pharmacy_ids_to_import_sessions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يُخزّن أرقام الصيدليات (IDs) التي تحدَّث "جرد الصيدليات" الخاص بها
 * فعلياً بجلسة رفع معيّنة — يُستخدم لتمرير هذه المعلومة عبر مسار "مواد
 * جديدة بالجرد" متعدد الخطوات (رفع → تأكيد المواد الجديدة يدوياً → عندها
 * فقط يُستدعى ParLevelReplenishmentService). نفس فلسفة عمود
 * excluded_restocking_companies الموجود أصلاً بالضبط.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->json('pharmacy_inventory_pharmacy_ids')->nullable()->after('excluded_restocking_companies');
        });
    }

    public function down(): void
    {
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->dropColumn('pharmacy_inventory_pharmacy_ids');
        });
    }
};
