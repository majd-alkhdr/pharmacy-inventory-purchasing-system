<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->unsignedInteger('quantity');
            // 🎯 لقطات تدقيقية وقت رصد الفرصة (لا وقت الموافقة، قد تختلفان) — نفس
            // فلسفة reverse_transfer_items.standard_quantity_at_time بالضبط: تُفسِّر
            // لاحقاً "لماذا اقتُرحت هذه الكمية تحديداً" حتى لو تغيّرت الأرصدة بعدها.
            $table->unsignedInteger('from_pharmacy_excess_at_time');
            $table->unsignedInteger('to_pharmacy_gap_at_time');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_transfer_items');
    }
};
