<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CentralInventoryPrice extends Model
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

    /** 🎯 آخر سعر مسجَّل حالياً لمادة معيّنة (تقرير المخزون الحالي — دائماً "الآن") */
    public static function latestFor(int $productId): ?float
    {
        $row = static::where('product_id', $productId)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return $row ? (float) $row->price : null;
    }

    /**
     * 🩹 [إصلاح N+1] نفس نتيجة استدعاء latestFor() لكل معرّف على حدة بالضبط، لكن
     * باستعلامين ثابتين بدل استعلام واحد لكل منتج. يستخدم نفس أسلوب MAX(id)
     * بالتجميع المُتّبَع أصلاً بـ effectivePricesFor() أعلاه — ترتيب الإدراج
     * (id تصاعدياً) يطابق ترتيب (effective_from, id) هنا لأن الصفوف تُدرَج دائماً
     * بترتيب زمني، فـ MAX(id) لكل منتج يُعطي نفس "الأحدث" تماماً بلا فرز إضافي.
     *
     * @param  list<int>  $productIds
     * @return array<int, float>  معرّف المنتج ← آخر سعر مسجَّل (المنتج بلا أي سعر سابق غائب عن النتيجة)
     */
    public static function latestForMany(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $latestIds = static::whereIn('product_id', $productIds)
            ->selectRaw('MAX(id) as id')
            ->groupBy('product_id')
            ->pluck('id');

        return static::whereIn('id', $latestIds)
            ->pluck('price', 'product_id')
            ->map(fn ($p) => (float) $p)
            ->all();
    }

    /**
     * 🎯 السعر الذي كان سارياً عند تاريخ محدَّد (افتراضياً: اليوم) — نفس
     * فلسفة ProductSellingPrice::effectivePriceFor() بالضبط. يُستخدَم من
     * تقريرَي المناقلات والنواقص (أحداث بتواريخ ماضية محدَّدة).
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
     * 🎯 نسخة مُحسَّنة أداءً لعدة منتجات **بنفس التاريخ** دفعة واحدة —
     * بلا N+1. ⚠️ ملاحظة مهمة عند استهلاكها بتقرير يضم أحداثاً بتواريخ
     * مختلفة (فواتير/نواقص من أيام مختلفة): استدعِ هذه الدالة **مرة لكل
     * مجموعة صفوف تشترك بنفس التاريخ** (Group by date أولاً)، لا استدعاءً
     * واحداً لكل التقرير بتاريخ واحد افتراضي — وإلا ستحصل على سعر اليوم
     * لأحداث ماضية بالخطأ رغم استخدامك لهذه الدالة "التاريخية" شكلياً.
     *
     * @param array<int> $productIds
     * @return array<int,float> [product_id => السعر الساري بذلك التاريخ]
     */
    public static function effectivePricesFor(array $productIds, ?string $asOfDate = null): array
    {
        if (empty($productIds)) {
            return [];
        }

        $asOfDate ??= now()->toDateString();

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
