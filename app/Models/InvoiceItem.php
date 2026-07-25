<?php

namespace App\Models;

use App\Enums\ItemAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = ['invoice_id', 'product_id', 'requested_qty', 'supplied_qty', 'shortage_qty'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    // ══════════════════════════════════════════════════════════
    // Scopes مشتركة — تُستخدم بالكونترولر، بالـ Invoice::scopeWithAvailability،
    // وبالتصدير (Exports) بدل تكرار where('supplied_qty','>',0) بكل مكان.
    // ══════════════════════════════════════════════════════════

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('supplied_qty', '>', 0);
    }

    public function scopeShortage(Builder $query): Builder
    {
        return $query->where('shortage_qty', '>', 0);
    }

    /** فلترة موحّدة حسب قيمة نصية قادمة من الطلب (request) */
    public function scopeAvailability(Builder $query, ?string $availability): Builder
    {
        return match ($availability) {
            'available' => $query->available(),
            'shortage'  => $query->shortage(),
            default     => $query,
        };
    }

    // ══════════════════════════════════════════════════════════
    // 🎯 Accessor يستبدل match(true){...} المكرر حرفياً بثلاث ملفات
    // (InvoiceExport, AllInvoicesExport, ومنطق مشابه بالـ blade).
    // ══════════════════════════════════════════════════════════

    public function availability(): ItemAvailability
    {
        return ItemAvailability::fromQuantities(
            $this->supplied_qty,
            $this->requested_qty,
            $this->shortage_qty
        );
    }

    public function displayQty(): int
    {
        return ItemAvailability::displayQty($this->supplied_qty, $this->shortage_qty);
    }
}
