<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesQuantityRemainder extends Model
{
    protected $fillable = ['pharmacy_id', 'product_id', 'remainder_quantity'];

    protected function casts(): array
    {
        return ['remainder_quantity' => 'decimal:4'];
    }

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
