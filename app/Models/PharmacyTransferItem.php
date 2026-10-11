<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PharmacyTransferItem extends Model
{
    protected $fillable = [
        'pharmacy_transfer_id',
        'product_id',
        'quantity',
        'from_pharmacy_excess_at_time',
        'to_pharmacy_gap_at_time',
        'execution_status', // 🆕 (ب.7)
    ];

    protected function casts(): array
    {
        return ['execution_status' => \App\Enums\TransferItemExecutionStatus::class];
    }

    public function pharmacyTransfer()
    {
        return $this->belongsTo(PharmacyTransfer::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
