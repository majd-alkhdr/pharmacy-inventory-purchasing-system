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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company'); // اسم شركة الأدوية المصنعة
            $table->string('barcode')->nullable(); // يفضل دائماً ترك مكان للباركود مستقبلاً
            $table->timestamps();

            // لضمان عدم تكرار نفس المادة لنفس الشركة
            $table->unique(['name', 'company']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
