<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 سجل دائم لـ"ما بِيع فعلياً" — مستقل تماماً عن كل من temporary_sales
 * (عابر، يُحذَف فور المعالجة) وInvoiceItem (يمثِّل "ما شُحن"، لا "ما بِيع"
 * — الاثنان مختلفان جوهرياً حين لا يُوجد رصيد مركزي كافٍ، أو حين لا يُوجد
 * ملف جرد مركزي مرفَق أصلاً بهذه الدفعة فلا شحن يحدث مطلقاً).
 *
 * 🎓 لماذا الحاجة الفعلية لهذا الجدول؟ تقرير دوران المخزون يحتاج معرفة
 * "كم بِيع فعلياً" ليحسب دوراناً حقيقياً — لا "كم شُحن"، الذي قد يكون صفراً
 * تماماً (وضع مبيعات بلا ملف جرد مركزي مرفَق، بقرار صريح لاحق: لا ترميم
 * بلا الملفين معاً) رغم أن بيعاً حقيقياً حدث فعلاً بالصيدلية. بلا هذا
 * الجدول، اختيار "سجّل مبيعاتي بلا ترميم فوري" كان يعني فقدان تلك البيانات
 * بالكامل بمجرد حذف temporary_sales — عكس الهدف المُعلَن (الاستفادة منها
 * بتقرير الدوران).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_sales_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 10, 4);
            $table->date('sale_date');
            $table->timestamps();

            $table->index(['pharmacy_id', 'product_id', 'sale_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_sales_records');
    }
};
