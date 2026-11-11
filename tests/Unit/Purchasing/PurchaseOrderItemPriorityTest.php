<?php

declare(strict_types=1);

namespace Tests\Unit\Purchasing;

use App\Models\PurchaseOrderItem;
use PHPUnit\Framework\TestCase;

/** 🎯 مصدر الأولوية الواحد: نفس أسبقية سلسلة شارة الأولوية بشاشة فاتورة الشراء. */
final class PurchaseOrderItemPriorityTest extends TestCase
{
    private function item(array $attributes = [], array $factors = []): PurchaseOrderItem
    {
        return new PurchaseOrderItem($attributes + ['explanation_factors' => $factors]);
    }

    public function test_the_six_priorities_and_their_ranks(): void
    {
        $cases = [
            ['market_shortage', 1, $this->item(['is_market_shortage' => true])],
            ['pharmacy_shortage', 2, $this->item([], ['is_pharmacy_shortage' => true])],
            ['target', 3, $this->item(['is_target_item' => true])],
            ['high_turnover', 4, $this->item([], ['is_high_turnover' => true])],
            ['high_priority', 5, $this->item(['priority_rank' => 3])],
            ['normal', 6, $this->item(['priority_rank' => 4])],
        ];

        foreach ($cases as [$key, $rank, $item]) {
            $this->assertSame($key, $item->priorityKey());
            $this->assertSame($rank, $item->priorityRank());
        }
        $this->assertSame('normal', $this->item()->priorityKey()); // بلا priority_rank
    }

    public function test_the_first_matching_condition_wins_when_several_hold(): void
    {
        $all = $this->item(['is_market_shortage' => true, 'is_target_item' => true, 'priority_rank' => 1], ['is_pharmacy_shortage' => true, 'is_high_turnover' => true]);
        $this->assertSame('market_shortage', $all->priorityKey());

        $noMarket = $this->item(['is_target_item' => true, 'priority_rank' => 1], ['is_pharmacy_shortage' => true, 'is_high_turnover' => true]);
        $this->assertSame('pharmacy_shortage', $noMarket->priorityKey());

        $targetAndTurnover = $this->item(['is_target_item' => true, 'priority_rank' => 2], ['is_high_turnover' => true]);
        $this->assertSame('target', $targetAndTurnover->priorityKey());
        $this->assertSame(3, $targetAndTurnover->priorityRank());

        $turnoverAndRank = $this->item(['priority_rank' => 1], ['is_high_turnover' => true]);
        $this->assertSame('high_turnover', $turnoverAndRank->priorityKey());
    }
}
