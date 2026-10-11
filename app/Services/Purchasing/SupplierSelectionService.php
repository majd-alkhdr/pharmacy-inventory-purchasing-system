<?php

namespace App\Services\Purchasing;

use App\Models\Product;
use App\Models\SupplierCompany;

/**
 * 🎯 يطبّق قرار المستخدم بالضبط: "اختيار المورد لا يزال تلقائياً حسب
 * الشركات، ولكن في حال وجود شركات مشتركة بين أكثر من مورد، يُطلَب
 * التدخل اليدوي لاختيار مورد من موردي هذه الشركة."
 *
 * ⚠️ الاختيار اليدوي عند التعارض *مؤقت لكل عملية توليد* (Session-based)
 * فقط — لا يُحفَظ بشكل دائم. في كل عملية توليد جديدة، لو تكرر نفس
 * التعارض، يُعاد سؤال المشرف من جديد. هذا قرار صريح من صاحب المشروع.
 */
class SupplierSelectionService
{
    /**
     * @param array<string,int> $sessionOverrides [company_name => supplier_id]
     *        القيم التي اختارها المشرف يدوياً بشاشة حل التعارض لهذه الدفعة فقط.
     */
    /**
     * @param list<int> $restrictToSupplierIds 🆕 (ج.1) حصر المرشحين بالموردين الذين اختارهم المستخدم
     *        (أي من استراتيجيتي النطاق). فارغة = كل الموردين (السلوك القديم حرفياً).
     */
    public function resolveFor(Product $product, array $sessionOverrides = [], array $restrictToSupplierIds = []): SupplierSelectionResult
    {
        $company = $product->company;

        if (!$company) {
            return SupplierSelectionResult::none();
        }

        $candidates = SupplierCompany::suppliersForCompany($company);

        if ($restrictToSupplierIds !== []) {
            $candidates = $candidates->filter(fn ($s) => in_array((int) $s->id, $restrictToSupplierIds, true))->values();
        }

        if ($candidates->isEmpty()) {
            return SupplierSelectionResult::none();
        }

        if ($candidates->count() === 1) {
            return SupplierSelectionResult::resolved($candidates->first()->id);
        }

        // 🎯 تعارض فعلي: أكثر من مورد لنفس الشركة.
        // 🩹 (ج.1) الاختيار اليدوي يُقبل فقط إن كان ضمن المرشحين الحاليين — اختيار محفوظ بالجلسة
        // من توليد سابق بنطاق مختلف لا يجوز أن يُسند المادة لمورد خارج نطاق هذا التوليد.
        if (isset($sessionOverrides[$company]) && $candidates->contains('id', (int) $sessionOverrides[$company])) {
            return SupplierSelectionResult::resolved((int) $sessionOverrides[$company]);
        }

        return SupplierSelectionResult::conflict($candidates->pluck('id'));
    }

    /**
     * 🎯 اختصار مريح يُستخدم من PriorityBasedPurchaseDecisionService عندما
     * يكون التعارض قد حُلَّ مسبقاً بالكامل (override مُمرَّر صراحة) — يُرجع
     * مباشرة int|null بدل التعامل مع DTO الكامل بكل نقطة استدعاء.
     */
    public function resolveSingleSupplierFor(Product $product, array $sessionOverrides = []): ?int
    {
        $result = $this->resolveFor($product, $sessionOverrides);

        return $result->isResolved() ? $result->supplierId : null;
    }
}
