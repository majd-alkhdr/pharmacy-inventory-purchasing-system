<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['barcode', 'name', 'company'];

    public static function findByBarcode(string $barcode): ?self
    {
        return static::where('barcode', $barcode)->first();
    }

    /** 🎯 نُقل من InventoryController::searchProducts() */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'LIKE', "%{$term}%")
                ->orWhere('company', 'LIKE', "%{$term}%");
        });
    }

    public function toSelect2Option(): array
    {
        return ['id' => $this->id, 'text' => "{$this->name} ({$this->company})"];
    }

    // ══════════════════════════════════════════════════════════
    // 🆕 علاقات نظام المشتريات (Phase B) — لا تؤثر إطلاقاً على أي
    // علاقة أو منطق موجود مسبقاً بهذا الموديل من Phase A.
    // ══════════════════════════════════════════════════════════

    public function supplierProducts()
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function inventoryBatches()
    {
        return $this->hasMany(InventoryBatch::class);
    }

    /** 🎯 إعدادات الشراء الخاصة به (تارجت/حد أدنى يدوي/راكد) — علاقة 1-1 */
    public function purchaseSetting()
    {
        return $this->hasOne(ProductPurchaseSetting::class);
    }

    public function alternatives()
    {
        return $this->hasMany(ProductAlternative::class);
    }

    public function marketShortageFlags()
    {
        return $this->hasMany(MarketShortageFlag::class);
    }

    /** هل هذا المنتج "مقطوع من السوق" حالياً؟ (بدون الحاجة لاستعلام يدوي منفصل بكل شاشة) */
    public function isMarketShortage(): bool
    {
        return $this->marketShortageFlags()->active()->exists();
    }

    /** هل هذا المنتج مُعلَّم "تارجت" حالياً؟ */
    public function isTarget(): bool
    {
        return $this->purchaseSetting?->is_target ?? false;
    }

    /** الرصيد الإجمالي القابل للبيع (مجموع كل الدفعات غير المنتهية الصلاحية) */
    public function usableStockQuantity(): int
    {
        return $this->inventoryBatches()->usable()->sum('quantity');
    }
}
