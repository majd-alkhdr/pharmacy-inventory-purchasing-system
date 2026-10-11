<?php

declare(strict_types=1);

namespace App\Services\Inventory\Reconciliation;

use App\Models\TransferReconciliation;
use App\Services\Inventory\Import\Support\ImportDiagnostics;
use App\Services\Shared\ImportHistory\ImportStats;
use App\Services\Shared\ImportHistory\ProvidesImportStats;

/** 🎯 (ب.3) نتيجة رفع ملف تحضير — تصف نفسها لسجل الرفع (أ.1) كأي مستورد آخر. */
final class ReconciliationOutcome implements ProvidesImportStats
{
    public function __construct(
        public readonly TransferReconciliation $reconciliation,
        public readonly int $rowsInFile,
        public readonly ImportDiagnostics $diagnostics,
    ) {
    }

    public function importStats(): ImportStats
    {
        return ImportStats::fromDiagnostics($this->rowsInFile, $this->diagnostics, $this->summary());
    }

    public function summary(): string
    {
        $lines = ["📦 مطابقة التحضير: {$this->reconciliation->items_count} مادة، منها {$this->reconciliation->mismatched_count} بفرق يحتاج سبباً."];

        return implode(' ', [...$lines, ...$this->diagnostics->summaryLines()]);
    }
}
