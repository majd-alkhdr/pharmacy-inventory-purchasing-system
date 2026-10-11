<?php

declare(strict_types=1);

namespace App\Services\Inventory\Surplus;

/**
 * 🎯 لقطة فائض كاملة محسوبة **مرة واحدة** لكل طلب شاشة — ثم تُسأل بالذاكرة لكل بند.
 * هذا ما يحقق "بلا استعلام مكرر منفصل في كل شاشة": شاشة بمئة بند مقترح = استعلام
 * لقطة واحد، لا مئة استعلام فائض.
 *
 * ⚠️ لقطة للقراءة/العرض فقط. قرار **التنفيذ** لا يعتمد عليها أبداً (قد تتقادم بين
 * عرض الشاشة وضغطة الزر) — SurplusEngine::commitIfAvailable() يعيد الحساب للزوج
 * المعني بقفل داخل المعاملة.
 */
final class SurplusSnapshot
{
    /** @param array<string, SurplusEntry> $entries */
    public function __construct(
        public readonly InventoryCycle $cycle,
        private readonly array $entries,
    ) {
    }

    public static function key(int $pharmacyId, int $productId): string
    {
        return "{$pharmacyId}_{$productId}";
    }

    public function entry(int $pharmacyId, int $productId): ?SurplusEntry
    {
        return $this->entries[self::key($pharmacyId, $productId)] ?? null;
    }

    public function available(int $pharmacyId, int $productId): int
    {
        return $this->entry($pharmacyId, $productId)?->available() ?? 0;
    }

    /** هل يغطي الفائض المتاح كمية بند **كاملة**؟ (لا تنفيذ جزئي لكمية بند) */
    public function covers(int $pharmacyId, int $productId, int $quantity): bool
    {
        return $quantity > 0 && $this->available($pharmacyId, $productId) >= $quantity;
    }

    /**
     * كل الصيدليات التي تملك فائضاً **متاحاً** من مادة — الأكبر أولاً (زر "مناقلات؟").
     *
     * @return list<SurplusEntry>
     */
    public function forProduct(int $productId): array
    {
        $matches = array_values(array_filter(
            $this->entries,
            fn (SurplusEntry $e): bool => $e->productId === $productId && $e->available() > 0,
        ));

        usort($matches, fn (SurplusEntry $a, SurplusEntry $b): int => $b->available() <=> $a->available());

        return $matches;
    }

    /**
     * كل الأزواج ذات الفائض المتاح، مجمَّعة حسب المادة (مدخل مخطِّط المناقلات بين الصيدليات).
     *
     * @return array<int, list<SurplusEntry>>
     */
    public function availableByProduct(): array
    {
        $grouped = [];
        foreach ($this->entries as $entry) {
            if ($entry->available() > 0) {
                $grouped[$entry->productId][] = $entry;
            }
        }

        return $grouped;
    }
}
