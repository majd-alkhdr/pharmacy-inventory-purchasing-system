<?php

namespace App\Models;

use App\Enums\PharmacyTransferStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PharmacyTransfer extends Model
{
    // 🆕 (ب.7) cycle_import_log_id/executed_by — راجع مايجريشن add_execution_workflow_to_transfers.
    protected $fillable = ['from_pharmacy_id', 'to_pharmacy_id', 'status', 'executed_date', 'cycle_import_log_id', 'executed_by'];

    protected function casts(): array
    {
        return [
            'status' => PharmacyTransferStatus::class,
            'executed_date' => 'date',
        ];
    }

    public function fromPharmacy()
    {
        return $this->belongsTo(Pharmacy::class, 'from_pharmacy_id');
    }

    public function toPharmacy()
    {
        return $this->belongsTo(Pharmacy::class, 'to_pharmacy_id');
    }

    public function items()
    {
        return $this->hasMany(PharmacyTransferItem::class);
    }

    public function scopeSuggested(Builder $query): Builder
    {
        return $query->where('status', PharmacyTransferStatus::Suggested);
    }

    public function executor()
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    /** 🆕 (أ.3) null = دورة "ما قبل سجل الرفع" — IS NULL لا "= NULL". */
    public function scopeInCycle(Builder $query, ?int $cycleImportLogId): Builder
    {
        return $cycleImportLogId === null
            ? $query->whereNull('cycle_import_log_id')
            : $query->where('cycle_import_log_id', $cycleImportLogId);
    }

    public function scopeInvolvingPharmacy(Builder $query, ?int $pharmacyId): Builder
    {
        return $pharmacyId
            ? $query->where(fn (Builder $q) => $q->where('from_pharmacy_id', $pharmacyId)->orWhere('to_pharmacy_id', $pharmacyId))
            : $query;
    }
}
