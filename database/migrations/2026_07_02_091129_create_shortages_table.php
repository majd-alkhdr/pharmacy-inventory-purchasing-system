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
        Schema::create('shortages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->integer('quantity'); // كمية النقص الحالية
            // الحالات: pending (تنتظر بضاعة)، fulfilled (تم سدها بالكامل)
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shortages');
    }
};
