<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PharmacyInventory extends Model
{
    protected $fillable = ['pharmacy_id', 'product_id', 'quantity'];

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** 🎯 اختصار مريح — الرصيد الحالي لمادة معيّنة بصيدلية معيّنة (0 إن لم يوجد سجل) */
    public static function quantityFor(int $pharmacyId, int $productId): int
    {
        return (int) static::where('pharmacy_id', $pharmacyId)
            ->where('product_id', $productId)
            ->value('quantity');
    }
}
