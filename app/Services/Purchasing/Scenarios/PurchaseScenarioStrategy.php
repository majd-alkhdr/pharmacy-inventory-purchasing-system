<?php

namespace App\Services\Purchasing\Scenarios;

use App\Models\Product;
use App\Services\Purchasing\ProductDemandCalculation;
use App\Services\Purchasing\PurchaseLineDecision;

/**
 * 🎯 نمط "الاستراتيجية" (Strategy Pattern) — التطبيق الكلاسيكي الأنسب
 * تماماً لحالتنا هنا: نفس "السؤال" (ما الكمية والمورد الأنسب؟) لكن بثلاث
 * "إجابات" مختلفة جذرياً حسب اختيار المشرف (PurchaseScenario Enum).
 *
 * بدل `match ($scenario) { ... }` ضخم داخل خدمة واحدة يكبر مع كل سيناريو
 * جديد يُضاف مستقبلاً (مخالفة صريحة لمبدأ Open/Closed)، كل سيناريو صنف
 * مستقل يُطبّق نفس هذا العقد. إضافة سيناريو رابع مستقبلاً = صنف جديد
 * ينفّذ هذا الـ Interface، دون لمس أي سيناريو موجود حالياً.
 */
interface PurchaseScenarioStrategy
{
    /**
     * @return PurchaseLineDecision|null null تعني: "لا داعي لشراء شيء الآن لهذه المادة"
     */
    public function decide(Product $product, ProductDemandCalculation $demand): ?PurchaseLineDecision;
}
