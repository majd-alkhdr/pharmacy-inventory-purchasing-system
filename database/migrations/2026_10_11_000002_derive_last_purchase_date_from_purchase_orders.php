<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 products.last_purchase_date يُشتَق الآن من فواتير الشراء المنفَّذة بالنظام نفسه، لا من أي ملف مرفوع.
 *
 * "منفَّذة" = سطر فاتورة شراء استُلم منه شيء فعلاً (received_qty > 0). تاريخ الشراء = وقت آخر استلام
 * لذلك السطر — يُحفظ بعمود جديد purchase_order_items.received_at (لم يكن بالنظام أي تاريخ استلام).
 *
 * التعبئة الرجعية: السطور المستلمة قبل هذا العمود لا تملك وقت استلام حقيقياً، فيُستخدم updated_at
 * لفاتورتها كأقرب تقدير متاح. ثم يُعاد حساب last_purchase_date لكل المواد من هذه البيانات فقط —
 * المادة التي لم تُستلَم بأي فاتورة يصبح تاريخها NULL (القيمة السابقة كانت من ملف جرد المشتريات الملغى).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dateTime('received_at')->nullable()->after('received_qty');
            $table->index(['product_id', 'received_at']);
        });

        DB::table('purchase_order_items')
            ->where('received_qty', '>', 0)
            ->whereNull('received_at')
            ->update([
                'received_at' => DB::raw('(SELECT purchase_orders.updated_at FROM purchase_orders WHERE purchase_orders.id = purchase_order_items.purchase_order_id)'),
            ]);

        DB::table('products')->update([
            'last_purchase_date' => DB::raw('(SELECT DATE(MAX(poi.received_at)) FROM purchase_order_items poi WHERE poi.product_id = products.id AND poi.received_qty > 0 AND poi.received_at IS NOT NULL)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'received_at']);
            $table->dropColumn('received_at');
        });
    }
};
