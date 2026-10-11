<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Services\Shared\Rounding\QuantityRounder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 🎯 (أ.5) قاعدة "دوّر مرة واحدة عند الحافة الأخيرة". */
final class QuantityRounderTest extends TestCase
{
    private QuantityRounder $rounder;

    protected function setUp(): void
    {
        $this->rounder = new QuantityRounder();
    }

    public function test_normalize_removes_floating_point_noise_but_keeps_the_fraction(): void
    {
        $this->assertSame(0.3, $this->rounder->normalize(0.1 + 0.2));
        $this->assertSame(0.3333, $this->rounder->normalize(1 / 3));
    }

    /** @return array<string, array{0: int|float|string|null, 1: int}> */
    public static function wholeUnits(): array
    {
        return [
            'half rounds up'                 => [2.5, 3],
            'below half rounds down'         => [2.4, 2],
            'drift just below half'          => [2.4999999999, 3], // 4 خانات أولاً ⇒ 2.5 ⇒ 3
            'numeric string from a form'     => ['12.6', 13],
            'negative clamps to zero'        => [-4, 0],
            'empty input is zero'            => ['', 0],
            'null is zero'                   => [null, 0],
            'integer passes through'         => [7, 7],
        ];
    }

    #[DataProvider('wholeUnits')]
    public function test_to_whole_units(int|float|string|null $input, int $expected): void
    {
        $this->assertSame($expected, $this->rounder->toWholeUnits($input));
    }

    public function test_rounding_once_beats_rounding_every_step(): void
    {
        // خطوتان كل منهما 2.4: التدوير بكل خطوة يعطي 4، التدوير مرة واحدة على 4.8 يعطي 5.
        $stepwise = $this->rounder->toWholeUnits(2.4) + $this->rounder->toWholeUnits(2.4);
        $once = $this->rounder->toWholeUnits($this->rounder->normalize(2.4 + 2.4));

        $this->assertSame(4, $stepwise);
        $this->assertSame(5, $once);
    }

    public function test_split_whole_matches_the_sales_remainder_algorithm(): void
    {
        $this->assertSame(['whole' => 1, 'remainder' => 0.5], $this->rounder->splitWhole(1.5));
        $this->assertSame(['whole' => 0, 'remainder' => 0.3333], $this->rounder->splitWhole(1 / 3));
        $this->assertSame(['whole' => 2, 'remainder' => 0.0], $this->rounder->splitWhole(0.6667 + 1.3333));
    }
}
