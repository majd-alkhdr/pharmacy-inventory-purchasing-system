<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory\Import;

use App\Services\Inventory\Import\Matrix\WarehouseMatrixRow;
use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;

final class WarehouseMatrixRowTest extends TestCase
{
    public function test_exposes_central_and_pharmacy_quantities(): void
    {
        $row = new WarehouseMatrixRow(5, '6281000', 'بنادول', 'شركة أ', 4, [4 => 0, 5 => 2]);

        $this->assertSame(4, $row->quantityAt(3));
        $this->assertSame(0, $row->quantityAt(4));
        $this->assertSame(2, $row->quantityAt(5));
        $this->assertTrue($row->hasAnyStock());
    }

    public function test_all_zero_row_has_no_stock(): void
    {
        $row = new WarehouseMatrixRow(6, '6281001', 'مادة', 'شركة أ', 0, [4 => 0, 5 => 0]);

        $this->assertFalse($row->hasAnyStock());
    }

    public function test_pharmacy_only_stock_counts(): void
    {
        $row = new WarehouseMatrixRow(7, '6281002', 'مادة', 'شركة أ', 0, [4 => 0, 5 => 3]);

        $this->assertTrue($row->hasAnyStock());
    }

    public function test_negative_central_quantity_is_unrepresentable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WarehouseMatrixRow(8, '6281003', 'مادة', 'شركة أ', -1, []);
    }

    public function test_negative_pharmacy_quantity_is_unrepresentable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WarehouseMatrixRow(9, '6281004', 'مادة', 'شركة أ', 0, [4 => -3]);
    }

    public function test_fractional_pharmacy_quantity_is_unrepresentable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WarehouseMatrixRow(10, '6281005', 'مادة', 'شركة أ', 0, [4 => 1.5]);
    }

    public function test_empty_barcode_is_unrepresentable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WarehouseMatrixRow(11, '', 'مادة', 'شركة أ', 0, []);
    }

    public function test_unknown_column_fails_loudly_instead_of_returning_zero(): void
    {
        $row = new WarehouseMatrixRow(12, '6281006', 'مادة', 'شركة أ', 0, [4 => 1]);

        $this->expectException(OutOfBoundsException::class);

        $row->quantityAt(9);
    }
}
