<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PharmacySalesRecord extends Model
{
    protected $fillable = ['pharmacy_id', 'product_id', 'quantity', 'sale_date'];

    protected function casts(): array
    {
        return [
            'quantity'  => 'decimal:4',
            'sale_date' => 'date',
        ];
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
