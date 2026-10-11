<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Preparation;

use App\Services\Inventory\Import\Contracts\TabularFileReader;
use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use App\Services\Inventory\Import\Support\ArabicNumberParser;
use App\Services\Inventory\Import\Support\ImportDiagnostics;
use Generator;

/**
 * 🎯 (ب.3) يحوّل ملف التحضير إلى تدفّق PreparationRow — قراءة متدفقة عبر TabularFileReader
 * (صف واحد بالذاكرة)، رؤوس تُفحص قبل أي صف بيانات، وكل تصحيح تلقائي يُسجَّل بـ ImportDiagnostics.
 *
 * قواعد الخانة "الكمية المحضّرة" (نفس فلسفة كشف المستودعات: لا تحويل صامت):
 *   - فراغ ⇒ 0 (لم يُحضَّر شيء) — القالب يُنزَّل بكل مواد الفاتورة وعمود فارغ، فالفراغ معناه صفر.
 *   - سالب أو نص غير رقمي ⇒ 0 مع تحذير InvalidQuantity.
 *   - كسر ⇒ يُخفَّض لعدد صحيح مع تحذير FractionalQuantity (القطع/العبوات تُعدّ صحيحة).
 */
final class PreparationRowParser
{
    public const SKIP_NO_BARCODE = 'صف بلا رمز مادة';

    private const FLOAT_TOLERANCE_DECIMALS = 6;

    public function __construct(
        private readonly TabularFileReader $reader,
    ) {
    }

    /**
     * @return Generator<int, PreparationRow>
     * @throws InvalidImportFileException
     */
    public function parse(string $absolutePath, ImportDiagnostics $diagnostics): Generator
    {
        $source = $this->reader->read($absolutePath);

        if (!$source->valid()) {
            throw InvalidImportFileException::emptyFile();
        }

        PreparationColumns::assertHeaderRow($source->current());

        return $this->rows($source, $diagnostics);
    }

    private function rows(Generator $source, ImportDiagnostics $diagnostics): Generator
    {
        for ($source->next(); $source->valid(); $source->next()) {
            $rowNumber = (int) $source->key();
            $cells = $source->current();

            $barcode = self::text($cells[PreparationColumns::BARCODE] ?? null);
            $rawQty = $cells[PreparationColumns::PREPARED_QTY] ?? null;

            if ($barcode === '') {
                if (self::text($rawQty) !== '' || self::text($cells[PreparationColumns::NAME] ?? null) !== '') {
                    $diagnostics->recordSkippedRow($rowNumber, self::SKIP_NO_BARCODE);
                }
                continue;
            }

            yield $rowNumber => new PreparationRow($rowNumber, $barcode, $this->quantity($rawQty, $rowNumber, $barcode, $diagnostics));
        }
    }

    private function quantity(string|int|float|bool|null $raw, int $row, string $barcode, ImportDiagnostics $diagnostics): int
    {
        $text = self::text($raw);
        if ($text === '' || in_array($text, ['-', '–', '—'], true)) {
            return 0;
        }

        $value = ArabicNumberParser::toFloat($raw);
        if ($value === null) {
            $diagnostics->recordInvalidQuantity($row, $barcode, 'الكمية المحضّرة', $text);

            return 0;
        }

        if ($value < 0) {
            $diagnostics->recordNegativeQuantity($row, $barcode, 'الكمية المحضّرة', $value);

            return 0;
        }

        $value = round($value, self::FLOAT_TOLERANCE_DECIMALS);
        $floored = (int) floor($value);
        if ($value !== (float) $floored) {
            $diagnostics->recordFractionalQuantity($row, $barcode, 'الكمية المحضّرة', $value, $floored);
        }

        return $floored;
    }

    private static function text(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => '',
            is_string($value) => trim($value),
            is_bool($value) => $value ? '1' : '',
            is_int($value) => (string) $value,
            is_finite($value) && floor($value) === $value && abs($value) < 1.0e15 => number_format($value, 0, '', ''),
            is_finite($value) => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'),
            default => '',
        };
    }
}
