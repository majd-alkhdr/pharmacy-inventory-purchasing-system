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
        return $query->executedLines()->whereHas('reverseTransfer', function (Builder $q) use ($filters) {
            // 🆕 (ب.6) تقرير "ما عاد للمستودع" يعدّ المنفَّذ فقط — المقترحات لها لوحتها المستقلة
            // بنفس الشاشة، والمرفوضة/منتهية الدورة لم تُحرّك شيئاً فلا تُحسب كمية مُعادة.
            // 🩹 التاريخ = يوم التنفيذ (ReverseTransfer::MOVEMENT_DATE_SQL)، لا يوم الاقتراح.
            $q->where('status', \App\Enums\ReverseTransferStatus::Executed)
                ->forPharmacy($filters['pharmacy_id'] ?? null)
                ->when($filters['date_from'] ?? null, fn(Builder $qq, $v) => $qq->whereRaw(ReverseTransfer::MOVEMENT_DATE_SQL.' >= ?', [$v]))
                ->when($filters['date_to'] ?? null, fn(Builder $qq, $v) => $qq->whereRaw(ReverseTransfer::MOVEMENT_DATE_SQL.' <= ?', [$v]));
        });
    }

    /**
     * 🩹 بنود تحرّكت فعلاً: منفَّذة، أو NULL (سجلات ما قبل سير التنفيذ — طُبِّق أثرها وقتها). البند
     * "superseded" (لم يعد الفائض يغطيه لحظة التنفيذ) لم يتحرّك رغم أن مستنده "منفَّذ" — كان يُحسب
     * بشاشة المناقلات العكسية وإجمالياتها بينما يستبعده التصدير والتقارير.
     */
    public function scopeExecutedLines(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('reverse_transfer_items.execution_status')
            ->orWhere('reverse_transfer_items.execution_status', \App\Enums\TransferItemExecutionStatus::Executed->value));
    }
}
