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
        Schema::create('temporary_sales', function (Blueprint $table) {
            $table->id();
            $table->string('session_id'); // لتمييز كل ملف يتم رفعه عن الآخر
            $table->string('pharmacy_name'); // الاسم القادم من الإكسل
            $table->string('company');       // الشركة القادمة من الإكسل
            $table->string('material_name'); // المادة القادمة من الإكسل
            $table->integer('quantity');     // الكمية القادمة من الإكسل

            // هذه الحقول تبدأ فارغة (Null) ويملأها النظام بعد المطابقة اليدوية أو التلقائية
            $table->foreignId('pharmacy_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('product_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('temporary_sales');
    }
};
