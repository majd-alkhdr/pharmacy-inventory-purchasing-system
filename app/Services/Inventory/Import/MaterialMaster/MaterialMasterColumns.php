<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\MaterialMaster;

use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use App\Services\Inventory\Import\Matrix\MatrixColumnMap;
use App\Services\Inventory\Import\Support\ArabicTextNormalizer;

/**
 * 🎯 يتحقق من رؤوس ملف "بيانات المواد" (الملف الثاني، المنفصل تماماً عن كشف
 * المستودعات). بعكس MatrixColumnMap، الأعمدة هنا **ثابتة العدد** (6) — لا صيدليات
 * ديناميكية هنا، فلا حاجة لمنطق "حتى آخر عمود له رأس".
 *
 * الترتيب الإجباري: رمز المادة ← اسم المادة ← الشركة ← السعر ← حجم العبوة ←
 * الحد الأدنى للفرق (= min_reorder_gap على مستوى المادة، راجع مايجريشن
 * 2026_09_22_000002 لسبب وجود هذا العمود تحديداً).
 */
final class MaterialMasterColumns
{
    public const BARCODE = 0;
    public const NAME = 1;
    public const COMPANY = 2;
    public const PRICE = 3;
    public const PACK_SIZE = 4;
    public const MIN_REORDER_GAP = 5;

    /** @var array<int, list<string>> فهرس العمود ← مرادفات رأسه المقبولة (نفس أسلوب MatrixColumnMap) */
    private const EXPECTED = [
        self::BARCODE => ['رمز المادة', 'الباركود', 'barcode'],
        self::NAME => ['اسم المادة', 'الاسم', 'name'],
        self::COMPANY => ['الشركة', 'company'],
        self::PRICE => ['السعر', 'price'],
        self::PACK_SIZE => ['حجم العبوة', 'حجم عبوة', 'pack size', 'pack_size'],
        self::MIN_REORDER_GAP => ['الحد الأدنى للفرق', 'الحد الأدنى للمخزون', 'min reorder gap', 'min_reorder_gap'],
    ];

    /**
     * @throws InvalidImportFileException رأس مفقود أو لا يطابق أياً من المرادفات المتوقعة لموضعه
     */
    public static function fromHeaderRow(array $headerCells): self
    {
        foreach (self::EXPECTED as $index => $synonyms) {
            $actual = trim((string) ($headerCells[$index] ?? ''));

            if ($actual === '') {
                throw InvalidImportFileException::materialMasterHeaderMismatch(
                    MatrixColumnMap::columnLetter($index),
                    self::describe($index),
                    '',
                );
            }

            $actualKey = ArabicTextNormalizer::key($actual);
            $synonymKeys = array_map(ArabicTextNormalizer::key(...), $synonyms);

            if (!in_array($actualKey, $synonymKeys, true)) {
                throw InvalidImportFileException::materialMasterHeaderMismatch(MatrixColumnMap::columnLetter($index), self::describe($index), $actual);
            }
        }

        return new self();
    }

    private static function describe(int $index): string
    {
        return self::EXPECTED[$index][0];
    }
}
