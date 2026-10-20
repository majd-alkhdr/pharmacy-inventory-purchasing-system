<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Preparation;

use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use App\Services\Inventory\Import\Matrix\MatrixColumnMap;
use App\Services\Inventory\Import\Support\ArabicTextNormalizer;

/**
 * 🎯 (ب.3) "فورم" ملف التحضير المتفق عليه — بنفس نمط MaterialMasterColumns بالضبط: صف رؤوس
 * إلزامي بترتيب ثابت ومرادفات مقبولة لكل عمود (تُقارن بعد تطبيع عربي — همزات، تاء مربوطة...).
 *
 *   A: اسم المادة | B: الكمية المحضّرة   (أي أعمدة بعدهما تُهمَل)
 *
 * المطابقة بالاسم لا بالرمز: البرنامج المحاسبي لا يُصدر رمز المادة بفاتورة المناقلة (راجع
 * PreparationNameMatcher). القالب الجاهز (PreparationTemplateExport) يُنزَّل من شاشة المطابقة
 * مملوءاً بأسماء مواد الفاتورة وعمود الكمية فارغاً — فيكتب المحضّر الأرقام فقط.
 */
final class PreparationColumns
{
    public const NAME = 0;
    public const PREPARED_QTY = 1;

    /** @var array<int, list<string>> */
    private const EXPECTED = [
        self::NAME => ['اسم المادة', 'الاسم', 'اسم الصنف', 'المادة', 'name'],
        self::PREPARED_QTY => ['الكمية المحضرة', 'الكمية المحضّرة', 'الكمية الفعلية', 'الكمية', 'prepared quantity', 'prepared_qty'],
    ];

    /** @return list<string> رؤوس القالب كما تُكتب */
    public static function headings(): array
    {
        return array_map(fn (array $synonyms) => $synonyms[0], self::EXPECTED);
    }

    /**
     * @param array<int, string|int|float|bool|null> $headerCells
     * @throws InvalidImportFileException
     */
    public static function assertHeaderRow(array $headerCells): void
    {
        foreach (self::EXPECTED as $index => $synonyms) {
            $actual = trim((string) ($headerCells[$index] ?? ''));
            $synonymKeys = array_map(ArabicTextNormalizer::key(...), $synonyms);

            if ($actual === '' || !in_array(ArabicTextNormalizer::key($actual), $synonymKeys, true)) {
                throw InvalidImportFileException::preparationHeaderMismatch(MatrixColumnMap::columnLetter($index), $synonyms[0], $actual);
            }
        }
    }
}
