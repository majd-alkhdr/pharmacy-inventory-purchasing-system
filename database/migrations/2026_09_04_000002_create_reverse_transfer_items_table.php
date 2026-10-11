<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 quantity هنا = الكمية الفعّالة المُعادة (بعد تقريب حجم العبوة —
 * راجع ReverseTransferDetectionService/ParLevelReplenishmentService).
 *
 * standard_quantity_at_time وpharmacy_quantity_at_time: لقطة تدقيقية —
 * لا تُستهلَك بأي حساب لاحق، فقط توثّق "لماذا؟" لحظة الرصد. بلا هذين
 * العمودين، مراجعة مناقلة عكسية بعد أسبوع تعني تخمين ما كان المخزون
 * القياسي والرصيد الفعلي وقتها (قد يكونان تغيّرا منذ ذلك الحين) بدل
 * معرفتهما يقيناً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reverse_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reverse_transfer_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained();
            $table->integer('quantity'); // الكمية المُعادة فعلياً للمستودع المركزي
            $table->integer('standard_quantity_at_time'); // 🎯 لقطة تدقيقية
            $table->integer('pharmacy_quantity_at_time');  // 🎯 لقطة تدقيقية
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reverse_transfer_items');
    }
};
