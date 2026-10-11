<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 إزالة product_purchase_prices: مفهوم "آخر سعر شراء" (مصدره ملف جرد المشتريات الملغى) أُلغي
 * بالكامل — النموذج حُذف، وسعر الوحدة بفواتير الشراء صار "سعر المادة" (central_inventory_prices)،
 * ومراقب تذبذب التكلفة صار يقرأ product_price_changes. لا شيء بالكود يقرأ هذا الجدول.
 *
 * ⚠️ الحذف يُتلف البيانات التاريخية المخزَّنة فيه (أسعار رفعات جرد المشتريات السابقة) نهائياً.
 * down() يُعيد **البنية فقط** مطابقةً لمايجريشن 2026_08_01_000003 — لا البيانات. خذ نسخة احتياطية
 * من قاعدة البيانات قبل التشغيل إن كانت هذه الأسعار تهمك.
 *
 * ℹ️ inventory_batches يبقى عمداً (مجمَّد لا مُزال): StagnantProductDetectionService والأمر المجدول
 * purchasing:detect-stagnant يقرآنه، وسيُعطى تاريخ الصلاحية مصدراً جديداً لاحقاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('product_purchase_prices');
    }

    public function down(): void
    {
        if (Schema::hasTable('product_purchase_prices')) {
            return;
        }

        Schema::create('product_purchase_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->date('effective_from');
            $table->timestamps();

            $table->index(['product_id', 'effective_from']);
        });
    }
};
