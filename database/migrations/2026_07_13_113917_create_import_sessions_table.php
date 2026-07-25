<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->unique();
            $table->string('type'); // sales_and_inventory | stock_only | shortages
            // processing | needs_pending_products | needs_mapping | completed | faile
            $table->string('status')->default('processing');
            $table->text('message')->nullable(); // رسالة نجاح/خطأ نهائية
            $table->boolean('has_sales')->default(false); // 🩹 بديل session() غير المتاح داخل Job
            // مسارات الملفات المؤقتة على storage/app، تُحذف بعد اكتمال المعالجة
            $table->string('inventory_file_path')->nullable();
            $table->string('sales_file_path')->nullable();
            $table->string('shortages_file_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_sessions');
    }
};
