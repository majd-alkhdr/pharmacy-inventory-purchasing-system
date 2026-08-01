<?php

namespace App\Services\Purchasing;

use App\Enums\TurnoverStatus;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * 🎯 يحوّل الرقم الخام الناتج عن TurnoverCalculator إلى تصنيف عملي واضح
 * يُستخدم مباشرة بمكانين مختلفين تماماً بمحرك القرار:
 *   1) PurchaseEligibilityFilter — الاستبعاد الكلي (راكد فقط)
 *   2) PurchaseLineCandidate::priorityScore() — رفع الأولوية (دوران عالٍ)
 *
 * العتبات (شهران) محسومة صراحة من قرار المستخدم — راجع وثيقة التصميم
 * §1 للتفاصيل الكاملة لكل قرار.
 *
 * 🆕 نسخة مُحسَّنة أداءً (2026-08، الجولة الثانية): أُضيفت classifyMany()
 * التي تعتمد على TurnoverCalculator::bulkCalculate() (استعلامان فقط
 * بالمجمل لأي عدد من المنتجات)، بدل classify() المفردة التي تُنفِّذ
 * استعلاماً مستقلاً لكل منتج. classify() المفردة تبقى موجودة (بلا حذف)
 * لاستخدامات مستقبلية تحتاج فحص منتج واحد بمعزل عن غيره.
 */
class StockTurnoverClassifier
{
    private const STAGNANT_THRESHOLD_MONTHS = 2.0;
    private const HIGH_TURNOVER_THRESHOLD_MONTHS = 2.0;

    public function __construct(
        private readonly TurnoverCalculator $turnoverCalculator,
    ) {
    }

    /** الحساب الدقيق لمنتج واحد فقط — راجع تنبيه الأداء بـ TurnoverCalculator::calculateFor() */
    public function classify(Product $product): TurnoverStatus
    {
        return $this->resolveStatus($this->turnoverCalculator->calculateFor($product));
    }

    /**
     * 🆕 المسار المُحسَّن — يصنّف مجموعة منتجات معاً باستعلامين فقط
     * بالمجمل (عبر TurnoverCalculator::bulkCalculate)، بدل استعلام لكل
     * منتج. هذا ما يُستدعى فعلياً من PurchaseEligibilityFilter و
     * PurchaseOrderGenerationService بعد التحسين.
     *
     * @param Collection<int, Product> $products
     * @return array<int, TurnoverStatus> [product_id => TurnoverStatus]
     */
    public function classifyMany(Collection $products): array
    {
        $turnoverByProduct = $this->turnoverCalculator->bulkCalculate($products);

        $result = [];
        foreach ($products as $product) {
            $result[$product->id] = $this->resolveStatus($turnoverByProduct[$product->id] ?? null);
        }

        return $result;
    }

    private function resolveStatus(?float $turnover): TurnoverStatus
    {
        if ($turnover === null) {
            return TurnoverStatus::TooNewToJudge;
        }

        if ($turnover > self::STAGNANT_THRESHOLD_MONTHS) {
            return TurnoverStatus::Stagnant;
        }

        if ($turnover < self::HIGH_TURNOVER_THRESHOLD_MONTHS) {
            return TurnoverStatus::HighTurnover;
        }

        return TurnoverStatus::Normal;
    }
}
