<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Import;

use App\Enums\ImportWarning;
use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use App\Services\Inventory\Import\Matrix\WarehouseMatrixRow;
use App\Services\Inventory\Import\Matrix\WarehouseMatrixRowParser;
use App\Services\Inventory\Import\Support\ImportDiagnostics;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Inventory\Import\Doubles\ArrayTabularFileReader;

final class WarehouseMatrixRowParserTest extends TestCase
{
    /** رؤوس بصيدليتين: العمودان 4 و5. */
    private const HEADER = ['رمز المادة', 'الاسم', 'الشركة', 'مستودع الصناعة', 'صيدلية أ', 'صيدلية ب'];

    /**
     * @param list<array<int, string|int|float|bool|null>|null> $dataRows الصف الأول (الرؤوس) يُضاف تلقائياً
     * @return list<WarehouseMatrixRow>
     */
    private function parse(array $dataRows, ImportDiagnostics $diagnostics, array $header = self::HEADER): array
    {
        $parser = new WarehouseMatrixRowParser(new ArrayTabularFileReader([$header, ...$dataRows]));

        return iterator_to_array($parser->parse('ignored.xlsx', $diagnostics)->rows(), false);
    }

    public function test_blank_cells_inside_the_matrix_are_zero_and_the_row_is_still_yielded(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([['111', 'بنادول', 'شركة أ', null, null, null]], $diagnostics);

        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows[0]->centralQuantity);
        $this->assertSame([4 => 0, 5 => 0], $rows[0]->pharmacyQuantities);
        $this->assertFalse($diagnostics->hasWarnings());
    }

    public function test_negative_quantities_become_zero_and_are_counted_visibly(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([['111', 'ب', 'ش', -2, -3, 4]], $diagnostics);

        $this->assertSame(0, $rows[0]->centralQuantity);
        $this->assertSame([4 => 0, 5 => 4], $rows[0]->pharmacyQuantities);
        $this->assertSame(2, $diagnostics->count(ImportWarning::NegativeQuantity));

        $first = $diagnostics->samples(ImportWarning::NegativeQuantity)[0];
        $this->assertSame('المخزون المركزي', $first['location']);
        $this->assertSame(-2.0, $first['value']);
        $this->assertSame(2, $first['row']);
    }

    public function test_fractions_are_floored_and_counted(): void
    {
        $diagnostics = new ImportDiagnostics();

        // القيم الفعلية الأكثر تكراراً بكشفك: 2.67 و1.5 و0.5
        $rows = $this->parse([['111', 'ب', 'ش', 2.67, 1.5, 0.5]], $diagnostics);

        $this->assertSame(2, $rows[0]->centralQuantity);
        $this->assertSame([4 => 1, 5 => 0], $rows[0]->pharmacyQuantities);
        $this->assertSame(3, $diagnostics->count(ImportWarning::FractionalQuantity));
        $this->assertSame(2, $diagnostics->samples(ImportWarning::FractionalQuantity)[0]['stored_as']);
    }

    public function test_excel_float_noise_is_not_mistaken_for_a_fraction(): void
    {
        $diagnostics = new ImportDiagnostics();

        // ناتج صيغة بـ Excel: 3 مخزَّنة كـ 2.9999999999999996
        $rows = $this->parse([['111', 'ب', 'ش', 2.9999999999999996, 0.0000001, 5.0]], $diagnostics);

        $this->assertSame(3, $rows[0]->centralQuantity);
        $this->assertSame([4 => 0, 5 => 5], $rows[0]->pharmacyQuantities);
        $this->assertFalse($diagnostics->hasWarnings());
    }

    public function test_numbers_written_as_text_are_understood(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([['111', 'ب', 'ش', '12', '١٢', '1,5']], $diagnostics);

        $this->assertSame(12, $rows[0]->centralQuantity);
        $this->assertSame([4 => 12, 5 => 1], $rows[0]->pharmacyQuantities);
        $this->assertSame(1, $diagnostics->count(ImportWarning::FractionalQuantity)); // "1,5" ظاهرة لا صامتة
    }

    public function test_dash_and_whitespace_mean_nothing_without_a_warning(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([['111', 'ب', 'ش', '-', '   ', '—']], $diagnostics);

        $this->assertSame(0, $rows[0]->centralQuantity);
        $this->assertSame([4 => 0, 5 => 0], $rows[0]->pharmacyQuantities);
        $this->assertFalse($diagnostics->hasWarnings());
    }

    public function test_garbage_in_a_quantity_cell_is_zero_but_never_silent(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([['111', 'ب', 'ش', 'N/A', true, 3.0e9]], $diagnostics);

        $this->assertSame(0, $rows[0]->centralQuantity);
        $this->assertSame([4 => 0, 5 => 0], $rows[0]->pharmacyQuantities);
        $this->assertSame(3, $diagnostics->count(ImportWarning::InvalidQuantity));
    }

    public function test_barcodes_stored_as_numbers_do_not_become_scientific_notation(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([
            [6281000123456.0, 'أ', 'ش', 1, 0, 0],
            [5, 'ب', 'ش', 1, 0, 0],
            ['  789  ', 'ج', 'ش', 1, 0, 0],
        ], $diagnostics);

        $this->assertSame('6281000123456', $rows[0]->barcode);
        $this->assertSame('5', $rows[1]->barcode);
        $this->assertSame('789', $rows[2]->barcode);
    }

    public function test_barcode_already_ruined_by_export_is_skipped_and_reported(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([['6.281E+12', 'أ', 'ش', 1, 0, 0]], $diagnostics);

        $this->assertSame([], $rows);
        $this->assertSame(1, $diagnostics->count(ImportWarning::SkippedRow));
        $this->assertSame(WarehouseMatrixRowParser::SKIP_SCIENTIFIC_BARCODE, $diagnostics->samples(ImportWarning::SkippedRow)[0]['reason']);
    }

    public function test_rows_without_barcode_and_totals_rows_are_skipped_and_reported(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([
            [null, 'بلا رمز', 'ش', 5, 0, 0],
            ['اجمالي الارصدة', null, null, 100, 200, 300],
            ['إجمالي', null, null, 1, 1, 1],
            ['222', 'سليمة', 'ش', 1, 0, 0],
        ], $diagnostics);

        $this->assertCount(1, $rows);
        $this->assertSame('222', $rows[0]->barcode);
        $this->assertSame(3, $diagnostics->count(ImportWarning::SkippedRow));
    }

    public function test_fully_blank_rows_are_skipped_silently_and_row_numbers_stay_honest(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([
            ['111', 'أ', 'ش', 1, 0, 0],   // الصف 2
            null,                          // الصف 3 فارغ
            [null, null, null],            // الصف 4 فارغ
            ['222', 'ب', 'ش', 2, 0, 0],   // الصف 5
        ], $diagnostics);

        $this->assertSame([2, 5], array_map(fn (WarehouseMatrixRow $r) => $r->sourceRow, $rows));
        $this->assertFalse($diagnostics->hasWarnings());
    }

    public function test_blank_company_defaults_to_dash_like_the_existing_imports(): void
    {
        $rows = $this->parse([['111', 'أ', null, 1, 0, 0]], new ImportDiagnostics());

        $this->assertSame('-', $rows[0]->company);
    }

    public function test_data_under_a_headerless_column_is_reported_not_read(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([['111', 'أ', 'ش', 1, 0, 0, 'ملاحظة', 'أخرى']], $diagnostics);

        $this->assertCount(1, $rows);
        $this->assertSame(2, $diagnostics->count(ImportWarning::StrayCell));
        $this->assertSame('العمود G', $diagnostics->samples(ImportWarning::StrayCell)[0]['column']);
    }

    public function test_a_summary_column_is_ignored_silently(): void
    {
        $diagnostics = new ImportDiagnostics();
        $header = [...self::HEADER, 'المجموع'];

        $rows = $this->parse([['111', 'أ', 'ش', 1, 2, 3, 6]], $diagnostics, $header);

        $this->assertSame([4 => 2, 5 => 3], $rows[0]->pharmacyQuantities);
        $this->assertFalse($diagnostics->hasWarnings());
    }

    public function test_duplicate_barcodes_are_reported_and_both_rows_are_yielded(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([
            ['111', 'أ', 'ش', 1, 0, 0],
            ['111', 'أ', 'ش', 2, 0, 0],
        ], $diagnostics);

        $this->assertCount(2, $rows);
        $this->assertSame(1, $diagnostics->count(ImportWarning::DuplicateBarcode));
    }

    public function test_pharmacy_count_is_dynamic(): void
    {
        $header = [...self::HEADER, 'صيدلية ج'];

        $rows = $this->parse([['111', 'أ', 'ش', 1, 2, 3, 4]], new ImportDiagnostics(), $header);

        $this->assertSame([4 => 2, 5 => 3, 6 => 4], $rows[0]->pharmacyQuantities);
    }

    public function test_bad_headers_fail_immediately_at_parse_not_at_iteration(): void
    {
        $parser = new WarehouseMatrixRowParser(new ArrayTabularFileReader([['الاسم', 'رمز المادة', 'الشركة', 'مستودع الصناعة']]));

        $this->expectException(InvalidImportFileException::class);

        $parser->parse('ignored.xlsx', new ImportDiagnostics()); // لا نستهلك rows() إطلاقاً
    }

    public function test_an_empty_file_is_rejected(): void
    {
        $parser = new WarehouseMatrixRowParser(new ArrayTabularFileReader([]));

        $this->expectException(InvalidImportFileException::class);

        $parser->parse('ignored.xlsx', new ImportDiagnostics());
    }

    public function test_a_header_only_file_yields_no_rows(): void
    {
        $this->assertSame([], $this->parse([], new ImportDiagnostics()));
    }
}
