<?php

namespace App\Models;

use App\Enums\ImportFileType;
use App\Enums\ImportLogStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 🎯 (أ.1) سطر واحد بسجل الرفع الموحَّد = ملف واحد مرفوع. لا يُنشأ مباشرة من أي
 * كونترولر — دائماً عبر ImportHistoryService (نقطة الكتابة الوحيدة).
 */
class ImportLog extends Model
{
    protected $fillable = [
        'file_type', 'original_filename', 'session_id', 'user_id', 'status',
        'rows_succeeded', 'rows_skipped', 'rows_warned', 'diagnostics', 'message',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'file_type' => ImportFileType::class,
            'status' => ImportLogStatus::class,
            'diagnostics' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOfType(Builder $query, ImportFileType|array|null $types): Builder
    {
        if ($types === null) {
            return $query;
        }

        $values = array_map(fn (ImportFileType $t) => $t->value, is_array($types) ? $types : [$types]);

        return $query->whereIn('file_type', $values);
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->whereIn('status', ImportLogStatus::successfulValues());
    }

    /**
     * آخر رفعة **ناجحة** (بتحذيرات أو بدونها) لنوع/أنواع معيّنة. الترتيب بـ id لا
     * finished_at وحده: رفعتان بنفس الثانية تبقيان مرتبتين حتمياً.
     *
     * @param ImportFileType|list<ImportFileType> $types
     */
    public static function latestSuccessful(ImportFileType|array $types): ?self
    {
        return static::query()->ofType($types)->successful()
            ->orderByDesc('finished_at')->orderByDesc('id')
            ->first();
    }

    public function hasWarnings(): bool
    {
        return ($this->rows_warned ?? 0) > 0;
    }

    /** وقت الحدث الأهم للعرض — الانتهاء إن انتهى، وإلا البداية. */
    public function displayTime(): \Illuminate\Support\Carbon
    {
        return $this->finished_at ?? $this->started_at;
    }
}
