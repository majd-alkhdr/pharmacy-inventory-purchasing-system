<?php

namespace App\Models;

use App\Enums\BonusType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SupplierOffer extends Model
{
    protected $fillable = ['supplier_product_id', 'min_quantity', 'bonus_type', 'bonus_value', 'starts_at', 'ends_at', 'notes'];

    protected function casts(): array
    {
        return [
            'bonus_type' => BonusType::class,
            'starts_at'  => 'date',
            'ends_at'    => 'date',
        ];
    }

    public function supplierProduct()
    {
        return $this->belongsTo(SupplierProduct::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', now()));
    }

    public function isActiveNow(): bool
    {
        $today = now()->toDateString();

        return (!$this->starts_at || $this->starts_at->toDateString() <= $today)
            && (!$this->ends_at || $this->ends_at->toDateString() >= $today);
    }
}
