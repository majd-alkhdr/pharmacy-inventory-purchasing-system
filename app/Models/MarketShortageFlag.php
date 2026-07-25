<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MarketShortageFlag extends Model
{
    protected $fillable = ['product_id', 'flagged_at', 'resolved_at', 'note'];

    protected function casts(): array
    {
        return [
            'flagged_at'  => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** 🎯 الاستعلام الأهم بهذا الموديل: كل ما هو "مقطوع من السوق" حالياً */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function isActive(): bool
    {
        return $this->resolved_at === null;
    }

    public function resolve(): void
    {
        $this->update(['resolved_at' => now()]);
    }
}
