<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يضيف عموداً جديداً pharmacy_inventory_file_paths (JSON، مصفوفة
 * مسارات) لدعم رفع عدة ملفات "جرد صيدليات" معاً بنفس دفعة الرفع الواحدة
 * (مثال: 10 صيدليات، كل واحدة بملفها الخاص).
 *
 * ⚠️ العمود القديم pharmacy_inventory_file_path (مسار واحد، نص) يبقى
 * موجوداً بلا حذف — نفس فلسفة "الترحيل التدريجي" (Strangler Fig) المتّبعة
 * أصلاً بمشروعك (راجع مايجريشن 2026_07_17_000010 لنفس المبدأ بالضبط):
 * تغيير نوع عمود موجود عبر ->change() يتطلب doctrine/dbal وقد يتصرف
 * بشكل مختلف بين SQLite (بيئة التطوير) وMySQL (الإنتاج)؛ إضافة عمود
 * جديد بجانبه أكثر أماناً وأبسط رجوعاً (down) بلا أي مخاطرة على العمود
 * القديم أو أي بيانات تاريخية مخزَّنة به.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->json('pharmacy_inventory_file_paths')->nullable()->after('pharmacy_inventory_file_path');
        });
    }

    public function down(): void
    {
        Schema::table('import_sessions', function (Blueprint $table) {
            $table->dropColumn('pharmacy_inventory_file_paths');
        });
    }
};
