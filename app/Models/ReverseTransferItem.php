<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ReverseTransferItem extends Model
{
    protected $fillable = [
        'reverse_transfer_id',
        'product_id',
        'quantity',
        'standard_quantity_at_time',
        'pharmacy_quantity_at_time',
        'execution_status', // 🆕 (ب.6)
    ];

    protected function casts(): array
    {
        return ['execution_status' => \App\Enums\TransferItemExecutionStatus::class];
    }

    public function reverseTransfer()
    {
        return $this->belongsTo(ReverseTransfer::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * 🎯 نفس نمط Shortage::scopeFilter() — الفلترة عبر علاقة reverseTransfer
     * (لا عمود pharmacy_id/date مباشر بهذا الجدول، بنفس منطق invoice_items
     * التي تُفلتَر عبر invoices أصلاً).
     *
     * @param array{pharmacy_id?:int,date_from?:string,date_to?:string} $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query->whereHas('reverseTransfer', function (Builder $q) use ($filters) {
            // 🆕 (ب.6) تقرير "ما عاد للمستودع" يعدّ المنفَّذ فقط — المقترحات لها لوحتها المستقلة
            // بنفس الشاشة، والمرفوضة/منتهية الدورة لم تُحرّك شيئاً فلا تُحسب كمية مُعادة.
            $q->where('status', \App\Enums\ReverseTransferStatus::Executed)
                ->forPharmacy($filters['pharmacy_id'] ?? null)
                ->when($filters['date_from'] ?? null, fn(Builder $qq, $v) => $qq->whereDate('date', '>=', $v))
                ->when($filters['date_to'] ?? null, fn(Builder $qq, $v) => $qq->whereDate('date', '<=', $v));
        });
    }
}
