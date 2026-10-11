<?php

namespace App\Services\Purchasing;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * 🎯 طبقات الاستبعاد النهائية (بعد كل جولات إعادة الهيكلة):
 *
 *   1) قرب انتهاء الصلاحية — is_stagnant (تلقائي، عبر StagnantProductDetectionService)
 *   2) دوران بطيء جداً (> شهرين) — StockTurnoverClassifier::Stagnant
 *   3) 🆕 "قيد التصفية" — is_clearance (يدوي، عبر ProductClearanceController)
 *
 * استثناء وحيد: نقص فعلي معلَّق بجدول shortages يتجاوز **الطبقة 2 فقط**
 * (الركود المحسوب رياضياً).
 *
 * ⚠️ قرار تصميمي صريح بخصوص التصفية: الطبقة 3 **استبعاد مطلق** — تتجاوز
 * حتى استثناء "نقص فعلي بالصيدليات" (بخلاف الركود العادي). التبرير: لو
 * قرَّر المشرف صراحة "هذا الصنف قيد تصفية متعمَّدة"، هذا قرار عمل واعٍ
 * يجب أن يبقى نافذاً بغض النظر عن أي إشارة أخرى — وإلا فقدت هذه الميزة
 * غرضها الأساسي (إيقاف إعادة الطلب أثناء التصفية مهما كانت الظروف).
 */
class PurchaseEligibilityFilter
{
    public function __construct(
        private readonly StockTurnoverClassifier $turnoverClassifier,
        private readonly PendingPharmacyShortageDetector $shortageDetector,
    ) {
    }

    public function eligibleProductsQuery(): Builder
    {
        return Product::query()
            ->whereDoesntHave('purchaseSetting', fn (Builder $q) => $q->where('is_stagnant', true));
        // 🩹 لا نفلتر is_clearance هنا على مستوى SQL عمداً — clearance_until
        // يحتاج مقارنة تاريخ ديناميكية (>=اليوم)، والفحص الدقيق (بما فيه
        // معاملة NULL كـ"بلا انتهاء") أوضح وأقل عرضة للخطأ عبر
        // Product::isInClearance() بطبقة PHP أدناه، على غرار طبقة الدوران.
    }

    public function isEligible(Product $product): bool
    {
        if ($product->purchaseSetting?->is_stagnant ?? false) {
            return false;
        }

        if ($product->isInClearance()) {
            return false; // 🆕 استبعاد مطلق — لا استثناء حتى لو نقص فعلي معلَّق
        }

        if ($this->shortageDetector->hasPendingShortage($product->id)) {
            return true;
        }

        return !$this->turnoverClassifier->classify($product)->isExcludedFromPurchasing();
    }

    /**
     * @param Collection<int, Product> $products
     * @return array{eligible: Collection<int,Product>, turnoverStatuses: array<int,\App\Enums\TurnoverStatus>}
     */
    public function filterEligibleWithTurnover(Collection $products): array
    {
        $turnoverStatuses = $this->turnoverClassifier->classifyMany($products);

        $eligible = $products->filter(function (Product $p) use ($turnoverStatuses) {
            if ($p->isInClearance()) {
                return false; // 🆕 يُفحَص أولاً، قبل أي استثناء آخر
            }

            if ($this->shortageDetector->hasPendingShortage($p->id)) {
                return true;
            }

            return !$turnoverStatuses[$p->id]->isExcludedFromPurchasing();
        })->values();

        return ['eligible' => $eligible, 'turnoverStatuses' => $turnoverStatuses];
    }
}
