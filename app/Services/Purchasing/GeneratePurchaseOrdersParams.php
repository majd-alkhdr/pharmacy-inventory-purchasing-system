<?php

namespace App\Services\Purchasing;

use App\Enums\PurchaseScenario;

/**
 * 🎯 "مدخلات المشرف" لتوليد فواتير الشراء.
 *
 * 🆕 supplierOverrides: بعد إعادة هيكلة المشتريات — [company_name => supplier_id]
 * القيم التي اختارها المشرف يدوياً بشاشة "حل تعارض الموردين" (عند وجود
 * أكثر من مورد يفضّل نفس الشركة). تُقرأ من Session، وتُمرَّر هنا كي تبقى
 * GeneratePurchaseOrdersParams كائناً واحداً شاملاً لكل ما يحتاجه محرك
 * التوليد — بلا الحاجة لتمرير Session مباشرة داخل الخدمات.
 *
 * scenario لم يعد يُختار من المشرف — يُفرَض دائماً PurchaseScenario::PriorityBased
 * من GeneratePurchaseOrdersRequest::toParams()، لكن الحقل نفسه يبقى
 * موجوداً بالـ DTO لتوافق البنية القديمة (تخزينه بجدول purchase_orders).
 */
final class GeneratePurchaseOrdersParams
{
    public function __construct(
        public readonly int $forecastPeriodDays,
        public readonly PurchaseScenario $scenario,
        public readonly ?int $supplierId = null,
        public readonly ?float $manualBudgetOverride = null,
        public readonly array $supplierOverrides = [],
    ) {
    }
}
