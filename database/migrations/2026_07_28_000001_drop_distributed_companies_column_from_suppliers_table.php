<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 تراجع مقصود عن قرار سابق (مايجريشن 2026_07_27_000004)، بعد نقاش
 * كشف فجوة حقيقية: العمود كان سيحتاج مزامنة يدوية من مصدرين مختلفين
 * (الاستيراد التاريخي + الربط اليدوي بشاشة الموردون والأسعار)، وأي
 * نسيان بتحديث أحدهما = بيانات قديمة (Stale) تُضلّل عرض شاشة تفاصيل
 * المورد دون أي طريقة لاكتشاف الخطأ.
 *
 * البديل: Supplier::distributedCompanies() تحسب القائمة **حيّاً** من
 * الأصل الحقيقي الوحيد (supplier_products → products.company) في كل
 * مرة تُطلَب. لا تخزين = لا مزامنة = لا احتمال تضارب. الكلفة الحسابية
 * ضئيلة جداً (استعلام DISTINCT واحد بسيط) لأن هذا الحقل معلوماتي فقط
 * (عرض بشاشة تفاصيل المورد)، وليس جزءاً من أي منطق حسابي متكرر بحلقات
 * (CrossSupplierOverlapDetector أصلاً لا يعتمد عليه — يستعلم مباشرة من
 * بيانات المسودات الفعلية).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('distributed_companies');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->json('distributed_companies')->nullable()->after('lead_time_days');
        });
    }
};
