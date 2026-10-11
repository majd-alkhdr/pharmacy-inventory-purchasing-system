<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Import;

use App\Enums\ImportWarning;
use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use App\Services\Inventory\Import\MaterialMaster\MaterialMasterRow;
use App\Services\Inventory\Import\MaterialMaster\MaterialMasterRowParser;
use App\Services\Inventory\Import\Support\ImportDiagnostics;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Inventory\Import\Doubles\ArrayTabularFileReader;

final class MaterialMasterRowParserTest extends TestCase
{
    private const HEADER = ['رمز المادة', 'اسم المادة', 'الشركة', 'السعر', 'حجم العبوة', 'الحد الأدنى للفرق'];

    /**
     * @param list<array<int, string|int|float|bool|null>|null> $dataRows
     * @return list<MaterialMasterRow>
     */
    private function parse(array $dataRows, ImportDiagnostics $diagnostics, array $header = self::HEADER): array
    {
        $parser = new MaterialMasterRowParser(new ArrayTabularFileReader([$header, ...$dataRows]));

        return iterator_to_array($parser->parse('ignored.xlsx', $diagnostics), false);
    }

    public function test_a_fully_populated_row_is_parsed_as_is(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([['111', 'بنادول', 'شركة أ', 12.5, 10, 5]], $diagnostics);

        $this->assertCount(1, $rows);
        $this->assertSame(['بنادول', 'شركة أ', 12.5, 10, 5], [
            $rows[0]->name, $rows[0]->company, $rows[0]->price, $rows[0]->packSize, $rows[0]->minReorderGap,
        ]);
        $this->assertFalse($diagnostics->hasWarnings());
    }

    public function test_blank_optional_cells_mean_leave_untouched_not_zero(): void
    {
        $rows = $this->parse([['111', null, null, null, null, null]], new ImportDiagnostics());

        $this->assertSame([null, null, null, null, null], [
            $rows[0]->name, $rows[0]->company, $rows[0]->price, $rows[0]->packSize, $rows[0]->minReorderGap,
        ]);
    }

    public function test_a_dash_means_leave_untouched_exactly_like_blank(): void
    {
        $rows = $this->parse([['111', '-', '-', '-', '-', '-']], new ImportDiagnostics());

        $this->assertSame([null, null, null, null, null], [
            $rows[0]->name, $rows[0]->company, $rows[0]->price, $rows[0]->packSize, $rows[0]->minReorderGap,
        ]);
    }

    public function test_a_row_with_only_a_barcode_and_nothing_else_is_skipped_and_reported(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([['111', null, null, null, null, null]], $diagnostics);
        // (repeated on purpose so the assertion below reads clearly)
        $this->assertSame([], $this->parse([['222', '-', '-', '-', '-', '-']], $diagnostics));
        $this->assertSame(2, $diagnostics->count(ImportWarning::SkippedRow));
    }

    public function test_a_row_without_a_barcode_is_skipped_and_reported(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([[null, 'بنادول', 'شركة أ', 5, 1, 1]], $diagnostics);

        $this->assertSame([], $rows);
        $this->assertSame(1, $diagnostics->count(ImportWarning::SkippedRow));
        $this->assertSame(MaterialMasterRowParser::SKIP_NO_BARCODE, $diagnostics->samples(ImportWarning::SkippedRow)[0]['reason']);
    }

    public function test_non_numeric_junk_in_a_numeric_column_is_left_untouched_with_a_warning(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([['111', 'بنادول', 'شركة أ', 'N/A', 'كثير', null]], $diagnostics);

        $this->assertSame(['بنادول', 'شركة أ', null, null], [$rows[0]->name, $rows[0]->company, $rows[0]->price, $rows[0]->packSize]);
        $this->assertSame(2, $diagnostics->count(ImportWarning::InvalidValue));
    }

    public function test_a_negative_number_is_left_untouched_with_a_warning_not_clamped_to_zero(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([['111', 'بنادول', 'شركة أ', -5, -1, null]], $diagnostics);

        $this->assertSame([null, null], [$rows[0]->price, $rows[0]->packSize]);
        $this->assertSame(2, $diagnostics->count(ImportWarning::InvalidValue));
    }

    public function test_fractional_pack_size_and_gap_are_floored_and_reported_but_still_applied(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([['111', 'بنادول', 'شركة أ', null, 10.7, 2.4]], $diagnostics);

        $this->assertSame([10, 2], [$rows[0]->packSize, $rows[0]->minReorderGap]);
        $this->assertSame(2, $diagnostics->count(ImportWarning::FractionalQuantity));
    }

    public function test_price_keeps_two_decimal_places(): void
    {
        $rows = $this->parse([['111', null, null, 12.999, null, null]], new ImportDiagnostics());

        $this->assertSame(13.0, $rows[0]->price);
    }

    public function test_numbers_written_with_arabic_digits_and_decimal_comma_are_understood(): void
    {
        $rows = $this->parse([['111', null, null, '١٢٫٥', null, null]], new ImportDiagnostics());

        $this->assertSame(12.5, $rows[0]->price);
    }

    public function test_fully_blank_data_rows_are_skipped_silently_and_row_numbers_stay_honest(): void
    {
        $diagnostics = new ImportDiagnostics();

        $rows = $this->parse([
            ['111', 'أ', 'ش', 1, 1, 1],   // الصف 2
            null,                           // الصف 3 فارغ كلياً
            ['222', 'ب', 'ش', 2, 2, 2],   // الصف 4
        ], $diagnostics);

        $this->assertSame([2, 4], array_map(fn (MaterialMasterRow $r) => $r->sourceRow, $rows));
        $this->assertFalse($diagnostics->hasWarnings());
    }

    public function test_headers_out_of_order_are_rejected_immediately(): void
    {
        $parser = new MaterialMasterRowParser(new ArrayTabularFileReader([
            ['اسم المادة', 'رمز المادة', 'الشركة', 'السعر', 'حجم العبوة', 'الحد الأدنى للفرق'],
        ]));

        $this->expectException(InvalidImportFileException::class);

        iterator_to_array($parser->parse('ignored.xlsx', new ImportDiagnostics()));
    }

    public function test_the_material_master_specific_error_message_is_used_not_the_matrix_one(): void
    {
        $parser = new MaterialMasterRowParser(new ArrayTabularFileReader([
            ['رمز المادة', 'اسم غلط', 'الشركة', 'السعر', 'حجم العبوة', 'الحد الأدنى للفرق'],
        ]));

        try {
            iterator_to_array($parser->parse('ignored.xlsx', new ImportDiagnostics()));
            $this->fail('كان يجب رمي InvalidImportFileException');
        } catch (InvalidImportFileException $e) {
            $this->assertStringContainsString('السعر، حجم العبوة، الحد الأدنى للفرق', $e->getMessage());
            $this->assertStringNotContainsString('مستودع الصناعة', $e->getMessage());
        }
    }

    public function test_a_synonym_header_is_accepted(): void
    {
        $header = ['الباركود', 'الاسم', 'company', 'price', 'pack_size', 'min_reorder_gap'];

        $rows = $this->parse([['111', 'أ', 'ش', 1, 1, 1]], new ImportDiagnostics(), $header);

        $this->assertCount(1, $rows);
    }

    public function test_an_empty_file_is_rejected(): void
    {
        $parser = new MaterialMasterRowParser(new ArrayTabularFileReader([]));

        $this->expectException(InvalidImportFileException::class);

        iterator_to_array($parser->parse('ignored.xlsx', new ImportDiagnostics()));
    }
}
