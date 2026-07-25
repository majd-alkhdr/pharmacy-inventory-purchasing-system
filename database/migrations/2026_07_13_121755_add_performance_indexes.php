<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 🩹 كل إضافة فهرس هون محاطة بشرط Schema::hasIndex() عشان الملف
        // يصير "آمن للتكرار" (idempotent) — إذا اتنفذ جزء منها سابقاً بمحاولة
        // فاشلة، إعادة التشغيل ما رح تطلع خطأ "duplicate key".

        if (!$this->hasIndex('temporary_sales', 'temporary_sales_session_id_barcode_index')) {
            Schema::table('temporary_sales', function (Blueprint $table) {
                $table->index(['session_id', 'barcode']);
            });
        }
        if (!$this->hasIndex('temporary_sales', 'temporary_sales_session_id_pharmacy_name_index')) {
            Schema::table('temporary_sales', function (Blueprint $table) {
                $table->index(['session_id', 'pharmacy_name']);
            });
        }
        if (!$this->hasIndex('temporary_sales', 'temporary_sales_session_id_pharmacy_id_index')) {
            Schema::table('temporary_sales', function (Blueprint $table) {
                $table->index(['session_id', 'pharmacy_id']);
            });
        }
        if (!$this->hasIndex('temporary_sales', 'temporary_sales_session_id_product_id_index')) {
            Schema::table('temporary_sales', function (Blueprint $table) {
                $table->index(['session_id', 'product_id']);
            });
        }

        if (!$this->hasIndex('temporary_shortages', 'temporary_shortages_session_id_barcode_index')) {
            Schema::table('temporary_shortages', function (Blueprint $table) {
                $table->index(['session_id', 'barcode']);
            });
        }
        if (!$this->hasIndex('temporary_shortages', 'temporary_shortages_session_id_pharmacy_name_index')) {
            Schema::table('temporary_shortages', function (Blueprint $table) {
                $table->index(['session_id', 'pharmacy_name']);
            });
        }
        if (!$this->hasIndex('temporary_shortages', 'temporary_shortages_session_id_pharmacy_id_index')) {
            Schema::table('temporary_shortages', function (Blueprint $table) {
                $table->index(['session_id', 'pharmacy_id']);
            });
        }
        if (!$this->hasIndex('temporary_shortages', 'temporary_shortages_session_id_product_id_index')) {
            Schema::table('temporary_shortages', function (Blueprint $table) {
                $table->index(['session_id', 'product_id']);
            });
        }

        if (!$this->hasIndex('pending_inventory_items', 'pending_inventory_items_session_id_index')) {
            Schema::table('pending_inventory_items', function (Blueprint $table) {
                $table->index('session_id');
            });
        }

        // ❌ حذفنا فهرس LOWER() تماماً — MariaDB ما بيدعم هاي الصيغة.
        // البديل: الاستعلامات يلي تستخدم LOWER(material_name)/LOWER(company)
        // بـ InventoryImportService رح تبقى تشتغل تمام بس بدون تسريع الفهرس
        // الإضافي هذا. هذا مش مشكلة كبيرة لأنه استعلام واحد لكل استيراد
        // (مش لكل صف)، فالتأثير على الأداء محدود جداً مقارنة بباقي الفهارس.

        if (!$this->hasIndex('central_inventories', 'central_inventories_product_id_index')) {
            Schema::table('central_inventories', function (Blueprint $table) {
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('temporary_sales', function (Blueprint $table) {
            $table->dropIndex(['session_id', 'barcode']);
            $table->dropIndex(['session_id', 'pharmacy_name']);
            $table->dropIndex(['session_id', 'pharmacy_id']);
            $table->dropIndex(['session_id', 'product_id']);
        });

        Schema::table('temporary_shortages', function (Blueprint $table) {
            $table->dropIndex(['session_id', 'barcode']);
            $table->dropIndex(['session_id', 'pharmacy_name']);
            $table->dropIndex(['session_id', 'pharmacy_id']);
            $table->dropIndex(['session_id', 'product_id']);
        });

        Schema::table('pending_inventory_items', function (Blueprint $table) {
            $table->dropIndex(['pending_inventory_items_session_id_index']);
        });
    }

    /**
     * دالة مساعدة بسيطة تتحقق هل الفهرس موجود مسبقاً على جدول معين
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }
};
