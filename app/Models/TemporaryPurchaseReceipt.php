<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TemporaryPurchaseReceipt extends Model
{
    protected $fillable = [
        'session_id', 'purchase_order_id', 'barcode', 'material_name', 'company',
        'received_qty', 'expiry_date', 'product_id', 'matched_purchase_order_item_id',
    ];

    protected function casts(): array
    {
        return ['expiry_date' => 'date'];
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function matchedItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'matched_purchase_order_item_id');
    }

    // 🎯 نفس أسماء الـ scopes الثلاثة تماماً الموجودة بـ TemporarySale
    // وTemporaryShortage — اتساق كامل يسمح لأي مطوّر يعرف أحد النموذجين
    // بفهم هذا مباشرة دون تعلّم شيء جديد.
    public function scopeForSession(Builder $query, string $sessionId): Builder
    {
        return $query->where('session_id', $sessionId);
    }

    public function scopeUnmatchedProducts(Builder $query): Builder
    {
        return $query->whereNull('product_id');
    }

    public function scopeUnmatchedItems(Builder $query): Builder
    {
        return $query->whereNull('matched_purchase_order_item_id');
    }
}
