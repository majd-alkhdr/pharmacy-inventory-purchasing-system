<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 صفوف ملف المبيعات اليدوي التي لم تُطابِق أي منتج موجود بـ(الاسم +
 * الشركة) تلقائياً — بانتظار قرار المشرف الصريح بشاشة المطابقة: إما
 * ربطها بمنتج موجود مشابه (فرق تشكيل/مسافات)، أو تأكيد أنها منتج جديد
 * فعلاً. بخلاف pending_category_links (مسار واحد فقط: ربط بموجود)، هذا
 * الجدول يسمح بمسارين معاً — راجع ManualSalesRateMappingService.
 *
 * period_days مُكرَّر هنا عمداً لكل صف رغم كونه قيمة واحدة على مستوى
 * الرفعة كاملة (ليس عموداً بالملف نفسه) — نفس نمط تخزين purchase_order_id
 * بكل صف TemporaryPurchaseReceipt بدل جدول جلسة منفصل: تبسيط متعمَّد،
 * الرفعات صغيرة الحجم فعلياً، فتكلفة التكرار مهملة مقابل تبسيط الاستعلام.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_manual_sales_rates', function (Blueprint $table) {
            $table->id();
            $table->string('session_id');
            $table->string('material_name');
            $table->string('company');
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('period_days');
            $table->timestamps();

            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_manual_sales_rates');
    }
};
