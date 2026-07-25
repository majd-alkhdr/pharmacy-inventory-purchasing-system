<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 لماذا هذا المايجريشن ضروري (اكتشاف حقيقي من قراءة الكود):
 *
 * InventoryController::invoicesIndex() ينفّذ هذا بكل مرة تُفتح فيها صفحة
 * "الفواتير الصادرة" (بدون أي فلتر إضافي من المستخدم):
 *
 *     Invoice::whereDate('date', $date)->...
 *
 * الفهرس الموجود حالياً على جدول invoices هو مركّب:
 *   ['pharmacy_id', 'created_at']  (من مايجريشن 2026_07_04)
 * وفهرس منفصل على status فقط.
 *
 * لا يوجد أي فهرس يبدأ بعمود `date` بمفرده. أي استعلام لا يُفلتر أولاً
 * بـ pharmacy_id لن يستفيد من الفهرس المركّب إطلاقاً (MySQL/MariaDB
 * تستخدم الفهرس المركّب من اليسار لليمين فقط - leftmost prefix rule).
 * أي هذا الاستعلام يعمل Full Table Scan في كل مرة يفتح فيها أي مستخدم
 * الصفحة الافتراضية — وهي الصفحة الأكثر زيارة بالنظام.
 *
 * ⚠️ هذا مايجريشن *جديد* بتاريخ لاحق، وليس تعديلاً على أي مايجريشن قديم —
 * هذا هو الأسلوب الصحيح لتعديل بنية جدول بعد أن أصبح مستخدَماً بالإنتاج.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->hasIndex('invoices', 'invoices_date_index')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->index('date');
            });
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_date_index');
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        return count(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName])) > 0;
    }
};
