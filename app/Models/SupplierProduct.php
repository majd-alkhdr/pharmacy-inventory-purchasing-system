<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SupplierProduct extends Model
{
    protected $fillable = ['supplier_id', 'product_id', 'unit_cost', 'is_preferred', 'last_price_updated_at'];

    protected function casts(): array
    {
        return [
            'is_preferred'           => 'boolean',
            'unit_cost'              => 'decimal:2',
            'last_price_updated_at'  => 'datetime',
        ];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function offers()
    {
        return $this->hasMany(SupplierOffer::class);
    }

    public function scopePreferred(Builder $query): Builder
    {
        return $query->where('is_preferred', true);
    }

    /**
     * 🎯 أفضل عرض سارٍ حالياً لهذا الرابط عند كمية معيّنة. "الأفضل" هنا
     * يعني أعلى شريحة (min_quantity) تنطبق على الكمية المطلوبة — شريحة
     * أعلى تعني عادة فائدة أكبر (بونص و/أو خصم أكبر)، بافتراض أن الموردين
     * يُصمّمون عروضهم تصاعدياً. لو أردت لاحقاً مقارنة كل الشرائح المؤهَّلة
     * فعلياً بالتكلفة الصافية بدل الافتراض، استخدم offers()->active() مباشرة.
     */
    public function bestActiveOfferFor(int $quantity): ?SupplierOffer
    {
        return $this->offers()
            ->where('min_quantity', '<=', $quantity)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', now()))
            ->orderByDesc('min_quantity')
            ->first();
    }
}
