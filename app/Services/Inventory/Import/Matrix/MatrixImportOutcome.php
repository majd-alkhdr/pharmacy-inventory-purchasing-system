<?php

declare(strict_types=1);

namespace App\Services\Inventory\Import\Matrix;

use App\Services\Inventory\Import\Support\ImportDiagnostics;
use App\Services\Shared\ImportHistory\ImportStats;
use App\Services\Shared\ImportHistory\ProvidesImportStats;

/**
 * 🎯 ما يعيده المستورد للمُنسِّق (InventoryUploadService) — حالتان فقط:
 *  - applied: كُتب كل شيء. المُنسِّق يكمل (أصناف معلَّقة؟ ترميم؟).
 *  - "يحتاج مطابقة صيدليات": **لم يُكتب أي شيء**. المُنسِّق يوقف الجلسة ويعرض الشاشة.
 *
 * 🆕 (أ.1) يصف نفسه لسجل الرفع: الناجح = مواد معروفة كُتبت، المتجاهَل = صفوف متخطّاة +
 * مواد مجهولة بلا رصيد (لم تُقترح للإنشاء)، والمحذَّر = كل تحذيرات ImportDiagnostics.
 */
final class MatrixImportOutcome implements ProvidesImportStats
{
    public function importStats(): ImportStats
    {
        return ImportStats::fromDiagnostics(
            succeeded: $this->productsInFile,
            diagnostics: $this->diagnostics,
            message: implode(' ', $this->summaryLines()),
            extraSkipped: $this->unknownWithoutStock,
        );
    }

    /**
     * @param list<int>                         $affectedPharmacyIds
     * @param list<string>                      $headersNeedingMapping
     * @param list<array{barcode: string, material_name: string, company: string, quantity: int, pack_size: null, price: null}> $pendingProducts مواد مجهولة لها رصيد (بصيغة PendingInventoryItem)
     */
    public function __construct(
        public readonly bool $applied,
        public readonly array $affectedPharmacyIds,
        public readonly array $headersNeedingMapping,
        public readonly array $pendingProducts,
        public readonly int $productsInFile,
        public readonly int $centralRowsWritten,
        public readonly int $pharmacyRowsWritten,
        public readonly int $zeroRowsMaterialized,
        public readonly int $productsRefreshed,
        public readonly int $unknownWithoutStock,
        public readonly ImportDiagnostics $diagnostics,
    ) {
    }

    /** @param list<string> $headers */
    public static function needsPharmacyMapping(array $headers, ImportDiagnostics $diagnostics): self
    {
        return new self(false, [], $headers, [], 0, 0, 0, 0, 0, 0, $diagnostics);
    }

    public function needsMapping(): bool
    {
        return !$this->applied;
    }

    /**
     * أسطر عربية لرسالة الاكتمال: ما كُتب، ثم كل تحذير (لا شيء صامت).
     *
     * @return list<string>
     */
    public function summaryLines(): array
    {
        $lines = [sprintf(
            '📥 استُورد كشف المستودعات: %d مادة، %d صيدلية (%d صف رصيد، منها %d صفر مُثبَّت لأزواج المخزون القياسي).',
            $this->productsInFile,
            count($this->affectedPharmacyIds),
            $this->pharmacyRowsWritten,
            $this->zeroRowsMaterialized,
        )];

        if ($this->productsRefreshed > 0) {
            $lines[] = "✏️ حُدِّث اسم/شركة {$this->productsRefreshed} مادة موجودة.";
        }

        if ($this->pendingProducts !== []) {
            $lines[] = '🆕 ' . count($this->pendingProducts) . ' مادة جديدة لها رصيد تنتظر تأكيدك. '
                . 'أرصدة صيدلياتها لا تُخزَّن الآن (لا مخزون قياسي لها بعد) وتُقرأ من الكشف التالي.';
        }

        if ($this->unknownWithoutStock > 0) {
            $lines[] = "ℹ️ {$this->unknownWithoutStock} مادة غير معروفة بلا رصيد بأي موقع لم تُقترح للإنشاء.";
        }

        return [...$lines, ...$this->diagnostics->summaryLines()];
    }
}
