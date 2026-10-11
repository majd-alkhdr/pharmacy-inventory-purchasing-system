<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يحفظ "الباقي" الكسري المتراكم من مبيعات جزء من عبوة (بيع ظرف واحد
 * من عبوة تحوي ظرفين = 0.5، من عبوة 3 ظروف = 0.33...) لكل زوج
 * (صيدلية+مادة)، **عبر دفعات رفع منفصلة** — لا داخل دفعة واحدة فقط.
 *
 * 🎓 لماذا جدول دائم منفصل، لا مجرد حقل إضافي بجدول موجود؟ لأن هذا
 * مفهوم مختلف جوهرياً عن أي شيء آخر بالنظام: ليس "نقصاً" (Shortage —
 * فجوة بين طلب ورصيد متاح)، وليس "مخزوناً" (كمية موجودة فعلياً) — إنه
 * "جزء من طلب لم يتراكم بعد لوحدة صحيحة قابلة للشحن الفعلي". يحتاج مكانه
 * الخاص، يُقرأ ويُحدَّث بمعزل تام عن أي حساب آخر بالنظام.
 *
 * راجع SalesRemainderResolverService للخوارزمية الكاملة، وInvoiceGenerationService
 * لنقطة الاستهلاك الفعلية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_quantity_remainders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('remainder_quantity', 10, 4)->default(0);
            $table->timestamps();

            $table->unique(['pharmacy_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_quantity_remainders');
    }
};
