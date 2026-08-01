<?php

namespace App\Services\Purchasing;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * 🎯 "طبقتا" الاستبعاد الآن (بعد إعادة هيكلة المشتريات):
 *
 *   1) قرب انتهاء الصلاحية (كما كانت أصلاً) — product_purchase_settings.is_stagnant
 *      المُحدَّث دورياً من StagnantProductDetectionService.
 *
 *   2) دوران بطيء جداً (> شهرين) — StockTurnoverClassifier::Stagnant.
 *      سبب استبعاد *مستقل تماماً* عن السبب الأول ولا يتعارض معه.
 *
 * 🆕 نسخة مُحسَّنة أداءً (2026-08، الجولة الثانية): filterEligible() الآن
 * تستدعي StockTurnoverClassifier::classifyMany() (استعلامان بالمجمل لكل
 * المنتجات معاً)، بدل استدعاء isEligible() بحلقة (كان يعني استعلاماً
 * منفصلاً بالدوران لكل منتج على حدة — N+1 كلاسيكي). isEligible() المفردة
 * تبقى موجودة (بلا حذف) لفحص منتج واحد بمعزل عن غيره عند الحاجة.
 */
class PurchaseEligibilityFilter
{
    public function __construct(
        private readonly StockTurnoverClassifier $turnoverClassifier,
    ) {
    }

    public function eligibleProductsQuery(): Builder
    {
        return Product::query()
            ->whereDoesntHave('purchaseSetting', fn (Builder $q) => $q->where('is_stagnant', true));
    }

    /** فحص شامل (الطبقتان معاً) لمنتج واحد — راجع تنبيه الأداء أعلاه */
    public function isEligible(Product $product): bool
    {
        if ($product->purchaseSetting?->is_stagnant ?? false) {
            return false;
        }

        return !$this->turnoverClassifier->classify($product)->isExcludedFromPurchasing();
    }

    /**
     * 🎯 نسخة مُحسَّنة أداءً لفحص مجموعة منتجات معاً — تُستخدم من
     * PurchaseOrderGenerationService. تُرجع *فقط* المنتجات المؤهَّلة فعلياً
     * بعد الطبقتين، مع خريطة تصنيف الدوران الكاملة (كي لا يُضطر المُستدعي
     * لإعادة حسابها مرة أخرى لاحقاً لتحديد "دوران عالٍ" — استفادة كاملة
     * من نفس استعلامي bulkCalculate الوحيدين).
     *
     * @param Collection<int, Product> $products
     * @return array{eligible: Collection<int,Product>, turnoverStatuses: array<int,\App\Enums\TurnoverStatus>}
     */
    public function filterEligibleWithTurnover(Collection $products): array
    {
        // الطبقة الأولى (قرب الصلاحية) بالفعل مُطبَّقة على مستوى SQL عبر
        // eligibleProductsQuery() قبل الوصول هنا — لا تكرار لها.
        $turnoverStatuses = $this->turnoverClassifier->classifyMany($products);

        $eligible = $products->filter(
            fn (Product $p) => !$turnoverStatuses[$p->id]->isExcludedFromPurchasing()
        )->values();

        return ['eligible' => $eligible, 'turnoverStatuses' => $turnoverStatuses];
    }
}
