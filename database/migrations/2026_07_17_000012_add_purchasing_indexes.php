<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 هذا الملف يستنسخ **بالضبط** نفس الأسلوب المستخدم فعلياً بمايجريشن
 * 2026_07_13_121755_add_performance_indexes.php بمشروعك: مايجريشن منفصل
 * لكل الفهارس الإضافية، محمي بدالة hasIndex() لضمان أنه "آمن للتكرار"
 * (Idempotent) — لو تعطّل تنفيذه منتصف الطريق لأي سبب، إعادة التشغيل لن
 * تُطلع خطأ "duplicate key". اتساق كامل مع أسلوبك المعتمَد، وليس نمطاً
 * جديداً.
 *
 * لماذا هذه الفهارس بالذات؟
 * - central_inventories(product_id) موجود مسبقاً — لا نكرره.
 * - inventory_batches(product_id, expiry_date): مُضاف فعلاً بالمايجريشن
 *   الأصلي؛ هنا نضيف فهرساً إضافياً على expiry_date وحدها لأن "فلتر
 *   الاستبعاد" (Exclusion Filter) سيبحث عن *كل* الدفعات القريبة من
 *   الانتهاء عبر كل المنتجات معاً دورياً (وليس منتجاً واحداً في كل مرة).
 * - purchase_order_items(product_id): يخدم استعلام "آخر سعر شراء فعلي
 *   لهذا المنتج" (مراقب تذبذب التكلفة) بسرعة.
 * - supplier_offers(starts_at, ends_at): يخدم استعلام "ما هي العروض
 *   السارية حالياً؟" المتكرر بكل مرة يُبنى فيها اقتراح شراء جديد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->hasIndex('inventory_batches', 'inventory_batches_expiry_date_index')) {
            Schema::table('inventory_batches', function (Blueprint $table) {
                $table->index('expiry_date');
            });
        }

        if (!$this->hasIndex('purchase_order_items', 'purchase_order_items_product_id_index')) {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                $table->index('product_id');
            });
        }

        if (!$this->hasIndex('supplier_offers', 'supplier_offers_starts_at_ends_at_index')) {
            Schema::table('supplier_offers', function (Blueprint $table) {
                $table->index(['starts_at', 'ends_at']);
            });
        }

        if (!$this->hasIndex('product_purchase_settings', 'product_purchase_settings_is_target_index')) {
            Schema::table('product_purchase_settings', function (Blueprint $table) {
                $table->index('is_target');
            });
        }

        if (!$this->hasIndex('product_purchase_settings', 'product_purchase_settings_is_stagnant_index')) {
            Schema::table('product_purchase_settings', function (Blueprint $table) {
                $table->index('is_stagnant');
            });
        }
    }

    public function down(): void
    {
        Schema::table('inventory_batches', fn (Blueprint $table) => $table->dropIndex(['expiry_date']));
        Schema::table('purchase_order_items', fn (Blueprint $table) => $table->dropIndex(['product_id']));
        Schema::table('supplier_offers', fn (Blueprint $table) => $table->dropIndex(['starts_at', 'ends_at']));
        Schema::table('product_purchase_settings', fn (Blueprint $table) => $table->dropIndex(['is_target']));
        Schema::table('product_purchase_settings', fn (Blueprint $table) => $table->dropIndex(['is_stagnant']));
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        return count(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName])) > 0;
    }
};
