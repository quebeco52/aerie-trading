<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * An insurer books the district losses incurred since its last report against what that window averages
 * (InsuranceBusinessModel). Over the stationary catastrophe process the ratio averages one in every calendar quarter,
 * so the district term adds claims in a heavy quarter and takes them back in a quiet one, and nothing on average.
 */
class CatastropheIncurredFlowTest extends TestCase
{
    private const SEEDS = [3, 41, 77, 109];

    private const YEARS_PER_SEED = 600;

    private const TICKS_PER_YEAR = 252;

    private const TICKS_PER_REPORT = 63;

    public function testTheIncurredFlowAveragesItsExpectationInEveryQuarter(): void
    {
        $dt = 1.0 / self::TICKS_PER_YEAR;
        $sums = array_fill(0, 4, 0.0);
        $squares = array_fill(0, 4, 0.0);
        $counts = array_fill(0, 4, 0);
        foreach (self::SEEDS as $seed) {
            $subsystem = new CommodityLogisticsSubsystem(MathUtility::ownStream($seed));
            $state = new MacroState();
            $lastBurden = $state->catastropheLossIndex;
            $lastTime = 0.0;
            for ($tick = 1; $tick <= self::YEARS_PER_SEED * self::TICKS_PER_YEAR; ++$tick) {
                $subsystem->calculateCatastropheLosses($state, $dt);
                $state->totalTime += $dt;
                if ($tick % self::TICKS_PER_REPORT !== 0) {
                    continue;
                }

                $window = $state->totalTime - $lastTime;
                $ratio = CommodityLogisticsSubsystem::catastropheIncurredSince($state->catastropheLossIndex, $lastBurden, $window)
                    / CommodityLogisticsSubsystem::expectedCatastropheIncurred($state->totalTime, $window);
                if ($tick > 10 * self::TICKS_PER_YEAR) {
                    $quarter = (((int) floor(($state->totalTime - (0.5 * $window)) * 4.0)) % 4 + 4) % 4;
                    $sums[$quarter] += $ratio;
                    $squares[$quarter] += $ratio * $ratio;
                    ++$counts[$quarter];
                }
                $lastBurden = $state->catastropheLossIndex;
                $lastTime = $state->totalTime;
            }
        }

        foreach ([0, 1, 2, 3] as $quarter) {
            $mean = $sums[$quarter] / $counts[$quarter];
            $standardError = sqrt((($squares[$quarter] / $counts[$quarter]) - ($mean * $mean)) / $counts[$quarter]);
            $this->assertEqualsWithDelta(1.0, $mean, 4.0 * $standardError + 0.01, "Quarter {$quarter}: the flow averages its seasonal expectation.");
        }
    }
}
