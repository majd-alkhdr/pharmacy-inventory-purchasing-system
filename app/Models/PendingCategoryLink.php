<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PendingCategoryLink extends Model
{
    protected $fillable = ['session_id', 'product_id', 'requested_category_name'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeForSession(Builder $query, string $sessionId): Builder
    {
        return $query->where('session_id', $sessionId);
    }
}
