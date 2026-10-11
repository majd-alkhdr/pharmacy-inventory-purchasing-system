<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 🎯 (ب.3) مطابقة واحدة = رفع ملف تحضير واحد لفاتورة مناقلة. */
class TransferReconciliation extends Model
{
    protected $fillable = ['invoice_id', 'import_log_id', 'user_id', 'items_count', 'mismatched_count'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function items()
    {
        return $this->hasMany(TransferReconciliationItem::class);
    }

    public function importLog()
    {
        return $this->belongsTo(ImportLog::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
