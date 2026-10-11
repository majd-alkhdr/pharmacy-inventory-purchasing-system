<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\MaterialMaster;

use App\Services\Inventory\Import\Contracts\TabularFileReader;
use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use App\Services\Inventory\Import\Support\ArabicNumberParser;
use App\Services\Inventory\Import\Support\ImportDiagnostics;
use Generator;

/**
 * 🎯 يحوّل صفوف ملف "بيانات المواد" الخام إلى MaterialMasterRow نظيفة. القاعدة
 * الوحيدة والحاكمة لكل عمود اختياري (كل شيء عدا الباركود): **الفراغ أو "-" يعني
 * "لا تغيير" — لا صفراً ولا حذفاً**. هذا يخالف عمداً قاعدة كشف المستودعات
 * (الفراغ = صفر هناك)؛ الملفان مختلفان بطبيعتهما: الأول جرد لحظي (فراغ = لا
 * يوجد شيء الآن)، هذا وصف تراكمي للمادة (فراغ = لم يُذكَر بهذه الرفعة، اترك
 * القديم).
 *
 * قيمة غير فارغة لكن غير مفهومة (نص بخانة رقمية، أو رقم سالب لا معنى له
 * لسعر/حجم عبوة/حد أدنى) **لا تُصفَّر ولا تُطبَّق** — تُترَك كأنها فراغ (بلا
 * تغيير) مع تحذير ظاهر، لأن تطبيق تخمين خاطئ على بيانات وصفية دائمة (لا لحظية
 * كالمخزون) أخطر من تجاهله؛ صاحب البيانات يصلح الملف ويعيد رفعه.
 */
final class MaterialMasterRowParser
{
    public const SKIP_NO_BARCODE = 'صف بلا رمز مادة';
    public const SKIP_NOTHING_TO_UPDATE = 'صف بلا أي بيانات فعلية (كل الأعمدة الاختيارية فارغة)';

    private const FLOAT_TOLERANCE_DECIMALS = 6;

    public function __construct(
        private readonly TabularFileReader $reader,
    ) {
    }

    /**
     * @throws InvalidImportFileException ملف تالف، أو رؤوس لا تطابق ترتيب الأعمدة الستة المتفق عليه
     * @return Generator<int, MaterialMasterRow>
     */
    public function parse(string $absolutePath, ImportDiagnostics $diagnostics): Generator
    {
        $source = $this->reader->read($absolutePath);

        if (!$source->valid()) {
            throw InvalidImportFileException::emptyFile();
        }

        MaterialMasterColumns::fromHeaderRow($source->current());

        return $this->rows($source, $diagnostics);
    }

    /**
     * @param Generator<int, array<int, string|int|float|bool|null>> $source
     * @return Generator<int, MaterialMasterRow>
     */
    private function rows(Generator $source, ImportDiagnostics $diagnostics): Generator
    {
        for ($source->next(); $source->valid(); $source->next()) {
            $rowNumber = (int) $source->key();
            $cells = $source->current();

            if (self::isBlankRow($cells)) {
                continue;
            }

            $barcode = self::text($cells[MaterialMasterColumns::BARCODE] ?? null);

            if ($barcode === '') {
                $diagnostics->recordSkippedRow($rowNumber, self::SKIP_NO_BARCODE);
                continue;
            }

            $row = new MaterialMasterRow(
                sourceRow: $rowNumber,
                barcode: $barcode,
                name: self::nullableText($cells[MaterialMasterColumns::NAME] ?? null),
                company: self::nullableText($cells[MaterialMasterColumns::COMPANY] ?? null),
                price: $this->nullableNonNegativeFloat(
                    $cells[MaterialMasterColumns::PRICE] ?? null,
                    $rowNumber,
                    $barcode,
                    'السعر',
                    $diagnostics,
                ),
                packSize: $this->nullableNonNegativeInt(
                    $cells[MaterialMasterColumns::PACK_SIZE] ?? null,
                    $rowNumber,
                    $barcode,
                    'حجم العبوة',
                    $diagnostics,
                ),
                minReorderGap: $this->nullableNonNegativeInt(
                    $cells[MaterialMasterColumns::MIN_REORDER_GAP] ?? null,
                    $rowNumber,
                    $barcode,
                    'الحد الأدنى للفرق',
                    $diagnostics,
                ),
            );

            if ($row->isEntirelyBlank()) {
                $diagnostics->recordSkippedRow($rowNumber, self::SKIP_NOTHING_TO_UPDATE);
                continue;
            }

            yield $rowNumber => $row;
        }
    }

    /** نص اختياري: فراغ أو "-" يعني "لا تغيير" (null)، أي شيء آخر يُقتطَع ويُستخدَم كما هو. */
    private static function nullableText(string|int|float|bool|null $raw): ?string
    {
        $text = self::text($raw);

        return in_array($text, ['', '-', '–', '—'], true) ? null : $text;
    }

    /**
     * رقم عشري اختياري غير سالب (السعر): فراغ/"-" = لا تغيير. قيمة سالبة أو غير
     * رقمية = لا تغيير أيضاً، لكن **مع تحذير ظاهر** — بخلاف الفراغ العادي، هذه
     * محاولة إدخال قيمة فشلت، لا غياب متعمَّد.
     */
    private function nullableNonNegativeFloat(
        string|int|float|bool|null $raw,
        int $row,
        string $barcode,
        string $field,
        ImportDiagnostics $diagnostics,
    ): ?float {
        $text = self::text($raw);

        if (in_array($text, ['', '-', '–', '—'], true)) {
            return null;
        }

        $value = ArabicNumberParser::toFloat($raw);

        if ($value === null || $value < 0) {
            $diagnostics->recordInvalidValue($row, $barcode, $field, $text);

            return null;
        }

        return round($value, 2);
    }

    /**
     * عدد صحيح اختياري غير سالب (حجم العبوة، الحد الأدنى للفرق): نفس قواعد
     * nullableNonNegativeFloat()، مع تخفيض أي كسر لأقرب عدد صحيح (بتحذير منفصل —
     * القيمة نفسها مفهومة وتُطبَّق، فقط بدقة أقل مما كُتب).
     */
    private function nullableNonNegativeInt(
        string|int|float|bool|null $raw,
        int $row,
        string $barcode,
        string $field,
        ImportDiagnostics $diagnostics,
    ): ?int {
        $text = self::text($raw);

        if (in_array($text, ['', '-', '–', '—'], true)) {
            return null;
        }

        $value = ArabicNumberParser::toFloat($raw);

        if ($value === null || $value < 0) {
            $diagnostics->recordInvalidValue($row, $barcode, $field, $text);

            return null;
        }

        $value = round($value, self::FLOAT_TOLERANCE_DECIMALS); // يمتص ضجيج الفاصلة العائمة (راجع WarehouseMatrixRowParser)
        $floored = (int) floor($value);

        if ($value !== (float) $floored) {
            $diagnostics->recordFractionalQuantity($row, $barcode, $field, $value, $floored);
        }

        return $floored;
    }

    /** @param array<int, string|int|float|bool|null> $cells */
    private static function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (self::text($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function text(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => '',
            is_string($value) => trim($value),
            is_bool($value) => $value ? '1' : '',
            is_int($value) => (string) $value,
            default => self::floatToText($value),
        };
    }

    private static function floatToText(float $value): string
    {
        if (!is_finite($value)) {
            return '';
        }

        if (floor($value) === $value && abs($value) < 1.0e15) {
            return number_format($value, 0, '', '');
        }

        return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    }
}
