<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InventoryBatch extends Model
{
    protected $fillable = ['product_id', 'quantity', 'expiry_date', 'received_at', 'purchase_order_item_id', 'batch_reference'];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'received_at' => 'date',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    /**
     * 🎯 "فلتر الاستبعاد" (Exclusion Filter) من مستندك — قسم 3، الطبقة
     * الأولى: "أي صنف تاريخ صلاحيته قريب (أقل من 6 أشهر افتراضياً) يُستبعد
     * فوراً من خوارزمية الشراء". الرقم 6 هنا بارامتر وليس ثابتاً مدفوناً،
     * تماماً كما طلب مستندك: "يُحدَّد بارامتر برمجياً".
     */
    public function scopeNearingExpiry(Builder $query, int $monthsThreshold = 6): Builder
    {
        return $query->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addMonths($monthsThreshold));
    }

    public function scopeForProduct(Builder $query, int $productId): Builder
    {
        return $query->where('product_id', $productId);
    }

    /** الدفعات القابلة فعلياً للبيع (لم تنتهِ صلاحيتها بعد) */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now()));
    }
}
