<?php

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 🎯 (أ.2) سطر سجل نشاط — append-only. يُكتب حصراً عبر AuditLogger.
 *
 * ⚠️ UPDATED_AT = null: السجل لا يُعدَّل بعد كتابته إطلاقاً (هذا معنى "سجل تدقيق").
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'context'];

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function auditable()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query->where('auditable_type', $subject->getMorphClass())->where('auditable_id', $subject->getKey());
    }

    /**
     * بحث بقيمة داخل context — لاسترجاع "تاريخ مستند" حتى بعد حذف بعض بنوده (البند المحذوف
     * لم يعد موجوداً كـ auditable، لكن context.invoice_id باقٍ بسطر سجله).
     */
    public function scopeWhereContext(Builder $query, string $key, int|string $value): Builder
    {
        return $query->where("context->{$key}", $value);
    }

    /**
     * سجلات مجموعة كائنات من نفس النوع دفعة واحدة (مثلاً كل بنود فاتورة) — تفادياً
     * لاستعلام لكل بند عند عرض "تاريخ التعديلات" بالشاشة.
     *
     * @param class-string<Model> $modelClass
     * @param iterable<int> $ids
     */
    public function scopeForSubjects(Builder $query, string $modelClass, iterable $ids): Builder
    {
        return $query->where('auditable_type', (new $modelClass())->getMorphClass())
            ->whereIn('auditable_id', collect($ids)->all());
    }
}
