<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ✅ barcode موجود بجدول products لكن nullable بدون unique
        // نضيف unique constraint (بعد تنظيف أي قيم null موجودة)
        Schema::table('products', function (Blueprint $table) {
            $table->string('barcode')->nullable(false)->change(); // يصبح إلزامياً
            $table->unique('barcode'); // رمز فريد لكل مادة
        });

        // ✅ نضيف barcode لـ temporary_sales للمطابقة السريعة
        Schema::table('temporary_sales', function (Blueprint $table) {
            $table->string('barcode')->nullable()->after('material_name');
        });

        // ✅ نضيف barcode لـ pending_inventory_items
        Schema::table('pending_inventory_items', function (Blueprint $table) {
            $table->string('barcode')->nullable()->after('material_name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
            $table->string('barcode')->nullable()->change();
        });

        Schema::table('temporary_sales', function (Blueprint $table) {
            $table->dropColumn('barcode');
        });

        Schema::table('pending_inventory_items', function (Blueprint $table) {
            $table->dropColumn('barcode');
        });
    }
};
