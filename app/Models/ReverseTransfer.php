<?php

namespace App\Models;

use App\Enums\ReverseTransferOrigin;
use App\Enums\ReverseTransferStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ReverseTransfer extends Model
{
    // 🆕 (ب.6) status/origin/cycle/executed_* — راجع مايجريشن add_execution_workflow_to_transfers.
    protected $fillable = ['pharmacy_id', 'date', 'status', 'origin', 'cycle_import_log_id', 'executed_at', 'executed_by'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => ReverseTransferStatus::class,
            'origin' => ReverseTransferOrigin::class,
            'executed_at' => 'datetime',
        ];
    }

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function items()
    {
        return $this->hasMany(ReverseTransferItem::class);
    }

    public function executor()
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function scopeOnDate(Builder $query, ?string $date): Builder
    {
        return $date ? $query->whereDate('date', $date) : $query;
    }

    public function scopeForPharmacy(Builder $query, ?int $pharmacyId): Builder
    {
        return $pharmacyId ? $query->where('pharmacy_id', $pharmacyId) : $query;
    }

    public function scopeSuggested(Builder $query): Builder
    {
        return $query->where('status', ReverseTransferStatus::Suggested);
    }

    /** 🆕 (أ.3) null = دورة "ما قبل سجل الرفع" — IS NULL لا "= NULL". */
    public function scopeInCycle(Builder $query, ?int $cycleImportLogId): Builder
    {
        return $cycleImportLogId === null
            ? $query->whereNull('cycle_import_log_id')
            : $query->where('cycle_import_log_id', $cycleImportLogId);
    }

    public function isSuggested(): bool
    {
        return $this->status === ReverseTransferStatus::Suggested;
    }
}
