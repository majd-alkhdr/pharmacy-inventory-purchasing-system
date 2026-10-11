<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class PharmacyMinimumStock extends Model
{
    // 🆕 pack_size_override — راجع مايجريشن 2026_09_01_000002 للتفاصيل
    // الكاملة (تجاوز اختياري لحجم العبوة خاص بهذا الزوج، يتقدَّم على
    // products.pack_size الافتراضي إن كان موجوداً).
    protected $fillable = ['pharmacy_id', 'product_id', 'minimum_quantity', 'min_reorder_gap', 'pack_size_override'];

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** الحد الأدنى لزوج معيّن، أو null إن لم يُحدَّد له حد أدنى إطلاقاً */
    public static function forPair(int $pharmacyId, int $productId): ?int
    {
        return static::where('pharmacy_id', $pharmacyId)
            ->where('product_id', $productId)
            ->value('minimum_quantity');
    }

    /**
     * 🎯 نسخة مُحسَّنة أداءً — تجلب كل الحدود الدنيا لمجموعة من أزواج
     * (صيدلية، مادة) بمجملها، بدل استعلام منفصل لكل زوج. تُستخدم من
     * ShortageFulfillmentService عند فحص دفعة كاملة من النواقص المعلَّقة
     * معاً، لتفادي N+1.
     *
     * @param Collection<int, array{pharmacy_id:int, product_id:int}> $pairs
     * @return array<string, int> ["pharmacyId_productId" => minimum_quantity]
     */
    public static function bulkForPairs(Collection $pairs): array
    {
        if ($pairs->isEmpty()) {
            return [];
        }

        $pharmacyIds = $pairs->pluck('pharmacy_id')->unique();
        $productIds  = $pairs->pluck('product_id')->unique();

        return static::whereIn('pharmacy_id', $pharmacyIds)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy(fn(PharmacyMinimumStock $row) => "{$row->pharmacy_id}_{$row->product_id}")
            ->map(fn(PharmacyMinimumStock $row) => $row->minimum_quantity)
            ->all();
    }

    /**
     * 🎯 حجم العبوة الفعّال لهذا الزوج تحديداً — التجاوز الخاص بهذه
     * الصيدلية إن وُجد (pack_size_override)، وإلا حجم العبوة الافتراضي
     * للمنتج نفسه (products.pack_size). كلاهما 0 يعني "بلا قيد تعبئة".
     *
     * 🎓 هذه الدالة هي نقطة الحقيقة الوحيدة لهذا القرار — أي كود يحتاج
     * "حجم العبوة الفعّال لهذا الزوج" يستدعيها بدل تكرار منطق ?? بأكثر
     * من مكان (راجع ParLevelReplenishmentService لمثال الاستهلاك).
     */
    public function effectivePackSize(): int
    {
        if (!is_null($this->pack_size_override)) {
            return (int) $this->pack_size_override;
        }

        return (int) ($this->product->pack_size ?? 0);
    }

    /**
     * 🆕 نفس منطق effectivePackSize() بالضبط: تجاوز هذا الزوج تحديداً إن وُجد
     * (min_reorder_gap بهذا الجدول)، وإلا افتراضي المادة (products.min_reorder_gap)،
     * وإلا صفر (بلا تسامح — السلوك القديم قبل وجود أي من العمودين).
     */
    public function effectiveMinReorderGap(): int
    {
        if (!is_null($this->min_reorder_gap)) {
            return (int) $this->min_reorder_gap;
        }

        return (int) ($this->product->min_reorder_gap ?? 0);
    }
}
