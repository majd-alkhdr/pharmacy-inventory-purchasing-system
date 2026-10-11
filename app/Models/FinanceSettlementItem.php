<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 🎯 (ب.3) مادة تحتاج تسوية من المالية — تنشأ تلقائياً عند اختيار سبب فرق يتطلب ذلك.
 * الشاشة ضمن قسم العمليات فقط (لا دور مالية بالنظام) — موظف العمليات يصدّرها ويرسلها.
 */
class FinanceSettlementItem extends Model
{
    protected $fillable = ['transfer_reconciliation_item_id', 'invoice_id', 'pharmacy_id', 'product_id', 'discrepancy_reason_id', 'quantity', 'exported_at'];

    protected function casts(): array
    {
        return ['exported_at' => 'datetime'];
    }

    public function reconciliationItem()
    {
        return $this->belongsTo(TransferReconciliationItem::class, 'transfer_reconciliation_item_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function reason()
    {
        return $this->belongsTo(DiscrepancyReason::class, 'discrepancy_reason_id');
    }

    public function scopePendingExport(Builder $query): Builder
    {
        return $query->whereNull('exported_at');
    }
}
