<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 🎯 السجل التاريخي لآخر سعر شراء لكل مادة — نظير ProductSellingPrice
 * تماماً بنفس الفلسفة (سطر جديد بكل رفع ملف جرد مشتريات، وليس عموداً
 * واحداً يُستبدَل)، لأن CostVarianceMonitorService يحتاج مقارنة سعرين
 * متتاليين زمنياً. راجع مايجريشن 2026_08_01_000003 للتفاصيل الكاملة.
 */
class ProductPurchasePrice extends Model
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

    /** 🎯 آخر سعر شراء مسجَّل حالياً لمادة معيّنة */
    public static function latestFor(int $productId): ?float
    {
        $row = static::where('product_id', $productId)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        return $row ? (float) $row->price : null;
    }

    /**
     * 🎯 السعر الذي كان سارياً *قبل* السعر الحالي مباشرة — هذا بالضبط ما
     * يقارنه مراقب تذبذب التكلفة الجديد (CostVarianceMonitorService) لكل
     * مادة، بدل استعلامه القديم المعقّد عبر purchase_order_items.
     */
    public static function previousFor(int $productId): ?float
    {
        $rows = static::where('product_id', $productId)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->limit(2)
            ->pluck('price');

        return $rows->count() === 2 ? (float) $rows->last() : null;
    }
}
