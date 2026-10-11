<?php

declare(strict_types=1);

namespace App\Services\Inventory\Surplus;

use App\Models\ImportLog;
use Illuminate\Support\Carbon;

/**
 * 🎯 "دورة الرفع الحالية" — الفترة منذ آخر رفعة ناجحة لملف يستبدل لقطة مخزون
 * الصيدليات (كشف المستودعات أو جرد صيدلية). كل التزامات الفائض محصورة بدورة.
 *
 * id = null: لا يوجد بعد أي سجل رفع ناجح من هذا النوع (بيانات سبقت نشر سجل الرفع).
 * تبقى دورة صالحة بذاتها — حتى لا يتعطل التنفيذ قبل أول رفعة بعد التحديث.
 */
final class InventoryCycle
{
    public function __construct(
        public readonly ?int $id,
        public readonly ?Carbon $startedAt,
        public readonly ?string $sourceLabel,
    ) {
    }

    public static function fromLog(?ImportLog $log): self
    {
        return $log === null
            ? new self(null, null, null)
            : new self((int) $log->id, $log->displayTime(), $log->file_type->label() . ' — ' . $log->original_filename);
    }

    /** هل ينتمي كيان موسوم بدورة (مناقلة مقترحة مثلاً) للدورة الحالية؟ */
    public function contains(?int $cycleImportLogId): bool
    {
        return $cycleImportLogId === $this->id;
    }
}
