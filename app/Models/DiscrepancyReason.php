<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 🎯 (ب.3) سبب فرق مطابقة — جدول مرجعي قابل للتوسع بلا تعديل كود (راجع المايجريشن).
 * لا يوجد بالكود أي شرط باسم سبب بعينه: السلوك كله يُقرأ من requires_finance_settlement.
 */
class DiscrepancyReason extends Model
{
    protected $fillable = ['code', 'label', 'requires_finance_settlement', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'requires_finance_settlement' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('label');
    }
}
