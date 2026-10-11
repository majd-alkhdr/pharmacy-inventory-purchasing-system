<?php

namespace App\Services\Purchasing;

use App\Enums\ShortageSource;
use App\Models\Shortage;
use Illuminate\Support\Collection;

/**
 * 🎯 نمط "النواقص" — يعتمد كلياً على ما هو مُسجَّل سلفاً بجدول shortages
 * (قسم المناقلات)، مفلتراً حسب مصدر واحد (Sales أو StandardInventory) أو
 * كليهما (sourceFilter = null، بإعادة استخدام Shortage::scopeSource()
 * الموجودة أصلاً). لا حساب معدّل استهلاك هنا إطلاقاً — الكمية المطلوبة
 * = مجموع النقص المعلَّق كما هو، بلا أي معادلة تنبؤ.
 */
class ShortageBasedRequiredQuantityProvider implements RequiredQuantityProvider
{
    public function __construct(
        private readonly ?ShortageSource $sourceFilter = null,
    ) {}

    public function bulkCalculate(Collection $products): array
    {
        $productIds = $products->pluck('id');

        $pendingByProduct = Shortage::pending()
            ->source($this->sourceFilter?->value)
            ->whereIn('product_id', $productIds)
            ->selectRaw('product_id, SUM(quantity) as total_quantity')
            ->groupBy('product_id')
            ->pluck('total_quantity', 'product_id');

        $result = [];

        foreach ($products as $product) {
            $qty = (int) ($pendingByProduct[$product->id] ?? 0);

            $result[$product->id] = new RequiredQuantityResult(
                productId: $product->id,
                requiredQty: $qty,
                explanationSummary: $qty > 0
                    ? "نقص مسجَّل معلَّق بالمناقلات ({$this->sourceLabel()}): {$qty} وحدة."
                    : 'لا يوجد نقص معلَّق مسجَّل بالمناقلات لهذه المادة.',
                explanationFactors: [
                    'source'                 => 'shortages',
                    'shortage_source_filter' => $this->sourceFilter?->value,
                    'pending_shortage_qty'   => $qty,
                ],
            );
        }

        return $result;
    }

    private function sourceLabel(): string
    {
        return $this->sourceFilter?->label() ?? 'مبيعات + مخزون قياسي معاً';
    }
}
