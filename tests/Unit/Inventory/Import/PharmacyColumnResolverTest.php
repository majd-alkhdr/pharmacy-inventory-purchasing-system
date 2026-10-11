<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Import;

use App\Services\Inventory\Import\Matrix\MatrixColumnMap;
use App\Services\Inventory\Import\Matrix\PharmacyColumnResolver;
use PHPUnit\Framework\TestCase;

final class PharmacyColumnResolverTest extends TestCase
{
    private const FIXED = ['رمز المادة', 'الاسم', 'الشركة', 'مستودع الصناعة'];

    private function columns(string ...$pharmacies): MatrixColumnMap
    {
        return MatrixColumnMap::fromHeaderRow([...self::FIXED, ...$pharmacies]);
    }

    public function test_resolves_by_normalised_name_across_separator_styles(): void
    {
        $resolution = (new PharmacyColumnResolver())->resolve(
            $this->columns('صيدلية ماهر سليمان- جرمانا', 'صيدلية هناء يونس - المزة'),
            [7 => 'صيدلية ماهر سليمان / جرمانا', 9 => 'صيدلية هناء يونس - المزة'],
            [],
        );

        $this->assertTrue($resolution->isComplete());
        $this->assertSame([4 => 7, 5 => 9], $resolution->resolved);
        $this->assertSame([7, 9], $resolution->pharmacyIds());
    }

    public function test_saved_manual_mapping_wins_over_name_matching(): void
    {
        $resolution = (new PharmacyColumnResolver())->resolve(
            $this->columns('صيدلية الأمل الجديدة'),
            [1 => 'صيدلية الأمل', 2 => 'صيدلية الأمل الجديدة'],
            ['صيدلية الأمل الجديدة' => 1],   // قرار يدوي سابق: هذا الاسم يعني الصيدلية 1
        );

        $this->assertSame([4 => 1], $resolution->resolved);
    }

    public function test_unknown_header_stops_for_manual_mapping_instead_of_guessing(): void
    {
        $resolution = (new PharmacyColumnResolver())->resolve(
            $this->columns('صيدلية أ', 'ملاحظات'),
            [1 => 'صيدلية أ'],
            [],
        );

        $this->assertFalse($resolution->isComplete());
        $this->assertTrue($resolution->needsManualMapping());
        $this->assertSame([5 => 'ملاحظات'], $resolution->unresolved);
        $this->assertSame(['ملاحظات'], $resolution->headersNeedingMapping());
        $this->assertSame([4 => 1], $resolution->resolved);   // المعروف يبقى محلولاً
    }

    public function test_two_registered_pharmacies_with_the_same_key_are_ambiguous(): void
    {
        $resolution = (new PharmacyColumnResolver())->resolve(
            $this->columns('صيدلية سامر - ميدان'),
            [1 => 'صيدلية سامر-ميدان', 2 => 'صيدلية سامر / ميدان'],
            [],
        );

        $this->assertFalse($resolution->isComplete());
        $this->assertSame([4 => [1, 2]], $resolution->ambiguous);
        $this->assertSame(['صيدلية سامر - ميدان'], $resolution->headersNeedingMapping());
    }

    public function test_two_columns_resolving_to_one_pharmacy_is_a_conflict_not_a_manual_choice(): void
    {
        $resolution = (new PharmacyColumnResolver())->resolve(
            $this->columns('صيدلية أ', 'اسم بديل لصيدلية أ'),
            [1 => 'صيدلية أ'],
            ['اسم بديل لصيدلية أ' => 1],
        );

        $this->assertFalse($resolution->isComplete());
        $this->assertFalse($resolution->needsManualMapping());
        $this->assertSame([1 => [4, 5]], $resolution->conflicts);
    }

    public function test_a_saved_mapping_pointing_at_a_deleted_pharmacy_is_ignored(): void
    {
        $resolution = (new PharmacyColumnResolver())->resolve(
            $this->columns('صيدلية قديمة'),
            [1 => 'صيدلية أ'],
            ['صيدلية قديمة' => 99],   // الصيدلية 99 لم تعد موجودة
        );

        $this->assertSame([4 => 'صيدلية قديمة'], $resolution->unresolved);
    }

    public function test_pharmacyless_file_is_trivially_complete(): void
    {
        $resolution = (new PharmacyColumnResolver())->resolve($this->columns(), [1 => 'صيدلية أ'], []);

        $this->assertTrue($resolution->isComplete());
        $this->assertSame([], $resolution->pharmacyIds());
    }
}
