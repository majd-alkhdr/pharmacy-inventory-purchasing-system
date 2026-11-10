<?php

declare(strict_types=1);

namespace Tests\Unit\Purchasing;

use App\Enums\BranchStockState;
use App\Services\Inventory\Surplus\SurplusEntry;
use App\Services\Purchasing\BranchStockLookup;
use App\Services\Purchasing\BranchStockRow;
use PHPUnit\Framework\TestCase;

/** 🎯 (ج.3) تصنيف حال الفرع وترتيبه — دوال نقية بلا قاعدة بيانات. */
final class BranchStockLookupTest extends TestCase
{
    private function entry(int $physical, int $committed = 0): SurplusEntry
    {
        return new SurplusEntry(1, 1, 20, 20 + $physical, $physical, $committed);
    }

    public function test_the_six_states(): void
    {
        $this->assertSame(BranchStockState::Surplus, BranchStockLookup::classify(50, 20, $this->entry(30)));
        $this->assertSame(BranchStockState::SurplusReserved, BranchStockLookup::classify(50, 20, $this->entry(30, 30)));
        $this->assertSame(BranchStockState::Ok, BranchStockLookup::classify(20, 20, null));
        $this->assertSame(BranchStockState::Below, BranchStockLookup::classify(5, 20, null));
        $this->assertSame(BranchStockState::Unknown, BranchStockLookup::classify(null, 20, null));
        $this->assertSame(BranchStockState::NoStandard, BranchStockLookup::classify(7, null, null));
    }

    public function test_stock_slightly_above_standard_within_tolerance_is_ok_not_surplus(): void
    {
        // المحرك لم يُنتج مدخل فائض (الزيادة ضمن min_reorder_gap) ⇒ ضمن القياسي، لا فائض.
        $this->assertSame(BranchStockState::Ok, BranchStockLookup::classify(23, 20, null));
    }

    public function test_missing_stock_row_is_unknown_not_zero(): void
    {
        // لو افتُرض صفراً لصار "دون القياسي" — قاعدة المحرك: لا صف = لا نعرف.
        $this->assertSame(BranchStockState::Unknown, BranchStockLookup::classify(null, 20, null));
        $this->assertNotSame(BranchStockLookup::classify(0, 20, null), BranchStockLookup::classify(null, 20, null));
    }

    public function test_rows_are_sorted_by_state_then_magnitude_then_name(): void
    {
        $row = fn (string $name, BranchStockState $state, ?int $current, ?int $diff = null, int $available = 0)
            => new BranchStockRow(crc32($name), $name, $current, 20, $diff, $state, $available, 0);

        $sorted = BranchStockLookup::sortRows([
            $row('ي-بلا قياسي', BranchStockState::NoStandard, 7),
            $row('ه-لا رصيد', BranchStockState::Unknown, null),
            $row('د-نقص صغير', BranchStockState::Below, 18, -2),
            $row('ج-نقص كبير', BranchStockState::Below, 5, -15),
            $row('ب-ضمن صغير', BranchStockState::Ok, 20, 0),
            $row('أ-ضمن كبير', BranchStockState::Ok, 23, 3),
            $row('محجوز', BranchStockState::SurplusReserved, 50, 30),
            $row('فائض صغير', BranchStockState::Surplus, 35, 15, 15),
            $row('فائض كبير', BranchStockState::Surplus, 50, 30, 30),
            $row('ب-فائض مساوٍ', BranchStockState::Surplus, 35, 15, 15),
        ]);

        $this->assertSame(
            ['فائض كبير', 'ب-فائض مساوٍ', 'فائض صغير', 'محجوز', 'أ-ضمن كبير', 'ب-ضمن صغير', 'ج-نقص كبير', 'د-نقص صغير', 'ه-لا رصيد', 'ي-بلا قياسي'],
            array_map(fn (BranchStockRow $r) => $r->name, $sorted),
        );
    }
}
