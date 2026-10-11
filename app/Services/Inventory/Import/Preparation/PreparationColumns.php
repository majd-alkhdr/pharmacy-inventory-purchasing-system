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
 *   A: رمز المادة | B: اسم المادة (للقراءة البشرية فقط — لا يُستخدم بالمطابقة) | C: الكمية المحضّرة
 *
 * القالب الجاهز (PreparationTemplateExport) يُنزَّل من شاشة المطابقة مملوءاً بمواد الفاتورة
 * وعمود الكمية فارغاً — فيكتب المحضّر الأرقام فقط ولا يخطئ برمز.
 */
final class PreparationColumns
{
    public const BARCODE = 0;
    public const NAME = 1;
    public const PREPARED_QTY = 2;

    /** @var array<int, list<string>> */
    private const EXPECTED = [
        self::BARCODE => ['رمز المادة', 'الباركود', 'barcode'],
        self::NAME => ['اسم المادة', 'الاسم', 'name'],
        self::PREPARED_QTY => ['الكمية المحضرة', 'الكمية المحضّرة', 'الكمية الفعلية', 'prepared quantity', 'prepared_qty'],
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
