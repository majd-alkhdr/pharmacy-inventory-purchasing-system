<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductPurchaseSetting extends Model
{
    protected $fillable = [
        'product_id', 'is_target', 'target_note',
        'manual_minimum_stock', 'safety_stock_days',
        'is_stagnant', 'stagnant_marked_at',
        'is_clearance', 'clearance_until', // 🆕
    ];

    protected function casts(): array
    {
        return [
            'is_target'          => 'boolean',
            'is_stagnant'        => 'boolean',
            'stagnant_marked_at' => 'datetime',
            'is_clearance'        => 'boolean', // 🆕
            'clearance_until'     => 'date',      // 🆕
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeTargetItems(Builder $query): Builder
    {
        return $query->where('is_target', true);
    }

    public function scopeStagnant(Builder $query): Builder
    {
        return $query->where('is_stagnant', true);
    }

    /**
     * 🆕 كل الإعدادات "قيد التصفية حالياً" فعلياً — تستثني تلقائياً أي
     * سطر انتهى تاريخ صلاحية علمه (clearance_until) دون أن يُلغيه أحد
     * يدوياً بعد. راجع Product::isInClearance() للمنطق المطابق على
     * مستوى منتج واحد.
     */
    public function scopeActivelyInClearance(Builder $query): Builder
    {
        return $query->where('is_clearance', true)
            ->where(fn (Builder $q) => $q->whereNull('clearance_until')->orWhereDate('clearance_until', '>=', now()->toDateString()));
    }
}
