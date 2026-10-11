<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Import;

use App\Enums\ImportWarning;
use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use App\Services\Inventory\Import\Matrix\WarehouseMatrixRowParser;
use App\Services\Inventory\Import\Readers\OpenSpoutTabularFileReader;
use App\Services\Inventory\Import\Support\ImportDiagnostics;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use PHPUnit\Framework\TestCase;

/**
 * اختبار تكاملي حقيقي: ملفات xlsx/csv تُكتب فعلياً على القرص ثم تُقرأ — بلا أي
 * محاكاة للقارئ. يثبّت خصوصاً ما يهمنا: أرقام الصفوف الصادقة، الفراغ null،
 * ترميم CSV العربي، والرفض الواضح للصيغ غير المدعومة.
 */
final class OpenSpoutTabularFileReaderTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    private function tempPath(string $extension): string
    {
        return $this->tempFiles[] = sys_get_temp_dir() . '/import-test-' . bin2hex(random_bytes(6)) . '.' . $extension;
    }

    /** @param list<array<int, string|int|float|null>> $rows */
    private function writeXlsx(array $rows): string
    {
        $path = $this->tempPath('xlsx');
        $writer = new XlsxWriter();
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return $path;
    }

    /** @return array<int, array<int, mixed>> */
    private function readAll(string $path): array
    {
        return iterator_to_array((new OpenSpoutTabularFileReader())->read($path));
    }

    public function test_xlsx_rows_keep_their_real_numbers_and_blanks_are_null(): void
    {
        $path = $this->writeXlsx([
            ['رمز', 'كمية'],
            ['x', null],
            [null, null],
            ['y', 5],
        ]);

        $rows = $this->readAll($path);

        $this->assertSame([1, 2, 3, 4], array_keys($rows));
        $this->assertSame('x', $rows[2][0]);
        $this->assertSame(null, $rows[2][1] ?? null);
        $this->assertSame([], array_filter($rows[3], fn ($c) => $c !== null)); // الصف 3 فارغ لكنه موجود برقمه
        $this->assertEquals(5, $rows[4][1]);
    }

    public function test_csv_with_bom_and_semicolon_delimiter(): void
    {
        $path = $this->tempPath('csv');
        file_put_contents($path, "\xEF\xBB\xBF" . "رمز المادة;الاسم\n123;بنادول\n");

        $rows = $this->readAll($path);

        $this->assertSame(['رمز المادة', 'الاسم'], $rows[1]);
        $this->assertSame(['123', 'بنادول'], $rows[2]);
    }

    public function test_legacy_windows_1256_csv_is_decoded(): void
    {
        $path = $this->tempPath('csv');
        // mbstring لا يعرف Windows-1256؛ iconv (الذي يستخدمه OpenSpout أيضاً) يعرفه.
        file_put_contents($path, iconv('UTF-8', 'WINDOWS-1256', "رمز المادة,الاسم\n123,بنادول أطفال\n"));

        $rows = $this->readAll($path);

        $this->assertSame(['رمز المادة', 'الاسم'], $rows[1]);
        $this->assertSame(['123', 'بنادول أطفال'], $rows[2]);
    }

    public function test_csv_uploaded_by_laravel_with_a_txt_extension_is_accepted(): void
    {
        $path = $this->tempPath('txt');
        file_put_contents($path, "a,b\n1,2\n");

        $this->assertSame(['1', '2'], $this->readAll($path)[2]);
    }

    public function test_only_the_first_visible_sheet_is_read(): void
    {
        $path = $this->tempPath('xlsx');
        $writer = new XlsxWriter();
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setIsVisible(false);
        $writer->addRow(Row::fromValues(['مخفية']));
        $writer->addNewSheetAndMakeItCurrent();
        $writer->addRow(Row::fromValues(['ظاهرة']));
        $writer->close();

        $this->assertSame('ظاهرة', $this->readAll($path)[1][0]);
    }

    public function test_xls_is_rejected_with_actionable_message(): void
    {
        $path = $this->tempPath('xls');
        file_put_contents($path, 'x');

        $this->expectException(InvalidImportFileException::class);
        $this->expectExceptionMessage('xlsx');

        (new OpenSpoutTabularFileReader())->read($path);
    }

    public function test_unsupported_extension_is_rejected(): void
    {
        $path = $this->tempPath('pdf');
        file_put_contents($path, 'x');

        $this->expectException(InvalidImportFileException::class);
        $this->expectExceptionMessage('غير مدعومة');

        (new OpenSpoutTabularFileReader())->read($path);
    }

    public function test_missing_file_fails_at_the_call_site(): void
    {
        $this->expectException(InvalidImportFileException::class);
        $this->expectExceptionMessage('غير موجود');

        (new OpenSpoutTabularFileReader())->read('/nonexistent/file.xlsx');
    }

    public function test_corrupt_xlsx_is_a_user_facing_error_not_a_crash(): void
    {
        $path = $this->tempPath('xlsx');
        file_put_contents($path, 'this is not a zip archive');

        $this->expectException(InvalidImportFileException::class);

        (new OpenSpoutTabularFileReader())->read($path);
    }

    public function test_full_pipeline_on_a_real_xlsx(): void
    {
        $path = $this->writeXlsx([
            ['رمز المادة', 'الاسم', 'الشركة', 'مستودع الصناعة', 'صيدلية أ', 'صيدلية ب'],
            [6281000123456, 'بنادول', 'شركة أ', 10, null, -2],
            [null, null, null, null, null, null],
            ['6281000123457', 'أموكسيل', 'شركة ب', 2.67, 3, ''],
        ]);

        $diagnostics = new ImportDiagnostics();
        $parsed = (new WarehouseMatrixRowParser(new OpenSpoutTabularFileReader()))->parse($path, $diagnostics);
        $rows = iterator_to_array($parsed->rows(), false);

        $this->assertSame(2, $parsed->columns->pharmacyCount());
        $this->assertCount(2, $rows);
        $this->assertSame('6281000123456', $rows[0]->barcode);
        $this->assertSame([4 => 0, 5 => 0], $rows[0]->pharmacyQuantities);
        $this->assertSame(2, $rows[1]->centralQuantity);
        $this->assertSame([4 => 3, 5 => 0], $rows[1]->pharmacyQuantities);
        $this->assertSame(1, $diagnostics->count(ImportWarning::NegativeQuantity));
        $this->assertSame(1, $diagnostics->count(ImportWarning::FractionalQuantity));
    }

    /**
     * 🩹 تراجع (regression): OpenSpout لا يحرّر مقبض الملف إن توقفت القراءة مبكراً (قراءة الرؤوس فقط)
     * — على ويندوز كان Storage::delete() يفشل بصمت فيبقى ملف المصفوفة المرفوع بعد الاستئناف.
     */
    public function test_file_is_released_when_reading_stops_after_the_header_row(): void
    {
        $path = $this->writeXlsx([['رمز المادة', 'الاسم'], ['111', 'بنادول'], ['222', 'سيتامول']]);

        $rows = (new OpenSpoutTabularFileReader())->read($path);
        $this->assertSame(['رمز المادة', 'الاسم'], $rows->current());
        unset($rows); // المستهلك يتوقف بعد الرؤوس

        $this->assertTrue(@unlink($path), 'الملف ما زال محجوزاً بعد التوقف المبكر عن القراءة.');
    }

    public function test_file_is_released_after_a_full_read(): void
    {
        $path = $this->writeXlsx([['رمز المادة'], ['111']]);

        $this->assertCount(2, $this->readAll($path));
        $this->assertTrue(@unlink($path));
    }
}
