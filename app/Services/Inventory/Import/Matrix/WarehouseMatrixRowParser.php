<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Matrix;

use App\Services\Inventory\Import\Contracts\TabularFileReader;
use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use App\Services\Inventory\Import\Support\ArabicNumberParser;
use App\Services\Inventory\Import\Support\ArabicTextNormalizer;
use App\Services\Inventory\Import\Support\ImportDiagnostics;
use Generator;

/**
 * 🎯 المسؤولية الوحيدة: تحويل صفوف الملف الخام إلى `WarehouseMatrixRow` **نظيفة**
 * بتطبيق قواعد الملف المتفق عليها، وتسجيل كل تصحيح تلقائي بالتحذيرات. لا يعرف
 * شيئاً عن قاعدة البيانات ولا عن المنتجات ولا عن الصيدليات المسجَّلة.
 *
 * القواعد (كلها بقرارك):
 *  1. الفراغ = 0 (داخل المصفوفة فقط: أعمدة المركزي والصيدليات).
 *  2. السالب = 0 + عدّاد تحذير ظاهر.
 *  3. الكسر يُخفَّض لأقرب عدد صحيح (2.67 → 2) + عدّاد تحذير.
 *  4. لا شيء يُتجاهَل بصمت: نص بخانة كمية، رقم خارج المدى، صف بلا رمز، بيانات
 *     تحت عمود بلا رأس، رمز مكرر — كلها تُسجَّل.
 *
 * 🎓 لماذا Generator وليس مصفوفة صفوف؟ كشفك 19,713 صفاً؛ كشف أكبر منه بعشر مرات
 * لا يغيّر شيئاً بالذاكرة: صف واحد بكل لحظة. وهذا ما يُنهي الأثر الجانبي الأصلي
 * لـ PharmacyInventoryImport (تجميع كل الصفوف ثم نسخها ثلاث مرات).
 *
 * ⚖️ الصف الفارغ كلياً يُتخطى **بصمت** عمداً — ليس بيانات، وكشوف الـ ERP تنتهي
 * غالباً بآلاف الصفوف المنسَّقة الفارغة؛ عدّها تحذيراً كان سيُغرق الرسالة بلا معنى.
 * أما صف له بيانات لكن بلا رمز مادة فيُسجَّل (قد يكون إجماليات لُصقت بالخطأ).
 *
 * 🔢 دقة الأعداد: Excel يخزّن الأعداد كـ double، فقد تصلنا 2.9999999999999996 بدل 3
 * (ناتج صيغة). نقرّب لـ 6 منازل **قبل** التخفيض، وإلا صار 3 مُخفَّضاً إلى 2 بخطأ
 * صامت — بالضبط فئة الأخطاء الحسابية "الصحيحة تركيبياً" الموثَّقة بتقريرك.
 */
final class WarehouseMatrixRowParser
{
    /** أكبر كمية مقبولة — أقل من سعة عمود INT (2,147,483,647) بهامش أمان. */
    public const MAX_QUANTITY = 2_000_000_000;

    public const SKIP_NO_BARCODE = 'صف بلا رمز مادة';
    public const SKIP_TOTALS_ROW = 'صف إجماليات';
    public const SKIP_SCIENTIFIC_BARCODE = 'رمز مادة بصيغة علمية (E+) — فقد الملف أرقامه عند التصدير';

    private const DEFAULT_COMPANY = '-';
    private const FLOAT_TOLERANCE_DECIMALS = 6;

    /** يبدأ بها نص "صف الإجماليات" بعد التطبيع (ArabicTextNormalizer). */
    private const TOTALS_PREFIXES = ['اجمالي', 'الاجمالي', 'مجموع', 'المجموع', 'total'];

    public function __construct(
        private readonly TabularFileReader $reader,
    ) {
    }

    /**
     * يفتح الملف ويتحقق من رؤوسه **فوراً**، ثم يُعيد الصفوف على التدفق.
     *
     * @throws InvalidImportFileException ملف تالف، أو رؤوس لا تطابق العقد
     */
    public function parse(string $absolutePath, ImportDiagnostics $diagnostics): ParsedMatrix
    {
        $source = $this->reader->read($absolutePath);

        if (!$source->valid()) {
            throw InvalidImportFileException::emptyFile();
        }

        $columns = MatrixColumnMap::fromHeaderRow($source->current());

        return new ParsedMatrix($columns, $this->rows($source, $columns, $diagnostics));
    }

    /**
     * @param Generator<int, array<int, string|int|float|bool|null>> $source
     * @return Generator<int, WarehouseMatrixRow>
     */
    private function rows(Generator $source, MatrixColumnMap $columns, ImportDiagnostics $diagnostics): Generator
    {
        $seenBarcodes = [];
        $lastMeaningfulColumn = $columns->lastColumnIndex();

        // صف الرؤوس استُهلك بـ parse() — نتقدم عنه. حلقة يدوية (لا foreach) لأن
        // foreach على Generator بدأ سيستدعي rewind() ويفشل بعد أول next().
        for ($source->next(); $source->valid(); $source->next()) {
            $rowNumber = (int) $source->key();
            $cells = $source->current();

            if (self::isBlankRow($cells)) {
                continue;
            }

            $barcode = self::text($cells[MatrixColumnMap::BARCODE] ?? null);

            if ($barcode === '') {
                $diagnostics->recordSkippedRow($rowNumber, self::SKIP_NO_BARCODE);
                continue;
            }

            if (self::looksLikeTotalsLabel($barcode)) {
                $diagnostics->recordSkippedRow($rowNumber, self::SKIP_TOTALS_ROW);
                continue;
            }

            if (preg_match('/^\d+(\.\d+)?E\+\d+$/i', $barcode) === 1) {
                $diagnostics->recordSkippedRow($rowNumber, self::SKIP_SCIENTIFIC_BARCODE);
                continue;
            }

            $company = self::text($cells[MatrixColumnMap::COMPANY] ?? null);

            $central = $this->quantity(
                $cells[MatrixColumnMap::CENTRAL] ?? null,
                $rowNumber,
                $barcode,
                $columns->locationLabel(MatrixColumnMap::CENTRAL),
                $diagnostics,
            );

            $pharmacies = [];
            foreach ($columns->pharmacyColumns() as $index => $header) {
                $pharmacies[$index] = $this->quantity($cells[$index] ?? null, $rowNumber, $barcode, $header, $diagnostics);
            }

            // خلايا خلف آخر عمود له معنى: بيانات تحت عمود بلا رأس. لا نعرف لمن هي،
            // فلا نقرؤها — لكن نُبلغ عنها بدل أن تختفي.
            foreach ($cells as $index => $cell) {
                if ($index > $lastMeaningfulColumn && self::text($cell) !== '') {
                    $diagnostics->recordStrayCell($rowNumber, 'العمود ' . MatrixColumnMap::columnLetter((int) $index));
                }
            }

            if (isset($seenBarcodes[$barcode])) {
                $diagnostics->recordDuplicateBarcode($rowNumber, $barcode);
            }
            $seenBarcodes[$barcode] = true;

            yield $rowNumber => new WarehouseMatrixRow(
                sourceRow: $rowNumber,
                barcode: $barcode,
                name: self::text($cells[MatrixColumnMap::NAME] ?? null),
                company: $company === '' ? self::DEFAULT_COMPANY : $company,
                centralQuantity: $central,
                pharmacyQuantities: $pharmacies,
            );
        }
    }

    /**
     * خانة كمية → عدد صحيح ≥ 0، مع تسجيل أي تصحيح.
     */
    private function quantity(
        string|int|float|bool|null $raw,
        int $row,
        string $barcode,
        string $location,
        ImportDiagnostics $diagnostics,
    ): int {
        if ($raw === null) {
            return 0; // القاعدة 1: الفراغ = صفر
        }

        // مُستخرَج إلى ArabicNumberParser — يشترك به MaterialMasterRowParser بنفس القواعد بالضبط.
        $value = ArabicNumberParser::toFloat($raw);

        if ($value === null) {
            // "-" و"—" وحدهما تعنيان "لا شيء" بكشوف الـ ERP: صفر بلا ضجيج.
            if (is_string($raw) && in_array(trim($raw), ['', '-', '–', '—'], true)) {
                return 0;
            }

            $diagnostics->recordInvalidQuantity($row, $barcode, $location, is_bool($raw) ? ($raw ? 'TRUE' : 'FALSE') : (string) $raw);

            return 0;
        }

        if (abs($value) > self::MAX_QUANTITY) {
            $diagnostics->recordInvalidQuantity($row, $barcode, $location, (string) $raw);

            return 0;
        }

        $value = round($value, self::FLOAT_TOLERANCE_DECIMALS);

        if ($value < 0) {
            $diagnostics->recordNegativeQuantity($row, $barcode, $location, $value);

            return 0; // القاعدة 2
        }

        $floored = (int) floor($value);

        if ($value !== (float) $floored) {
            $diagnostics->recordFractionalQuantity($row, $barcode, $location, $value, $floored);
        }

        return $floored; // القاعدة 3
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

    private static function looksLikeTotalsLabel(string $barcode): bool
    {
        $key = ArabicTextNormalizer::key($barcode);

        foreach (self::TOTALS_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * خلية → نص. الرقم العشري الصحيح (باركود مخزَّن كرقم بـ Excel) يُكتب بلا صيغة
     * علمية: (string) 6.281E12 يعطي "6.281E+12" فيُدمِّر الرمز.
     */
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
