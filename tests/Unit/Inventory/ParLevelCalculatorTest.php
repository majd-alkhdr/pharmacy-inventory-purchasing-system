<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Services\Inventory\ParLevelCalculator;
use PHPUnit\Framework\TestCase;

final class ParLevelCalculatorTest extends TestCase
{
    private function calc(): ParLevelCalculator
    {
        return new ParLevelCalculator();
    }

    // ── evaluate(): الحالة الأساسية بلا عبوة ولا تسامح ──

    public function test_a_real_gap_above_threshold_is_actionable(): void
    {
        $d = $this->calc()->evaluate(standardQuantity: 50, currentStock: 10, minReorderGap: 0, packSize: 0);

        $this->assertSame(40, $d->rawGap);
        $this->assertSame(40, $d->effectiveGap);
        $this->assertTrue($d->needsRestock());
        $this->assertFalse($d->isAtOrAboveStandard);
        $this->assertFalse($d->gapBelowThreshold);
        $this->assertFalse($d->needsReverseTransfer());
    }

    public function test_stock_exactly_at_standard_needs_nothing(): void
    {
        $d = $this->calc()->evaluate(standardQuantity: 50, currentStock: 50, minReorderGap: 0, packSize: 0);

        $this->assertSame(0, $d->rawGap);
        $this->assertTrue($d->isAtOrAboveStandard);
        $this->assertFalse($d->needsRestock());
        $this->assertFalse($d->needsReverseTransfer());
    }

    public function test_stock_above_standard_beyond_tolerance_needs_a_reverse_transfer(): void
    {
        $d = $this->calc()->evaluate(standardQuantity: 50, currentStock: 70, minReorderGap: 5, packSize: 0);

        $this->assertTrue($d->isAtOrAboveStandard);
        $this->assertSame(20, $d->physicalExcess);
        $this->assertSame(20, $d->effectiveExcess);
        $this->assertTrue($d->needsReverseTransfer());
        $this->assertFalse($d->needsRestock());
    }

    public function test_stock_above_standard_within_tolerance_needs_no_reverse_transfer(): void
    {
        $d = $this->calc()->evaluate(standardQuantity: 50, currentStock: 53, minReorderGap: 5, packSize: 0);

        $this->assertSame(3, $d->physicalExcess);
        $this->assertFalse($d->needsReverseTransfer());
    }

    public function test_excess_exactly_at_the_tolerance_boundary_needs_no_reverse_transfer(): void
    {
        // physicalExcess > minReorderGap (صارم) لا >= — الحدّ نفسه لا يُحسَب تجاوزاً
        $d = $this->calc()->evaluate(standardQuantity: 50, currentStock: 55, minReorderGap: 5, packSize: 0);

        $this->assertSame(5, $d->physicalExcess);
        $this->assertFalse($d->needsReverseTransfer());
    }

    // ── min_reorder_gap: تسامح متماثل حول الهدف ──

    public function test_a_gap_within_the_tolerance_is_ignored_entirely(): void
    {
        $d = $this->calc()->evaluate(standardQuantity: 50, currentStock: 47, minReorderGap: 5, packSize: 0);

        $this->assertSame(3, $d->rawGap);
        $this->assertTrue($d->gapBelowThreshold);
        $this->assertFalse($d->needsRestock());
        $this->assertFalse($d->isAtOrAboveStandard);   // متجاهَلة، لا "عند الهدف" — لا تُغلق نقصاً معلَّقاً
    }

    public function test_a_gap_exactly_at_the_tolerance_boundary_is_still_ignored(): void
    {
        // rawGap <= minReorderGap (يشمل التساوي) — بعكس الفائض تماماً (صارم بلا تساوٍ)
        $d = $this->calc()->evaluate(standardQuantity: 50, currentStock: 45, minReorderGap: 5, packSize: 0);

        $this->assertSame(5, $d->rawGap);
        $this->assertTrue($d->gapBelowThreshold);
    }

    public function test_a_gap_one_unit_past_the_tolerance_boundary_is_actionable(): void
    {
        $d = $this->calc()->evaluate(standardQuantity: 50, currentStock: 44, minReorderGap: 5, packSize: 0);

        $this->assertSame(6, $d->rawGap);
        $this->assertFalse($d->gapBelowThreshold);
        $this->assertTrue($d->needsRestock());
    }

    // ── pack_size: تقريب لأسفل لأقرب عبوة كاملة ──

    public function test_gap_rounds_down_to_a_whole_pack(): void
    {
        $d = $this->calc()->evaluate(standardQuantity: 50, currentStock: 0, minReorderGap: 0, packSize: 30);

        $this->assertSame(50, $d->rawGap);
        $this->assertSame(30, $d->effectiveGap);   // 50 / 30 = 1 عبوة كاملة فقط
    }

    public function test_a_gap_smaller_than_one_pack_needs_no_restock_at_all(): void
    {
        $d = $this->calc()->evaluate(standardQuantity: 50, currentStock: 30, minReorderGap: 0, packSize: 100);

        $this->assertSame(20, $d->rawGap);
        $this->assertSame(0, $d->effectiveGap);
        $this->assertFalse($d->needsRestock());
        $this->assertFalse($d->isAtOrAboveStandard);   // فجوة حقيقية موجودة، فقط دون عبوة كاملة — ليست "عند الهدف"
        $this->assertFalse($d->gapBelowThreshold);      // ولا "دون حد التسامح" أيضاً — سبب مختلف تماماً (راجع effectiveGap)
    }

    public function test_pack_size_zero_means_no_rounding_constraint(): void
    {
        $d = $this->calc()->evaluate(standardQuantity: 50, currentStock: 17, minReorderGap: 0, packSize: 0);

        $this->assertSame(33, $d->rawGap);
        $this->assertSame(33, $d->effectiveGap);
    }

    public function test_excess_also_rounds_down_to_a_whole_pack(): void
    {
        $d = $this->calc()->evaluate(standardQuantity: 50, currentStock: 95, minReorderGap: 0, packSize: 20);

        $this->assertSame(45, $d->physicalExcess);
        $this->assertSame(40, $d->effectiveExcess);   // 45 / 20 = 2 عبوتان فقط
    }

    // ── alreadyShipped: تصالح "الأكبر لا الجمع" ──

    public function test_already_shipped_amount_is_subtracted_from_the_gap(): void
    {
        $d = $this->calc()->evaluate(standardQuantity: 50, currentStock: 10, minReorderGap: 0, packSize: 0, alreadyShipped: 25);

        $this->assertSame(15, $d->rawGap);   // 40 - 25
        $this->assertSame(15, $d->effectiveGap);
    }

    public function test_already_shipped_amount_never_pushes_the_gap_negative(): void
    {
        $d = $this->calc()->evaluate(standardQuantity: 50, currentStock: 10, minReorderGap: 0, packSize: 0, alreadyShipped: 100);

        $this->assertSame(0, $d->rawGap);
        $this->assertTrue($d->isAtOrAboveStandard);
    }

    public function test_already_shipped_never_affects_the_excess_side_only_the_gap_side(): void
    {
        // القياسي والحالي هنا يعنيان فائضاً (لا فجوة)، وalreadyShipped غير صفري —
        // physicalExcess يجب أن يبقى محسوباً من الأرقام الخام فقط (راجع تحذير الكلاس الحرج).
        $d = $this->calc()->evaluate(standardQuantity: 50, currentStock: 70, minReorderGap: 5, packSize: 0, alreadyShipped: 999);

        $this->assertSame(20, $d->physicalExcess);
        $this->assertSame(20, $d->effectiveExcess);
        $this->assertTrue($d->isAtOrAboveStandard);
    }

    // ── allocate(): توفّر المخزون المركزي ──

    public function test_full_central_stock_covers_the_gap_completely(): void
    {
        $a = $this->calc()->allocate(effectiveGap: 40, availableCentral: 100, packSize: 0);

        $this->assertSame(40, $a->suppliedNow);
        $this->assertSame(0, $a->remainingGap);
        $this->assertTrue($a->isFullyCovered());
    }

    public function test_partial_central_stock_covers_part_of_the_gap(): void
    {
        $a = $this->calc()->allocate(effectiveGap: 40, availableCentral: 15, packSize: 0);

        $this->assertSame(15, $a->suppliedNow);
        $this->assertSame(25, $a->remainingGap);
        $this->assertFalse($a->isFullyCovered());
        $this->assertTrue($a->suppliedAnything());
    }

    public function test_zero_central_stock_supplies_nothing(): void
    {
        $a = $this->calc()->allocate(effectiveGap: 40, availableCentral: 0, packSize: 0);

        $this->assertSame(0, $a->suppliedNow);
        $this->assertSame(40, $a->remainingGap);
        $this->assertFalse($a->suppliedAnything());
    }

    public function test_negative_available_central_is_treated_as_zero(): void
    {
        // يحدث فعلياً: منتجات متعددة بنفس الدفعة تستهلك من نفس رصيد المنتج المركزي بالتتابع.
        $a = $this->calc()->allocate(effectiveGap: 40, availableCentral: -10, packSize: 0);

        $this->assertSame(0, $a->suppliedNow);
        $this->assertSame(40, $a->remainingGap);
    }

    public function test_allocation_also_rounds_down_to_a_whole_pack(): void
    {
        // الرصيد يكفي 65 وحدة، لكن العبوة 30 — يُشحَن عبوتان فقط (60)، لا يُفتَح ثالثة جزئياً
        $a = $this->calc()->allocate(effectiveGap: 90, availableCentral: 65, packSize: 30);

        $this->assertSame(60, $a->suppliedNow);
        $this->assertSame(30, $a->remainingGap);
    }

    public function test_allocation_never_exceeds_the_gap_even_with_abundant_central_stock(): void
    {
        $a = $this->calc()->allocate(effectiveGap: 40, availableCentral: 1_000_000, packSize: 0);

        $this->assertSame(40, $a->suppliedNow);
        $this->assertSame(0, $a->remainingGap);
    }
}
