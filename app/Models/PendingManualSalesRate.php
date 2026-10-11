<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PendingManualSalesRate extends Model
{
    protected $fillable = ['session_id', 'material_name', 'company', 'quantity', 'period_days'];

    public function scopeForSession(Builder $query, string $sessionId): Builder
    {
        return $query->where('session_id', $sessionId);
    }
}
