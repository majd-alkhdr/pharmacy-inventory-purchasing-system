<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TemporarySale extends Model
{
    protected $fillable = [
        'session_id',
        'pharmacy_name',
        'company',
        'material_name',
        'barcode',
        'quantity',
        'pharmacy_id',
        'product_id',
    ];

    public function scopeForSession(Builder $query, string $sessionId): Builder
    {
        return $query->where('session_id', $sessionId);
    }

    public function scopeUnmappedPharmacies(Builder $query): Builder
    {
        return $query->whereNull('pharmacy_id');
    }

    public function scopeUnmappedProducts(Builder $query): Builder
    {
        return $query->whereNull('product_id');
    }
}
