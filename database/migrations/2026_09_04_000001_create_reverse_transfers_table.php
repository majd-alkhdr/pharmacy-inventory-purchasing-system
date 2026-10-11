<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 "مناقلات عكسية" — من الصيدلية إلى المستودع المركزي، عكس اتجاه
 * invoices تماماً. بقرار معماري صريح: جدول منفصل تماماً عن invoices،
 * لا حالة (status) جديدة على نفس الجدول القديم — الأسباب:
 *
 * 1) لا مفهوم "عجز" هنا إطلاقاً (requested_qty/shortage_qty بـ
 *    invoice_items لا معنى لهما بهذا الاتجاه) — الحقول المناسبة مختلفة
 *    جوهرياً، لا مجرد إعادة تسمية.
 * 2) اتجاه حركة المخزون المركزي معكوس (يزيد لا ينقص) — خلط هذا بجدول
 *    invoices الحالي كان يعني إما فرعاً شرطياً بكل مكان يقرأ منه (تصدير
 *    الفواتير، كشف تجهيز الطلبيات، لوحة الفواتير) لتفادي معالجة صف
 *    "مناقلة عكسية" وكأنه فاتورة شحن عادية بالخطأ، أو خطر نسيان أحد تلك
 *    الأماكن فيُحتسَب بالخطأ ضمن "ما يجب تجهيزه للصيدليات" (عكس معناه
 *    الفعلي تماماً). جدول منفصل يُصفِّر هذا الخطر بالكامل من الأساس.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reverse_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained();
            $table->date('date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reverse_transfers');
    }
};
