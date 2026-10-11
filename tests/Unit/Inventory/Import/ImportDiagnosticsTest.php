<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Import;

use App\Enums\ImportWarning;
use App\Services\Inventory\Import\Support\ImportDiagnostics;
use PHPUnit\Framework\TestCase;

final class ImportDiagnosticsTest extends TestCase
{
    public function test_starts_clean(): void
    {
        $diagnostics = new ImportDiagnostics();

        $this->assertFalse($diagnostics->hasWarnings());
        $this->assertSame(0, $diagnostics->total());
        $this->assertSame([], $diagnostics->summaryLines());
    }

    public function test_counts_negatives_and_groups_them_by_location(): void
    {
        $diagnostics = new ImportDiagnostics();
        $diagnostics->recordNegativeQuantity(10, '111', 'صيدلية أ', -1);
        $diagnostics->recordNegativeQuantity(11, '112', 'صيدلية أ', -3);
        $diagnostics->recordNegativeQuantity(12, '113', 'صيدلية ب', -0.5);

        $this->assertSame(3, $diagnostics->count(ImportWarning::NegativeQuantity));
        $this->assertTrue($diagnostics->hasWarnings());

        $line = $diagnostics->summaryLines()[0];
        $this->assertStringContainsString('كمية سالبة حُوِّلت إلى صفر: 3', $line);
        $this->assertStringContainsString('صيدلية أ (2)', $line);
    }

    public function test_samples_keep_the_original_value_for_review(): void
    {
        $diagnostics = new ImportDiagnostics();
        $diagnostics->recordNegativeQuantity(189, '900', 'صيدلية أ', -387);

        $this->assertSame(
            [['row' => 189, 'barcode' => '900', 'location' => 'صيدلية أ', 'value' => -387]],
            $diagnostics->samples(ImportWarning::NegativeQuantity),
        );
    }

    public function test_sample_cap_never_affects_the_exact_counter(): void
    {
        $diagnostics = new ImportDiagnostics();
        $total = ImportDiagnostics::MAX_SAMPLES_PER_WARNING + 5;

        for ($i = 1; $i <= $total; $i++) {
            $diagnostics->recordDuplicateBarcode($i, "B{$i}");
        }

        $this->assertSame($total, $diagnostics->count(ImportWarning::DuplicateBarcode));
        $this->assertCount(ImportDiagnostics::MAX_SAMPLES_PER_WARNING, $diagnostics->samples(ImportWarning::DuplicateBarcode));
        $this->assertTrue($diagnostics->isTruncated(ImportWarning::DuplicateBarcode));
    }

    public function test_summary_shows_only_the_top_three_buckets(): void
    {
        $diagnostics = new ImportDiagnostics();
        foreach (['أ' => 5, 'ب' => 4, 'ج' => 3, 'د' => 1] as $location => $times) {
            for ($i = 0; $i < $times; $i++) {
                $diagnostics->recordNegativeQuantity($i + 1, 'X', "صيدلية {$location}", -1);
            }
        }

        $line = $diagnostics->summaryLines()[0];

        $this->assertStringContainsString('صيدلية أ (5)', $line);
        $this->assertStringContainsString('صيدلية ج (3)', $line);
        $this->assertStringNotContainsString('صيدلية د', $line);
    }

    public function test_survives_a_json_round_trip(): void
    {
        $diagnostics = new ImportDiagnostics();
        $diagnostics->recordNegativeQuantity(3, '1', 'المخزون المركزي', -2);
        $diagnostics->recordFractionalQuantity(4, '2', 'صيدلية أ', 2.67, 2);
        $diagnostics->recordSkippedRow(5, 'صف بلا رمز مادة');
        $diagnostics->recordStrayCell(6, 'العمود O');
        $diagnostics->recordInvalidQuantity(7, '3', 'صيدلية ب', 'N/A');

        $restored = ImportDiagnostics::fromArray(json_decode(json_encode($diagnostics->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

        $this->assertSame($diagnostics->toArray(), $restored->toArray());
        $this->assertSame($diagnostics->summaryLines(), $restored->summaryLines());
        $this->assertSame(5, $restored->total());
    }

    public function test_unknown_warning_keys_from_other_versions_are_ignored(): void
    {
        $restored = ImportDiagnostics::fromArray(['counts' => ['future_warning' => 9, 'negative_quantity' => 2]]);

        $this->assertSame(2, $restored->total());
    }
}
