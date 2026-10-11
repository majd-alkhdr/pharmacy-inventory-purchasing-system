<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 🎯 مصدر بديل لمعدّل الاستهلاك اليومي — راجع مايجريشن الجدول للتفاصيل
 * الكاملة. صف واحد لكل منتج، يُستبدَل بالكامل بكل رفعة/قرار مطابقة جديد.
 */
class ProductManualSalesRate extends Model
{
    protected $fillable = ['product_id', 'sales_quantity', 'period_days'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** 🎯 الدالة المشتقّة الوحيدة — لا شيء آخر يُخزَّن محسوباً مسبقاً بهذا الموديل */
    public function dailyRate(): float
    {
        return $this->period_days > 0
            ? round($this->sales_quantity / $this->period_days, 4)
            : 0.0;
    }
}
