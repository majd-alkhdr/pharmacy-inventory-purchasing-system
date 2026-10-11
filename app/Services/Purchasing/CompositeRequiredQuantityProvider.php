<?php

namespace App\Services\Purchasing;

use Illuminate\Support\Collection;

/**
 * 🎯 نمط "الاثنان معاً" — الأكبر بين نتيجتي المزوَّدين لكل منتج (قرار
 * صريح، لا جمع). يطبّق Composite بأبسط شكل ممكن: يستدعي bulkCalculate()
 * على كل مزوِّد فرعي مرة واحدة (لا مرة لكل منتج)، ثم يقارن بالذاكرة.
 */
class CompositeRequiredQuantityProvider implements RequiredQuantityProvider
{
    /** @param RequiredQuantityProvider[] $providers */
    public function __construct(
        private readonly array $providers,
    ) {}

    public function bulkCalculate(Collection $products): array
    {
        $resultsPerProvider = array_map(
            fn(RequiredQuantityProvider $p) => $p->bulkCalculate($products),
            $this->providers,
        );

        $combined = [];

        foreach ($products as $product) {
            $candidates = array_map(
                fn(array $results) => $results[$product->id],
                $resultsPerProvider,
            );

            /** @var RequiredQuantityResult $winner */
            $winner = collect($candidates)->sortByDesc(fn(RequiredQuantityResult $r) => $r->requiredQty)->first();

            $combined[$product->id] = new RequiredQuantityResult(
                productId: $product->id,
                requiredQty: $winner->requiredQty,
                explanationSummary: implode(' | ', array_map(fn(RequiredQuantityResult $r) => $r->explanationSummary, $candidates))
                    . " | ✅ اعتُمدت الكمية الأكبر: {$winner->requiredQty} وحدة.",
                explanationFactors: [
                    'source'         => 'combined',
                    'candidates'     => array_map(fn(RequiredQuantityResult $r) => $r->explanationFactors, $candidates),
                    'winning_source' => $winner->explanationFactors['source'] ?? null,
                ],
            );
        }

        return $combined;
    }
}
