<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Enums\ShortageSource;
use App\Services\Inventory\RestockingResult;
use App\Services\Inventory\Support\BuildsReplenishmentSummary;
use PHPUnit\Framework\TestCase;

/**
 * 🎯 يختبر BuildsReplenishmentSummary مباشرة (Trait) — بلا قاعدة بيانات، بلا
 * محرك ترميم كامل. يثبت أن "صفر فواتير" الآن يُفسَّر بدقة حين يكون السبب
 * أحد التشخيصين الجديدين (skippedNoBaseline/skippedNoStandard)، ويبقى
 * بالرسالة العامة فقط حين يكون السبب فعلياً غياب أي بيانات إطلاقاً.
 */
final class BuildsReplenishmentSummaryTest extends TestCase
{
    use BuildsReplenishmentSummary;

    public function test_zero_invoices_with_skipped_no_baseline_explains_the_reason(): void
    {
        $result = new RestockingResult(source: ShortageSource::StandardInventory, skippedNoBaseline: 3);

        $msg = $this->buildReplenishmentSummaryMessage($result, []);

        $this->assertStringContainsString('3 زوجاً بلا جرد صيدلية مسجَّل مسبقاً', $msg);
        $this->assertStringNotContainsString('لا توجد بيانات كافية لهذه الدفعة', $msg);
    }

    public function test_zero_invoices_with_skipped_no_standard_explains_the_reason(): void
    {
        $result = new RestockingResult(source: ShortageSource::StandardInventory, skippedNoStandard: 7);

        $msg = $this->buildReplenishmentSummaryMessage($result, []);

        $this->assertStringContainsString('7 زوجاً بلا مخزون قياسي محدَّد', $msg);
        $this->assertStringNotContainsString('لا توجد بيانات كافية لهذه الدفعة', $msg);
    }

    public function test_both_diagnostics_together_are_both_mentioned(): void
    {
        $result = new RestockingResult(source: ShortageSource::StandardInventory, skippedNoBaseline: 2, skippedNoStandard: 5);

        $msg = $this->buildReplenishmentSummaryMessage($result, []);

        $this->assertStringContainsString('2 زوجاً بلا جرد صيدلية', $msg);
        $this->assertStringContainsString('5 زوجاً بلا مخزون قياسي', $msg);
    }

    public function test_zero_invoices_with_no_diagnostics_at_all_keeps_the_original_generic_message(): void
    {
        $result = new RestockingResult(source: ShortageSource::StandardInventory);

        $msg = $this->buildReplenishmentSummaryMessage($result, []);

        $this->assertStringContainsString('لا توجد بيانات كافية لهذه الدفعة', $msg);
    }

    public function test_when_pairs_were_evaluated_the_normal_summary_is_used_regardless_of_diagnostics(): void
    {
        // pairsEvaluated > 0 يعني تجاوزنا فرع "صفر بيانات" بالكامل — التشخيصان الجديدان لا يُقحَمان هنا.
        $result = new RestockingResult(source: ShortageSource::StandardInventory, pairsEvaluated: 5, invoicesCreated: 0, skippedNoBaseline: 3);

        $msg = $this->buildReplenishmentSummaryMessage($result, []);

        $this->assertStringContainsString('من أصل 5 تركيبة', $msg);
        $this->assertStringNotContainsString('بلا جرد صيدلية مسجَّل مسبقاً', $msg);
    }
}
