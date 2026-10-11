<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🩹 [إصلاح جوهري] central_inventories لم يكن له قيد فريد على product_id — فقط فهرس
 * عادي (مايجريشن الأداء). لكن الكود يفترض غير ذلك بمكانين:
 *   CentralInventoryStockService::applyInventoryReplace()  → upsert(..., ['product_id'], ...)
 *   PendingProductService::createPendingProducts()         → upsert(..., ['product_id'], ...)
 *
 * 🎓 لماذا هذا خطأ صامت؟ Laravel على MySQL **يتجاهل** معامل الأعمدة الفريدة بـ upsert()
 * ويعتمد على الفهارس الفريدة بالجدول (MySQL: INSERT ... ON DUPLICATE KEY UPDATE).
 * بلا فهرس فريد لا يحدث "تعارض" أبداً، فيصير upsert() إدراجاً عادياً: كل رفع لجرد
 * المركزي يُضيف صفاً جديداً لكل مادة (القديم يُصفَّر فقط). الجدول ينمو ~20 ألف صف
 * بكل رفع، وكل استعلام `whereIn('product_id')->get()->keyBy('product_id')` يحمّل
 * الأصفار القديمة أيضاً ويعتمد على أن الأحدث "يفوز" بالترتيب — افتراض غير مضمون.
 *
 * الحل: (1) إبقاء أحدث صف (أكبر id) لكل مادة — القديمة صُفِّرت أصلاً بكل رفع؛ (2) قيد
 * فريد يجعل upsert() يعمل كما كُتب. الحذف بجدول مشتق (derived table) لأن MySQL يمنع
 * DELETE مع استعلام فرعي على نفس الجدول مباشرةً (خطأ 1093).
 *
 * ⚠️ على جدول ضخم (ملايين الصفوف) قد يستغرق الحذف دقائق — شغّله بوقت هادئ. للفحص قبله:
 *   SELECT COUNT(*) total, COUNT(DISTINCT product_id) distinct_products FROM central_inventories;
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'DELETE FROM central_inventories WHERE id NOT IN ('
            . 'SELECT id FROM (SELECT MAX(id) AS id FROM central_inventories GROUP BY product_id) AS keep_rows)'
        );

        Schema::table('central_inventories', function (Blueprint $table) {
            $table->unique('product_id', 'central_inventories_product_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('central_inventories', function (Blueprint $table) {
            $table->dropUnique('central_inventories_product_id_unique');
        });
    }
};
