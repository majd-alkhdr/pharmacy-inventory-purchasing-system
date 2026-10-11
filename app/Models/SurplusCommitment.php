<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 🎯 (أ.3) كمية محجوزة من فائض (صيدلية، مادة) ضمن دورة رفع واحدة. يُكتب ويُقرأ حصراً
 * عبر SurplusEngine — لا مستهلك آخر يستعلم هذا الجدول مباشرة (مصدر واحد للفائض).
 */
class SurplusCommitment extends Model
{
    protected $fillable = ['cycle_import_log_id', 'pharmacy_id', 'product_id', 'quantity', 'committable_type', 'committable_id', 'committed_by'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function committable()
    {
        return $this->morphTo();
    }

    /** null = دورة "ما قبل سجل الرفع" — تُطابَق بـ IS NULL لا بـ "= NULL" (الذي لا يطابق شيئاً بـ SQL). */
    public function scopeInCycle(Builder $query, ?int $cycleImportLogId): Builder
    {
        return $cycleImportLogId === null
            ? $query->whereNull('cycle_import_log_id')
            : $query->where('cycle_import_log_id', $cycleImportLogId);
    }
}
