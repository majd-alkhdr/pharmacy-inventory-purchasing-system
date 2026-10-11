<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Enums\ImportFileType;
use App\Enums\ImportLogStatus;
use App\Enums\ImportWarning;
use App\Models\ImportLog;
use App\Models\Product;
use App\Services\Inventory\Import\Support\ImportDiagnostics;
use App\Services\Inventory\Import\Validation\CrossFileCodeValidator;
use App\Services\Shared\ImportHistory\ImportHistoryService;
use App\Services\Shared\ImportHistory\ImportStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/** 🎯 (أ.1 + أ.4) سجل الرفع الموحَّد وفحص تطابق الأكواد بين الملفات. */
final class ImportHistoryAndCrossFileTest extends TestCase
{
    use RefreshDatabase;

    private function history(): ImportHistoryService
    {
        return $this->app->make(ImportHistoryService::class);
    }

    public function test_track_closes_the_log_with_stats_and_warnings(): void
    {
        $diagnostics = new ImportDiagnostics();
        $diagnostics->recordSkippedRow(3, 'صف بلا رمز');

        $this->history()->track(ImportFileType::MaterialMaster, 'items.xlsx', function (ImportLog $log) use ($diagnostics) {
            $this->history()->complete($log, ImportStats::fromDiagnostics(10, $diagnostics));
        });

        $log = ImportLog::firstOrFail();
        $this->assertSame(ImportLogStatus::SucceededWithWarnings, $log->status);
        $this->assertSame(10, $log->rows_succeeded);
        $this->assertSame(1, $log->rows_skipped);
        $this->assertSame('items.xlsx', $log->original_filename);
        $this->assertNotNull($log->finished_at);
    }

    public function test_track_marks_the_log_failed_and_rethrows(): void
    {
        try {
            $this->history()->track(ImportFileType::Sales, 'sales.xlsx', fn () => throw new RuntimeException('ملف تالف'));
            $this->fail('كان يجب إعادة رمي الاستثناء.');
        } catch (RuntimeException) {
        }

        $log = ImportLog::firstOrFail();
        $this->assertSame(ImportLogStatus::Failed, $log->status);
        $this->assertSame('ملف تالف', $log->message);
    }

    public function test_session_sync_finishes_open_logs_but_never_downgrades_a_completed_one(): void
    {
        $matrix = $this->history()->begin(ImportFileType::WarehouseMatrix, 'matrix.xlsx', 's1');
        $sales = $this->history()->begin(ImportFileType::Sales, 'sales.xlsx', 's1');

        $this->history()->complete($matrix, ImportStats::fromCounts(100));
        $this->history()->syncSessionStatus('s1', 'needs_mapping', 'بانتظار مطابقة');
        $this->assertSame(ImportLogStatus::AwaitingAction, $sales->fresh()->status);

        $this->history()->syncSessionStatus('s1', 'failed', 'انهار الترميم');

        $this->assertSame(ImportLogStatus::Failed, $sales->fresh()->status);
        $this->assertSame(ImportLogStatus::Succeeded, $matrix->fresh()->status);
        $this->assertStringContainsString('انهار الترميم', $matrix->fresh()->message);
        $this->assertSame($matrix->id, ImportLog::latestSuccessful(ImportFileType::WarehouseMatrix)->id);
    }

    public function test_cross_file_check_flags_both_directions_against_the_latest_matrix(): void
    {
        $matrixLog = $this->history()->begin(ImportFileType::WarehouseMatrix, 'matrix.xlsx');
        $this->history()->complete($matrixLog, ImportStats::fromCounts(1));

        // العمود يكتبه WarehouseMatrixImporter وحده (ليس fillable عمداً) — نحاكيه بـ forceFill.
        Product::create(['barcode' => '111', 'name' => 'بالكشف', 'company' => 'ش'])
            ->forceFill(['last_matrix_import_log_id' => $matrixLog->id])->save();
        Product::create(['barcode' => '222', 'name' => 'ليس بالكشف', 'company' => 'ش']);

        $validator = new CrossFileCodeValidator();
        $diagnostics = new ImportDiagnostics();

        $unknown = $validator->recordUnknownProducts(['111' => 2, '222' => 3, '999' => 4], $diagnostics, ImportFileType::MaterialMaster);
        $missing = $validator->recordMissingFromLatestMatrix(['111' => 2, '222' => 3], $diagnostics);

        $this->assertSame(['999'], $unknown);
        $this->assertSame(1, $missing);
        $this->assertSame(1, $diagnostics->count(ImportWarning::UnknownProductCode));
        $this->assertSame(1, $diagnostics->count(ImportWarning::MissingFromLatestMatrix));
        $this->assertSame('222', $diagnostics->samples(ImportWarning::MissingFromLatestMatrix)[0]['barcode']);
    }

    public function test_unknown_pharmacy_names_are_flagged_after_arabic_normalisation(): void
    {
        \App\Models\Pharmacy::create(['name' => 'صيدلية الأمل']);
        $diagnostics = new ImportDiagnostics();

        $unknown = (new CrossFileCodeValidator())->recordUnknownPharmacies(['صيدلية الامل' => 2, 'صيدلية وهمية' => 5], $diagnostics);

        $this->assertSame(['صيدلية وهمية'], $unknown);
        $this->assertSame(1, $diagnostics->count(ImportWarning::UnknownPharmacyCode));
    }
}
