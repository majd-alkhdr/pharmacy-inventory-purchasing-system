<?php

namespace App\Services\Purchasing\Scenarios;

use App\Enums\PurchaseScenario;

/**
 * 🎯 نقطة الوصل الوحيدة بين اختيار المشرف (PurchaseScenario Enum المُخزَّن
 * بجدول purchase_orders) وبين الصنف الفعلي الذي ينفّذ منطقه. المنسّق
 * القادم (Orchestrator) سيستدعي هذا المصنع فقط، دون معرفة أي شيء عن
 * الأصناف الثلاثة الفعلية — فصل تام بين "ماذا اختار المشرف" و"كيف يُنفَّذ
 * هذا الاختيار".
 *
 * لاحظ أن الاعتماديات (SupplierPricingResolver) تُحقن هنا عبر الـ
 * Constructor، وليست تُنشأ يدوياً بـ`new` داخل كل سيناريو — بنفس فلسفة
 * حاوية الخدمات (Service Container) المتبعة بكل مكان آخر بمشروعك.
 */
class PurchaseScenarioStrategyFactory
{
    public function __construct(
        private readonly RestockingOnlyScenarioService $restockingOnly,
        private readonly MaxProfitabilityScenarioService $maxProfitability,
        private readonly BalancedScenarioService $balanced,
    ) {
    }

    public function make(PurchaseScenario $scenario): PurchaseScenarioStrategy
    {
        return match ($scenario) {
            PurchaseScenario::RestockingOnly   => $this->restockingOnly,
            PurchaseScenario::MaxProfitability => $this->maxProfitability,
            PurchaseScenario::Balanced          => $this->balanced,
        };
    }
}
