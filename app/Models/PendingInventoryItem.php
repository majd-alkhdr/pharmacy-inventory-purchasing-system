<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingInventoryItem extends Model
{
    // 🆕 price — راجع مايجريشن 2026_09_05_000002 — نفس منطق pack_size
    // بالضبط: يُرحَّل من CentralInventoryImport (باركود جديد كلياً) إلى
    // central_inventory_prices عند التأكيد الفعلي عبر PendingProductService.
    protected $fillable = ['session_id', 'material_name', 'company', 'barcode', 'quantity', 'pack_size', 'price'];
}
