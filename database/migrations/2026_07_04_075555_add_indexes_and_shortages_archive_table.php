<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // فهارس تسريع الاستعلامات المتكررة يومياً
        Schema::table('shortages', function (Blueprint $table) {
            $table->index(['status', 'created_at']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['pharmacy_id', 'created_at']);
            $table->index('status');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->index('product_id');
        });

        // جدول أرشيف خفيف للنواقص المنتهية صلاحيتها (بدل التضخم الدائم لجدول shortages)
        Schema::create('shortages_archive', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pharmacy_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('quantity');
            $table->string('status'); // آخر حالة كانت عليها قبل الأرشفة (pending/fulfilled)
            $table->timestamp('original_created_at'); // تاريخ نشوء النقص الأصلي (للتتبع التاريخي)
            $table->timestamp('archived_at')->useCurrent(); // تاريخ الأرشفة نفسها
        });
    }

    public function down(): void
    {
        Schema::table('shortages', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['pharmacy_id', 'created_at']);
            $table->dropIndex(['status']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropIndex(['product_id']);
        });

        Schema::dropIfExists('shortages_archive');
    }
};
