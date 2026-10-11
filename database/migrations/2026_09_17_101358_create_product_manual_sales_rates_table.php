<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 مصدر بديل مؤقت لمعدّل الاستهلاك اليومي — يحلّ محل ConsumptionRateCalculator
 * (المعتمِد على invoice_items تاريخياً) لحين توفّر بيانات مبيعات كافية عبر
 * قسم المناقلات. راجع GeneratePurchaseOrdersParams وStandardStockRequiredQuantityProvider
 * للاستهلاك الفعلي.
 *
 * صف واحد لكل منتج (يُستبدَل بالكامل بكل رفعة/قرار مطابقة جديد) — نفس
 * فلسفة product_purchase_settings. نخزّن sales_quantity وperiod_days
 * الخامَّين معاً (لا daily_rate محسوباً) للحفاظ على قابلية إعادة البناء
 * والتدقيق لاحقاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_manual_sales_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();

            $table->unsignedInteger('sales_quantity');
            $table->unsignedInteger('period_days');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_manual_sales_rates');
    }
};
