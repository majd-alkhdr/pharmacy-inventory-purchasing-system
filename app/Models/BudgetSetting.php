<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetSetting extends Model
{
    protected $fillable = ['effective_month', 'monthly_cap_amount', 'is_enabled', 'notes'];

    protected function casts(): array
    {
        return [
            'effective_month'     => 'date',
            'is_enabled'          => 'boolean',
            'monthly_cap_amount'  => 'decimal:2',
        ];
    }

    /**
     * 🎯 يطبّق قرارك: "إمكانية إلغاء الحد العام أو عدم تحديد أي ميزانية".
     * تُستخدم من محرك الميزانية لاحقاً كنقطة انطلاق واحدة موثوقة بدل أن
     * يبني كل استدعاء منطق "أي شهر هو الحالي، وهل هو مفعّل" بنفسه.
     */
    public static function currentMonth(): ?self
    {
        return static::where('effective_month', now()->startOfMonth()->toDateString())
            ->where('is_enabled', true)
            ->first();
    }
}
