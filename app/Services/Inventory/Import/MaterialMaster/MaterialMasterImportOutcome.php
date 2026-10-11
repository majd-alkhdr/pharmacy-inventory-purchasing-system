<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\MaterialMaster;

use App\Services\Inventory\Import\Support\ImportDiagnostics;
use App\Services\Shared\ImportHistory\ImportStats;
use App\Services\Shared\ImportHistory\ProvidesImportStats;

/**
 * 🎯 نتيجة استيراد ملف "بيانات المواد" — لا حالة "يحتاج مطابقة" هنا إطلاقاً
 * (بخلاف MatrixImportOutcome): لا صيدليات في هذا الملف، فلا شيء غامض يستدعي
 * توقفاً بشرياً. إما يُكتب، أو يفشل الملف كله برسالة واحدة قبل أي كتابة.
 */
final class MaterialMasterImportOutcome implements ProvidesImportStats
{
    /** 🆕 (أ.1) الرموز غير المسجَّلة "متجاهَلة" (لم يُلمَس لها شيء) — تُعدّ ضمن المتجاهَل. */
    public function importStats(): ImportStats
    {
        return ImportStats::fromDiagnostics(
            succeeded: $this->rowsInFile - count($this->unmatchedBarcodes),
            diagnostics: $this->diagnostics,
            message: implode(' ', $this->summaryLines()),
            extraSkipped: count($this->unmatchedBarcodes),
        );
    }

    /** @param list<string> $unmatchedBarcodes رموز غير مسجَّلة بالنظام — معلوماتية فقط، لا تُنشأ منها مواد */
    public function __construct(
        public readonly int $rowsInFile,
        public readonly int $productsUpdated,
        public readonly int $pricesChanged,
        public readonly array $unmatchedBarcodes,
        public readonly ImportDiagnostics $diagnostics,
    ) {
    }

    /** @return list<string> */
    public function summaryLines(): array
    {
        $lines = [sprintf(
            '📇 استُورد ملف بيانات المواد: %d صف، %d مادة مُحدَّثة، %d تغيير سعر مُسجَّل تاريخياً.',
            $this->rowsInFile,
            $this->productsUpdated,
            $this->pricesChanged,
        )];

        if ($this->unmatchedBarcodes !== []) {
            $shown = array_slice($this->unmatchedBarcodes, 0, 10);
            $more = count($this->unmatchedBarcodes) - count($shown);
            $lines[] = '❓ ' . count($this->unmatchedBarcodes) . ' رمز غير مسجَّل بالنظام (لم يُلمَس شيء لها): '
                . implode('، ', $shown) . ($more > 0 ? "، و{$more} غيرها" : '') . '.';
        }

        return [...$lines, ...$this->diagnostics->summaryLines()];
    }
}
