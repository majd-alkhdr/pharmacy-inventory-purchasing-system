<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🎯 سطر تغيّر واحد لـ"سعر المادة" من ملف الأصناف — append-only، يُكتب حصراً عبر
 * MaterialPriceHistoryRecorder. لا timestamps: recorded_at هو وقت الحدث نفسه.
 */
class ProductPriceChange extends Model
{
    public $timestamps = false;

    protected $fillable = ['product_id', 'old_price', 'new_price', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'old_price'   => 'decimal:2',
            'new_price'   => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
