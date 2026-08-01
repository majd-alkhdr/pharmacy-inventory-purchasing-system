<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSellingPrice extends Model
{
    protected $fillable = ['product_id', 'price', 'effective_from'];

    protected function casts(): array
    {
        return [
            'price'          => 'decimal:2',
            'effective_from' => 'date',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * 🎯 السعر الرسمي الساري لمادة معيّنة عند تاريخ محدَّد (افتراضياً: اليوم).
     * هذا ما يضمن أن أي تقرير ربحية لفترة ماضية يستخدم السعر الذي كان
     * سارياً فعلياً وقتها، وليس السعر الحالي بعد أي تحديث لاحق —
     * الدقة التاريخية التي طلبها المستخدم صراحة.
     */
    public static function effectivePriceFor(int $productId, ?string $asOfDate = null): ?float
    {
        $asOfDate ??= now()->toDateString();

        $row = static::where('product_id', $productId)
            ->whereDate('effective_from', '<=', $asOfDate)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return $row ? (float) $row->price : null;
    }

    /**
     * 🎯 نفس الفكرة لكن دفعة واحدة لعدة منتجات (لتفادي N+1 عند حساب
     * الربحية لكل منتجات فاتورة شراء كاملة دفعة واحدة).
     *
     * @param array<int> $productIds
     * @return array<int,float> [product_id => السعر الساري]
     */
    public static function effectivePricesFor(array $productIds, ?string $asOfDate = null): array
    {
        if (empty($productIds)) {
            return [];
        }

        $asOfDate ??= now()->toDateString();

        // آخر سطر سارٍ لكل منتج عبر subquery — استعلام واحد فقط، بلا حلقة.
        $latestIds = static::query()
            ->whereIn('product_id', $productIds)
            ->whereDate('effective_from', '<=', $asOfDate)
            ->selectRaw('MAX(id) as id')
            ->groupBy('product_id')
            ->pluck('id');

        return static::whereIn('id', $latestIds)->pluck('price', 'product_id')
            ->map(fn ($p) => (float) $p)->all();
    }
}
