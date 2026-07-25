<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TemporaryShortage extends Model
{
    protected $fillable = [
        'session_id',
        'pharmacy_name',
        'barcode',
        'company',
        'material_name',
        'quantity',
        'shortage_date',
        'pharmacy_id',
        'product_id',
    ];

    protected $casts = [
        'shortage_date' => 'date',
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
