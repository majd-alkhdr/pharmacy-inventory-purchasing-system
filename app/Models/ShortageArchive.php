<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShortageArchive extends Model
{
    public $timestamps = false; // نتحكم بالتواريخ يدوياً (original_created_at + archived_at)

    protected $fillable = [
        'pharmacy_id',
        'product_id',
        'quantity',
        'status',
        'original_created_at',
        'archived_at',
    ];
}
