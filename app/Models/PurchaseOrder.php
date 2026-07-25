<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseScenario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'supplier_id', 'status', 'scenario', 'forecast_period_days',
        'generated_at', 'approved_at', 'approved_by',
        'total_estimated_cost', 'budget_cap_amount', 'budget_cap_source', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'                => PurchaseOrderStatus::class,
            'scenario'              => PurchaseScenario::class,
            'generated_at'          => 'datetime',
            'approved_at'           => 'datetime',
            'total_estimated_cost'  => 'decimal:2',
            'budget_cap_amount'     => 'decimal:2',
        ];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $status ? $query->where('status', $status) : $query;
    }

    public function scopeForSupplier(Builder $query, ?int $supplierId): Builder
    {
        return $supplierId ? $query->where('supplier_id', $supplierId) : $query;
    }

    /**
     * @param array{status?:string,supplier_id?:int} $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->status($filters['status'] ?? null)
            ->forSupplier($filters['supplier_id'] ?? null);
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /** 🎯 هل تجاوزت هذه الفاتورة سقف ميزانيتها المحدَّدة (إن وُجد أصلاً)؟ */
    public function isOverBudget(): bool
    {
        if (!$this->budget_cap_amount) {
            return false;
        }

        return $this->total_estimated_cost > $this->budget_cap_amount;
    }

    public function budgetUsagePercent(): ?float
    {
        if (!$this->budget_cap_amount || (float) $this->budget_cap_amount === 0.0) {
            return null;
        }

        return round(((float) $this->total_estimated_cost / (float) $this->budget_cap_amount) * 100, 1);
    }
}
