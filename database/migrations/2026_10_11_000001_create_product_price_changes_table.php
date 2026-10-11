<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 سجل تغيّرات "سعر المادة" كما وردت بملف الأصناف — سطر واحد لكل تغيير فعلي فقط
 * (لا سطر عند رفع نفس السعر مجدداً). يستبدل مفهوم "آخر سعر شراء" (product_purchase_prices،
 * مصدره ملف جرد المشتريات الملغى) كمصدر لمراقب تذبذب التكلفة.
 *
 * 🎓 لماذا old_price + new_price بنفس السطر بدل سطر سعر واحد لكل تاريخ؟ مراقب التذبذب يحتاج
 * "السعر قبل آخر تغيير" مباشرة — حفظه بلحظة التغيير يُغني عن البحث عن "السطر السابق" لاحقاً،
 * ويبقى صحيحاً حتى لو كتبت مصادر أخرى على central_inventory_prices بين رفعتين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('old_price', 10, 2);
            $table->decimal('new_price', 10, 2);
            $table->dateTime('recorded_at');

            $table->index(['product_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_changes');
    }
};
