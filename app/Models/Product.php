<?php

namespace App\Models;

use App\Services\Purchasing\TurnoverCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Product extends Model
{
    protected $fillable = ['barcode', 'name', 'company', 'last_purchase_date'];

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
    // 🆕 علاقات نظام المشتريات (Phase B)
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

    /** هل هذا المنتج "مقطوع من السوق" حالياً؟ */
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

    // ══════════════════════════════════════════════════════════
    // 🆕 التصنيف/الاستطباب — قائمة ثابتة مُدارة، معلوماتية فقط
    // (راجع تعليق مايجريشن categories: لا تُستخدم لأي مطابقة آلية
    // بين الأدوية، القرار يبقى دائماً بيد صيدلي مختص)
    //
    // ⚠️ بقرار صريح من المستخدم أثناء إعادة هيكلة المشتريات (2026-08):
    // هذه العلاقة تبقى منفصلة تماماً عن ملف جرد المشتريات الجديد، ولا
    // تُلمَس إطلاقاً من PurchaseInventoryUploadService — التصنيف علاقة
    // متعددة-لمتعددة يصعب تمثيلها بأمان بعمود واحد بملف Excel.
    // ══════════════════════════════════════════════════════════

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_product');
    }

    // ══════════════════════════════════════════════════════════
    // 🆕 سعر البيع الرسمي (التعرفة) — الطرف الثاني لمعادلة الربح
    // ══════════════════════════════════════════════════════════

    public function sellingPrices()
    {
        return $this->hasMany(ProductSellingPrice::class);
    }

    /** السعر الرسمي الساري الآن (أو عند تاريخ محدَّد) */
    public function currentSellingPrice(?string $asOfDate = null): ?float
    {
        return ProductSellingPrice::effectivePriceFor($this->id, $asOfDate);
    }

    /**
     * 🎯 الهامش الفعلي (الربح الحقيقي) لو اشترينا وحدة بهذه التكلفة
     * الصافية. تُرجع null لو لم يوجد سعر بيع مسجَّل لهذه المادة أصلاً.
     * ⚠️ غير مُستخدَمة حالياً بمحرك القرار الجديد (لا أسعار على مستوى
     * السطر بعد — راجع وثيقة التصميم §9)، تبقى جاهزة لعودة العروض لاحقاً.
     */
    public function marginAt(float $netUnitCost, ?string $asOfDate = null): ?float
    {
        $sellingPrice = $this->currentSellingPrice($asOfDate);

        return $sellingPrice === null ? null : round($sellingPrice - $netUnitCost, 4);
    }

    // ══════════════════════════════════════════════════════════
    // 🆕 آخر سعر شراء (تاريخي) — يخدم مراقب تذبذب التكلفة الجديد
    // ══════════════════════════════════════════════════════════

    public function purchasePrices()
    {
        return $this->hasMany(ProductPurchasePrice::class);
    }

    public function currentPurchasePrice(): ?float
    {
        return ProductPurchasePrice::latestFor($this->id);
    }

    // ══════════════════════════════════════════════════════════
    // 🆕 دوران المخزون — قلب إعادة هيكلة المشتريات (2026-08)
    // ══════════════════════════════════════════════════════════

    /**
     * 🎯 هل هذا المنتج "جديد الشراء" (أقل من 30 يوماً منذ آخر عملية شراء
     * فعلية)؟ يُستخدم لتفادي الحكم الخاطئ على منتج جديد بأنه "راكد" لمجرد
     * أن مبيعاته لا تزال قليلة — قد يكون قليل المبيعات لأنه لم يُطرَح
     * بالسوق كفاية، وليس لأنه راكد فعلياً.
     *
     * المصدر: last_purchase_date (يُغذَّى من ملف جرد المشتريات)، وإن لم
     * يوجد نتراجع لـ created_at كبديل — تماماً كما طلب المستخدم صراحة.
     */
    public function isNewlyPurchased(): bool
    {
        $referenceDate = $this->last_purchase_date ?? $this->created_at;

        if (!$referenceDate instanceof Carbon) {
            return false;
        }

        return $referenceDate->greaterThanOrEqualTo(now()->subDays(30));
    }

    /**
     * 🎯 نقطة دخول مختصرة ومريحة لحساب دوران هذا المنتج بالأشهر —
     * تُفوِّض العمل الفعلي بالكامل لـ TurnoverCalculator (لا تكرار منطق).
     * تُرجع null إن كان المنتج "جديد الشراء" وبلا مبيعات كافية بعد
     * للحكم عليه (راجع TurnoverCalculator::calculateFor للتفاصيل).
     */
    public function turnoverInMonths(): ?float
    {
        return app(TurnoverCalculator::class)->calculateFor($this);
    }
}
