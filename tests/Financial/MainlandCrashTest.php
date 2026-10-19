<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * The mainland's crashes (AssetMarketSubsystem::stepMainlandQuarter()): a Merton (1976) jump down in the gap's
 * innovation, compensated. The innovation keeps the fitted sd and no mean, and gains the left tail a Gaussian lacks:
 * 2008Q4 was a five-sd quarter of the rest of the residuals.
 */
class MainlandCrashTest extends TestCase
{
    private const SEEDS = [11, 47, 83];

    private const QUARTERS_PER_SEED = 40_000;

    public function testTheInnovationKeepsItsScaleAndGainsAShiftlessLeftTail(): void
    {
        $a1 = AssetMarketSubsystem::MAINLAND_GAP_AR1;
        $a2 = AssetMarketSubsystem::MAINLAND_GAP_AR2;
        $sum = 0.0;
        $sumSq = 0.0;
        $sumCube = 0.0;
        $belowFour = 0;
        $n = 0;
        foreach (self::SEEDS as $seed) {
            mt_srand($seed);
            $subsystem = new AssetMarketSubsystem(new MathUtility());
            $state = new MacroState();
            $state->outputGapEma = 0.0; // the district at trend sets the mainland's mean at zero
            for ($q = 0; $q < self::QUARTERS_PER_SEED; ++$q) {
                $state->totalTime += 0.25;
                $last = $state->foreignOutputGap;
                $before = $state->foreignOutputGapLag;
                $subsystem->calculateForeignEconomy($state, 0.25);
                $surprise = ($state->foreignOutputGap - ($a1 * $last) - ($a2 * $before)) / AssetMarketSubsystem::mainlandGapInnovationSd();
                $sum += $surprise;
                $sumSq += $surprise * $surprise;
                $sumCube += $surprise ** 3;
                $belowFour += $surprise < -4.0 ? 1 : 0;
                ++$n;
            }
        }

        $mean = $sum / $n;
        $variance = ($sumSq / $n) - ($mean * $mean);
        $skew = (($sumCube / $n) - (3.0 * $mean * $variance) - ($mean ** 3)) / ($variance ** 1.5);

        $this->assertEqualsWithDelta(0.0, $mean, 4.0 / sqrt($n), 'Compensated, the crashes leave the innovation without a mean.');
        $this->assertEqualsWithDelta(1.0, sqrt($variance), 0.02, 'Its sd is the fitted regression\'s, 0.490pp.');
        $this->assertLessThan(-0.4, $skew, 'Crashes skew it down (about -0.85).');
        // A crash quarter falls past four sd two times in three; a Gaussian does so once in 30,000 quarters.
        $this->assertGreaterThan(0.002 * $n, $belowFour, 'Four-sd falls come about once in 250 quarters,');
        $this->assertLessThan(0.007 * $n, $belowFour, 'and no more often than crashes strike.');
    }
}
