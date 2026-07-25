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
        Schema::create('temporary_shortages', function (Blueprint $table) {
            $table->id();
            $table->string('session_id');
            $table->string('pharmacy_name');
            $table->string('company')->default('-');
            $table->string('material_name');
            $table->integer('quantity');
            $table->date('shortage_date')->nullable();
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
        Schema::dropIfExists('temporary_shortages');
    }
};
