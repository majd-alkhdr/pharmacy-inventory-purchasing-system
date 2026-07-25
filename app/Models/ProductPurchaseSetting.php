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
    ];

    protected function casts(): array
    {
        return [
            'is_target'          => 'boolean',
            'is_stagnant'        => 'boolean',
            'stagnant_marked_at' => 'datetime',
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
}
