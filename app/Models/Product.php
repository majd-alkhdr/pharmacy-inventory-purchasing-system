<?php

namespace App\Models;

use App\Services\Purchasing\TurnoverCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Product extends Model
{
    // 🆕 pack_size — راجع مايجريشن 2026_08_25_000001 للتفاصيل الكاملة
    // 🆕 min_reorder_gap: افتراضي «الحد الأدنى للفرق» على مستوى المادة — راجع مايجريشن
    // 2026_09_22_000002 وPharmacyMinimumStock::effectiveMinReorderGap() للتفاصيل الكاملة.
    protected $fillable = ['barcode', 'name', 'company', 'last_purchase_date', 'pack_size', 'min_reorder_gap'];

    protected function casts(): array
    {
        return [
            'last_purchase_date' => 'date',
        ];
    }

    public static function findByBarcode(string $barcode): ?self
    {
        return static::where('barcode', $barcode)->first();
    }

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

    public function supplierProducts()
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function inventoryBatches()
    {
        return $this->hasMany(InventoryBatch::class);
    }

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

    public function isMarketShortage(): bool
    {
        return $this->marketShortageFlags()->active()->exists();
    }

    public function isTarget(): bool
    {
        return $this->purchaseSetting?->is_target ?? false;
    }

    /**
     * 🆕 رصيد المستودع المركزي من ملف المصفوفة — لا inventory_batches (ملف جرد المشتريات، أُلغي).
     * الاسم أُبقي لأن DemandCalculationService (المجمَّدة) ما زالت تستدعيه.
     */
    public function usableStockQuantity(): int
    {
        return app(\App\Services\Shared\Stock\CentralStockReader::class)->quantityFor($this->id);
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_product');
    }

    public function sellingPrices()
    {
        return $this->hasMany(ProductSellingPrice::class);
    }

    public function currentSellingPrice(?string $asOfDate = null): ?float
    {
        return ProductSellingPrice::effectivePriceFor($this->id, $asOfDate);
    }

    public function marginAt(float $netUnitCost, ?string $asOfDate = null): ?float
    {
        $sellingPrice = $this->currentSellingPrice($asOfDate);

        return $sellingPrice === null ? null : round($sellingPrice - $netUnitCost, 4);
    }

    /** 🆕 سجل تغيّرات "سعر المادة" من ملف الأصناف — يستبدل مفهوم "آخر سعر شراء" الملغى. */
    public function priceChanges()
    {
        return $this->hasMany(ProductPriceChange::class);
    }

    public function isNewlyPurchased(): bool
    {
        $referenceDate = $this->last_purchase_date ?? $this->created_at;

        if (!$referenceDate instanceof Carbon) {
            return false;
        }

        return $referenceDate->greaterThanOrEqualTo(now()->subDays(30));
    }

    public function turnoverInMonths(): ?float
    {
        return app(TurnoverCalculator::class)->calculateFor($this);
    }

    /**
     * 🎯 هل حجم عبوة هذه المادة مُحدَّد فعلياً (> 0)؟ — منطق الترميم
     * (ParLevelReplenishmentService / ShortageFulfillmentService) يستخدم
     * هذه القيمة لتقريب أي فجوة أو كمية شحن للأسفل لأقرب مضاعف للعبوة؛
     * pack_size = 0 يعني "بلا قيد تعبئة" (السلوك القديم بلا أي تقريب).
     */
    public function hasPackConstraint(): bool
    {
        return $this->pack_size > 0;
    }

    /**
     * 🆕 هل هذا المنتج "قيد تصفية" فعلياً الآن؟ — علم يدوي صريح يتجاوز
     * كل حسابات الدوران/النقص الحرج بمحرك القرار (راجع
     * PurchaseEligibilityFilter). يُراعي تاريخ الانتهاء الاختياري تلقائياً
     * (لو انتهى، يُعامَل كأنه غير مفعَّل، بلا حاجة لأي مهمة مجدولة تُصفِّر
     * العمود بقاعدة البيانات فعلياً — الفحص حيّ دائماً).
     */
    public function isInClearance(): bool
    {
        $setting = $this->purchaseSetting;

        if (!$setting || !$setting->is_clearance) {
            return false;
        }

        if ($setting->clearance_until === null) {
            return true; // بلا تاريخ انتهاء = يبقى فعّالاً حتى إلغاء يدوي صريح
        }

        return $setting->clearance_until->greaterThanOrEqualTo(now()->startOfDay());
    }
}
