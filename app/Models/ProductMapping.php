<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductMapping extends Model
{
    protected $fillable = ['original_name', 'company', 'product_id'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
