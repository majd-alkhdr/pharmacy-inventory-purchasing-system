<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingInventoryItem extends Model
{
    protected $fillable = ['session_id', 'material_name', 'company', 'barcode', 'quantity'];
}
