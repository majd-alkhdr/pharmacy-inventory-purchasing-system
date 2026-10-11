<?php

namespace App\Models;

use App\Enums\ItemAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'product_id',
        'requested_qty',
        'supplied_qty',
        'shortage_qty',
        'is_redistributed',
        'redistribution_sources', // 🆕
        'system_supplied_qty',    // 🆕 (ب.2)
        'is_manually_added',      // 🆕 (ب.2)
    ];

    protected $casts = [ // 🆕
        'is_redistributed'       => 'boolean',
        'redistribution_sources' => 'array',
        'is_manually_added'      => 'boolean',
    ];

    /**
     * 🆕 (ب.2) الكمية التي اقترحها النظام لهذا البند. NULL بالعمود = لم يُعدَّل البند قط،
     * فالكمية الحالية نفسها هي الاقتراح. بند أُضيف يدوياً اقتراحه صفر.
     */
    public function systemQuantity(): int
    {
        if ($this->is_manually_added) {
            return 0;
        }

        return (int) ($this->system_supplied_qty ?? $this->supplied_qty);
    }

    public function wasEdited(): bool
    {
        return $this->is_manually_added || ($this->system_supplied_qty !== null && (int) $this->system_supplied_qty !== (int) $this->supplied_qty);
    }

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
