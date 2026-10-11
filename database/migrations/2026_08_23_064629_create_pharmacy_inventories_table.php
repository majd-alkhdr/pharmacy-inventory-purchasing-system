<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 يمثّل الرصيد الفعلي لكل مادة **داخل كل صيدلية على حدة** — مفهوم
 * مختلف تماماً عن central_inventories (رصيد المستودع المركزي قبل
 * التوزيع). هذا الجدول يُغذَّى من ملف "جرد الصيدليات" الجديد الذي
 * يرفعه المشرف يدوياً (غالباً نتيجة جرد فعلي ميداني بكل صيدلية).
 *
 * 🎓 الاستخدام الوحيد لهذا الجدول حالياً: تقييد الترميم التلقائي —
 * راجع ShortageFulfillmentService المعدَّل. لا علاقة له بحساب الاحتياج
 * أو أي معادلة أخرى بالنظام.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();

            $table->unique(['pharmacy_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_inventories');
    }
};
