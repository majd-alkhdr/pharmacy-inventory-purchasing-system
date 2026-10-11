<?php

declare(strict_types=1);

namespace App\Services\Shared\ImportHistory;

use App\Enums\ImportWarning;
use App\Services\Inventory\Import\Support\ImportDiagnostics;

/**
 * 🎯 ملخص نتيجة ملف واحد بالشكل الذي يفهمه سجل الرفع — بصرف النظر عن المستورد.
 *
 * 🎓 لماذا DTO وسيط بدل أن يقرأ ImportHistoryService كل Outcome مباشرة؟ المستوردات
 * غير متجانسة: الجديدة (المصفوفة، بيانات المواد) تملك ImportDiagnostics غنياً، والقديمة
 * (Maatwebsite) تُعيد مصفوفات matched/unmatched. هذا الكلاس هو "اللغة المشتركة" —
 * كل مستورد يترجم نفسه إليه مرة واحدة، والسجل لا يعرف شيئاً عن أي مستورد بعينه (DIP).
 */
final class ImportStats
{
    /**
     * @param array<string, mixed>|null $diagnostics صيغة ImportDiagnostics::toArray()، أو null
     */
    public function __construct(
        public readonly int $succeeded,
        public readonly int $skipped = 0,
        public readonly int $warned = 0,
        public readonly ?array $diagnostics = null,
        public readonly ?string $message = null,
    ) {
    }

    /**
     * الطريق المفضَّل لأي مستورد يستخدم ImportDiagnostics: "المتجاهَل" هو عدّاد
     * SkippedRow تحديداً، و"المحذَّر" مجموع كل التحذيرات (بما فيها المتجاهَل — لأن
     * التجاهل نفسه قرار اتخذه النظام نيابة عن المستخدم ويجب أن يُرى).
     */
    public static function fromDiagnostics(int $succeeded, ImportDiagnostics $diagnostics, ?string $message = null, int $extraSkipped = 0): self
    {
        return new self(
            succeeded: $succeeded,
            skipped: $diagnostics->count(ImportWarning::SkippedRow) + $extraSkipped,
            warned: $diagnostics->total(),
            diagnostics: $diagnostics->toArray(),
            message: $message,
        );
    }

    /**
     * للمستوردات القديمة التي لا تملك ImportDiagnostics: صفوف لم تُطبَّق (غير مطابَقة،
     * قيم غير صالحة) تُعدّ متجاهَلة **ومحذَّرة** معاً — نفس الفلسفة: لا شيء صامت.
     */
    public static function fromCounts(int $succeeded, int $skipped = 0, ?string $message = null): self
    {
        return new self(succeeded: $succeeded, skipped: $skipped, warned: $skipped, message: $message);
    }

    public function hasWarnings(): bool
    {
        return $this->warned > 0;
    }
}
