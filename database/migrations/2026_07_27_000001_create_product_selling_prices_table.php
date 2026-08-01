<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يمثّل "سعر البيع الرسمي" (تعرفة) لكل مادة — الطرف الثاني الناقص
 * من معادلة الربح (سعر البيع − التكلفة الصافية = الربح)، بعد أن
 * تأكّدنا أن لا شيء بالنظام الحالي يخزّن هذه القيمة إطلاقاً.
 *
 * 🎓 لماذا جدول مستقل بتاريخ سريان (وليس عمود `selling_price` واحد على
 * جدول products يُستبدَل عند كل تحديث)؟ لأن السعر يتغيّر كل ~6 أشهر،
 * وأي عمود واحد يُستبدَل يفقد فوراً القدرة على معرفة "بأي سعر كانت
 * تُباع هذه المادة فعلياً وقت بيعها الشهر الماضي" — وهذا يجعل أي تقرير
 * ربحية لفترة سابقة غير دقيق بمجرد أول تحديث سعر. نفس فلسفة
 * budget_settings الموجود أصلاً بالمشروع (سطر واحد لكل فترة سريان).
 *
 * السعر "الحالي" لأي مادة = آخر سطر بـ effective_from <= اليوم.
 * لا يوجد unique على product_id لوحده عمداً — يسمح بتاريخ كامل من
 * الأسعار المتعاقبة لنفس المادة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_selling_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->date('effective_from');
            $table->timestamps();

            // 🎯 الاستعلام الأهم بهذا الجدول: "آخر سعر سارٍ لهذه المادة الآن؟"
            $table->index(['product_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_selling_prices');
    }
};
