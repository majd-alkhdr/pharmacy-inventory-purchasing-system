<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Import;

use App\Services\Inventory\Import\Exceptions\InvalidImportFileException;
use App\Services\Inventory\Import\Matrix\MatrixColumnMap;
use PHPUnit\Framework\TestCase;

final class MatrixColumnMapTest extends TestCase
{
    private const FIXED = ['رمز المادة', 'الاسم', 'الشركة', 'مستودع الصناعة'];

    /** صف الرؤوس كما كتبته حرفياً في رسالتك (10 صيدليات). */
    private function agreedHeaderRow(): array
    {
        return [
            ...self::FIXED,
            'صيدلية سامر السعدي-ميدان',
            'صيدلية راما رسلان-حرستا',
            'صيدلية احلام زكريا-حرستا',
            'صيدلية ياسين الحجة-ج عرطوز',
            'صيدلية ليليان الفارس-شبعا',
            'صيدلية سهاد - ضاحية قدسيا',
            'صيدلية هناء بطحيش-كفرسوسة',
            'صيدلية ماهر سليمان- جرمانا',
            'صيدلية رانيا شاهين - جرمانا',
            'صيدلية هناء يونس - المزة',
        ];
    }

    public function test_parses_the_agreed_header_row(): void
    {
        $map = MatrixColumnMap::fromHeaderRow($this->agreedHeaderRow());

        $this->assertSame(10, $map->pharmacyCount());
        $this->assertSame(4, array_key_first($map->pharmacyColumns()));
        $this->assertSame(13, array_key_last($map->pharmacyColumns()));
        $this->assertSame(13, $map->lastColumnIndex());
        $this->assertSame('صيدلية هناء يونس - المزة', $map->pharmacyColumns()[13]);
        $this->assertSame([], $map->ignoredColumns());
    }

    public function test_accepts_synonyms_and_invisible_marks(): void
    {
        $map = MatrixColumnMap::fromHeaderRow(["\u{200F}رمز المادة", 'اسم المادة', 'اسم الشركة', 'المخزون المركزي', 'صيدلية أ']);

        $this->assertSame(1, $map->pharmacyCount());
    }

    public function test_pharmacy_count_is_not_fixed(): void
    {
        $twelve = [...self::FIXED, ...array_map(fn (int $i) => "صيدلية {$i}", range(1, 12))];

        $this->assertSame(12, MatrixColumnMap::fromHeaderRow($twelve)->pharmacyCount());
    }

    public function test_pharmacyless_file_is_structurally_valid(): void
    {
        $map = MatrixColumnMap::fromHeaderRow(self::FIXED);

        $this->assertSame(0, $map->pharmacyCount());
        $this->assertSame(MatrixColumnMap::CENTRAL, $map->lastColumnIndex());
    }

    public function test_trailing_blank_headers_are_ignored(): void
    {
        $map = MatrixColumnMap::fromHeaderRow([...self::FIXED, 'صيدلية أ', null, '', null]);

        $this->assertSame(1, $map->pharmacyCount());
        $this->assertSame(4, $map->lastColumnIndex());
    }

    public function test_summary_column_is_ignored_not_read_as_a_pharmacy(): void
    {
        $map = MatrixColumnMap::fromHeaderRow([...self::FIXED, 'صيدلية أ', 'المجموع']);

        $this->assertSame(1, $map->pharmacyCount());
        $this->assertSame([5 => 'المجموع'], $map->ignoredColumns());
        $this->assertSame(5, $map->lastColumnIndex());
    }

    public function test_rejects_shifted_columns(): void
    {
        $this->expectException(InvalidImportFileException::class);
        $this->expectExceptionMessage('العمود A');

        MatrixColumnMap::fromHeaderRow(['الاسم', 'رمز المادة', 'الشركة', 'مستودع الصناعة']);
    }

    public function test_rejects_missing_central_column_and_names_it(): void
    {
        $this->expectException(InvalidImportFileException::class);
        $this->expectExceptionMessage('العمود D يجب أن يكون «مستودع الصناعة»');

        MatrixColumnMap::fromHeaderRow(['رمز المادة', 'الاسم', 'الشركة', 'صيدلية أ']);
    }

    public function test_rejects_pharmacy_written_twice_with_different_separators(): void
    {
        $this->expectException(InvalidImportFileException::class);
        $this->expectExceptionMessage('بالعمودين E وF');

        MatrixColumnMap::fromHeaderRow([...self::FIXED, 'صيدلية ماهر سليمان / جرمانا', 'صيدلية ماهر سليمان- جرمانا']);
    }

    public function test_rejects_blank_header_between_pharmacies(): void
    {
        $this->expectException(InvalidImportFileException::class);
        $this->expectExceptionMessage('العمود F بلا رأس');

        MatrixColumnMap::fromHeaderRow([...self::FIXED, 'صيدلية أ', '', 'صيدلية ب']);
    }

    public function test_rejects_blank_header_row(): void
    {
        $this->expectException(InvalidImportFileException::class);

        MatrixColumnMap::fromHeaderRow([null, '', null]);
    }

    public function test_pharmacy_keys_unify_erp_and_matrix_spellings(): void
    {
        $erp    = MatrixColumnMap::fromHeaderRow([...self::FIXED, 'صيدلية ماهر سليمان / جرمانا']);
        $matrix = MatrixColumnMap::fromHeaderRow([...self::FIXED, 'صيدلية ماهر سليمان- جرمانا']);

        $this->assertSame($erp->pharmacyKeys(), $matrix->pharmacyKeys());
    }

    public function test_column_letters_match_excel(): void
    {
        $this->assertSame('A', MatrixColumnMap::columnLetter(0));
        $this->assertSame('D', MatrixColumnMap::columnLetter(3));
        $this->assertSame('Z', MatrixColumnMap::columnLetter(25));
        $this->assertSame('AA', MatrixColumnMap::columnLetter(26));
        $this->assertSame('AB', MatrixColumnMap::columnLetter(27));
        $this->assertSame('ZZ', MatrixColumnMap::columnLetter(701));
        $this->assertSame('AAA', MatrixColumnMap::columnLetter(702));
    }

    public function test_location_labels(): void
    {
        $map = MatrixColumnMap::fromHeaderRow([...self::FIXED, 'صيدلية أ', 'المجموع']);

        $this->assertSame('المخزون المركزي', $map->locationLabel(3));
        $this->assertSame('صيدلية أ', $map->locationLabel(4));
        $this->assertSame('المجموع', $map->locationLabel(5));
        $this->assertSame('العمود AO', $map->locationLabel(40));
    }
}
