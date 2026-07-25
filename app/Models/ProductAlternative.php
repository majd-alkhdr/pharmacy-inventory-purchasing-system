<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductAlternative extends Model
{
    protected $fillable = ['product_id', 'alternative_product_id', 'note'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function alternativeProduct()
    {
        return $this->belongsTo(Product::class, 'alternative_product_id');
    }
}
