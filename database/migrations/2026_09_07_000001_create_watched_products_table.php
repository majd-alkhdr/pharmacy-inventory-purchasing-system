<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 "أصناف للمراقبة" — قائمة منتجات محدَّدة يريد صاحب المشروع متابعة كل
 * حركتها (شحن من المستودع، عودة عبر مناقلة عكسية) بتقرير مخصَّص، بمعزل
 * عن أي تقرير آخر بالنظام. جدول بسيط عمداً (فقط "أي منتج مراقَب؟") — لا
 * حالة إضافية، لا تاريخ، لا كمية. الحركة الفعلية تُقرَأ حيّة من الجداول
 * الموجودة أصلاً (InvoiceItem، ReverseTransferItem) وقت عرض التقرير، لا
 * تُنسَخ أو تُخزَّن هنا مسبقاً — راجع ProductMovementReportService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watched_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watched_products');
    }
};
