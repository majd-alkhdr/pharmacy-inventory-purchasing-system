<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PharmacyMapping extends Model
{
    protected $fillable = ['original_name', 'pharmacy_id'];

    public function pharmacy()
    {
        return $this->belongsTo(Pharmacy::class);
    }
}
