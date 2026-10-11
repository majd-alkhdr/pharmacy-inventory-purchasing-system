<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 🎯 (ب.3) بند مطابقة: المُفوتَر ↔ المحضَّر فعلياً، وسبب الفرق إن وُجد. */
class TransferReconciliationItem extends Model
{
    protected $fillable = [
        'transfer_reconciliation_id', 'product_id', 'invoiced_qty', 'prepared_qty', 'difference',
        'discrepancy_reason_id', 'reason_set_by', 'reason_set_at',
    ];

    protected function casts(): array
    {
        return ['reason_set_at' => 'datetime'];
    }

    public function reconciliation()
    {
        return $this->belongsTo(TransferReconciliation::class, 'transfer_reconciliation_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function reason()
    {
        return $this->belongsTo(DiscrepancyReason::class, 'discrepancy_reason_id');
    }

    public function settlement()
    {
        return $this->hasOne(FinanceSettlementItem::class);
    }

    public function hasDifference(): bool
    {
        return $this->difference !== 0;
    }
}
