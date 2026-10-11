<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductMasterField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🎯 آخر كاتب لحقل (مادة، حقل): رفع (import_log_id) أو تصحيح يدوي (audit_log_id). يُكتب حصراً عبر
 * ProductFieldSourceRecorder. للعرض فقط — لا يُستشار عند الرفع.
 */
class ProductFieldSource extends Model
{
    public $timestamps = false;

    protected $fillable = ['product_id', 'field', 'import_log_id', 'audit_log_id', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'field' => ProductMasterField::class,
            'recorded_at' => 'datetime',
        ];
    }

    public function importLog(): BelongsTo
    {
        return $this->belongsTo(ImportLog::class);
    }

    public function auditLog(): BelongsTo
    {
        return $this->belongsTo(AuditLog::class);
    }

    public function isManual(): bool
    {
        return $this->audit_log_id !== null;
    }
}
