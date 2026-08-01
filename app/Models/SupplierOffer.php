<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SupplierOffer extends Model
{
    protected $fillable = ['supplier_product_id', 'min_quantity', 'bonus_quantity', 'discount_percent', 'starts_at', 'ends_at', 'notes'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at'   => 'date',
        ];
    }

    public function supplierProduct()
    {
        return $this->belongsTo(SupplierProduct::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', now()));
    }

    public function isActiveNow(): bool
    {
        $today = now()->toDateString();

        return (!$this->starts_at || $this->starts_at->toDateString() <= $today)
            && (!$this->ends_at || $this->ends_at->toDateString() >= $today);
    }

    /**
     * 🎯 صيغة الحساب الموحّدة المتفَق عليها:
     *   1) سعر الوحدة بعد الخصم = السعر الأساسي × (1 - discount_percent/100)
     *   2) التكلفة الكلية لما اشتريناه فعلياً = سعر الوحدة بعد الخصم × الكمية المشتراة
     *   3) عدد الوحدات المستلَمة فعلياً = الكمية المشتراة + bonus_quantity
     *   4) التكلفة الصافية للوحدة = التكلفة الكلية ÷ عدد الوحدات المستلَمة فعلياً
     *
     * مثال "10+1 مع خصم 9%" بسعر أساسي 1.00: تُحسب 10 وحدات بـ 0.91 (=9.10
     * إجمالي)، تُستلَم 11 وحدة فعلياً → التكلفة الصافية للوحدة = 9.10/11 = 0.827.
     */
    public function effectiveUnitCost(float $listUnitCost, int $purchasedQty): float
    {
        if ($purchasedQty <= 0) {
            return $listUnitCost;
        }

        $unitCostAfterDiscount = $listUnitCost * (1 - ((float) $this->discount_percent / 100));
        $totalCost = $unitCostAfterDiscount * $purchasedQty;
        $totalUnitsReceived = $purchasedQty + $this->bonus_quantity;

        return $totalUnitsReceived > 0 ? $totalCost / $totalUnitsReceived : $unitCostAfterDiscount;
    }

    public function hasAnyBenefit(): bool
    {
        return $this->bonus_quantity > 0 || (float) $this->discount_percent > 0;
    }

    public function label(): string
    {
        $parts = [];
        if ($this->bonus_quantity > 0) {
            $parts[] = "بونص {$this->bonus_quantity}";
        }
        if ((float) $this->discount_percent > 0) {
            $parts[] = "خصم {$this->discount_percent}%";
        }

        return $parts ? implode(' + ', $parts) : 'بلا فائدة إضافية';
    }
}
