<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * The SHAPE of the cycle the engine produces, held against the CBO output gap (real GDP against potential,
 * 1949-2026). Real business cycles are not symmetric: expansions climb slowly and contractions fall away,
 * so the gap is left-skewed (-0.32 over the whole post-war record, -1.20 since 1985) with roughly a tenth of
 * quarters below -3% and troughs reaching -6% and worse.
 *
 * Until 2026-09-20 the engine did not produce that shape: over 960 simulated years the gap's skew was +0.02
 * and 0.4% of quarters fell below -3%, because a SYMMETRIC cubic capacity term was the largest restoring
 * force at both ends of the cycle. The ceiling is now one-sided (Friedman 1993 plucking; Dupraz, Nakamura &
 * Steinsson 2019) and the stabilisers act within the year, and the same harness measures skew -0.20 with
 * 5% of quarters below -3% at production tick rate (8 seeds x 60y).
 *
 * Skew is a noisy statistic: two 4-seed halves of the SAME arm read -0.01 and -0.32. The path is therefore
 * pooled across eight seeds and computed once, and the bounds are deliberately wider than the calibration
 * target -- they exist to catch the return of a symmetric cycle, not to pin any particular constant.
 */
class BusinessCycleRealismTest extends TestCase
{
    private const YEARS = 40;
    private const BURN_IN_YEARS = 5;
    private const TICKS_PER_YEAR = 252;
    private const SEEDS = [20260918, 4711, 90210, 1, 2, 3, 5, 8];

    /** @var array<string, list<float>> Pooled quarterly gap paths, keyed by seed list; the run is the whole cost of this class. */
    private static array $pathCache = [];

    public function testTheOutputGapIsLeftSkewedWithARealLeftTail(): void
    {
        // Before the one-sided ceiling, every candidate that kept the cubic symmetric failed here: a
        // Gilchrist-Zakrajsek excess bond premium drag deepened busts but the capital overhang returned the
        // energy into the next boom (skew +0.02 -> +0.07); zeroing KALDOR_MOMENTUM, faster hikes, faster
        // stabilisers, weaker easing transmission and a steeper (quintic) limiter all shrank or grew BOTH tails
        // together. A symmetric bound plus symmetric-or-pro-boom economics gives a symmetric distribution.
        $gaps = $this->simulateGapPath();
        $count = count($gaps);

        $mean = array_sum($gaps) / $count;
        $variance = 0.0;
        foreach ($gaps as $gap) {
            $variance += ($gap - $mean) ** 2;
        }
        $stdDev = sqrt($variance / $count);

        $skew = 0.0;
        foreach ($gaps as $gap) {
            $skew += (($gap - $mean) / $stdDev) ** 3;
        }
        $skew /= $count;

        $belowThree = count(array_filter($gaps, static fn (float $g): bool => $g < -0.03)) / $count;
        $aboveTwo = count(array_filter($gaps, static fn (float $g): bool => $g > 0.02)) / $count;

        // CBO gap: skew -0.32 since 1949 and -1.20 since 1985. A symmetric oscillator reads about zero; the
        // engine measures -0.20 over 8 seeds x 60y, and the bound leaves room for seed noise at this length.
        $this->assertLessThan(-0.10, $skew, 'The cycle must be left-skewed: contractions fall away faster than expansions build.');

        // 10.0% of post-war quarters and 7.8% since 1985 sit below -3%. The engine managed 0.4% without a
        // credit crunch, and its worst quarter in 960 simulated years was -3.43%.
        $this->assertGreaterThan(0.02, $belowThree, 'The gap must have a genuine left tail, not just a mild slump.');
        $this->assertLessThan(0.16, $belowThree, 'But a recession must stay an episode rather than the usual state of the world.');

        $this->assertLessThan(0.20, $aboveTwo, 'The economy must not spend a fifth of its life running two percent hot.');
        $this->assertEqualsWithDelta(0.0, $mean, 0.008, 'A cyclical gap averages near zero over four decades.');
        $this->assertGreaterThan(0.010, $stdDev, 'The cycle has to actually move.');
        $this->assertLessThan(0.030, $stdDev, 'And stay inside business-cycle amplitude.');
    }

    public function testContractionsDescendFasterThanExpansionsClimb(): void
    {
        // NOT YET MET. With the one-sided ceiling busts reach their trough at 1.7-1.9pp a year against booms at
        // 1.8-2.0 (8 seeds x 60y, production tick rate): the depth is there but the descent is not, because a
        // bust still has no amplifier of its own -- credit is a function of the gap and cannot turn on its own.
        // A credit-cycle crisis hazard (Schularick & Taylor 2012) on the credit-to-GDP gap is the candidate.
        $this->markTestIncomplete('Contractions do not yet descend faster than expansions climb: a bust has no amplifier of its own.');

        // @phpstan-ignore deadCode.unreachable
        $gaps = $this->simulateGapPath();

        $booms = [];
        $busts = [];
        $index = 0;
        $total = count($gaps);
        while ($index < $total) {
            $sign = $gaps[$index] >= 0.0 ? 1 : -1;
            $end = $index;
            while ($end < $total && ($gaps[$end] >= 0.0 ? 1 : -1) === $sign) {
                $end++;
            }
            $segment = array_slice($gaps, $index, $end - $index);
            $peak = $sign > 0 ? max($segment) : min($segment);
            if (count($segment) >= 2 && abs($peak) >= 0.0025) {
                $quartersToPeak = ((int) array_search($peak, $segment, true)) + 1;
                // Amplitude per year over the approach to the extreme.
                $speed = abs($peak) / ($quartersToPeak / 4.0);
                if ($sign > 0) {
                    $booms[] = $speed;
                } else {
                    $busts[] = $speed;
                }
            }
            $index = $end;
        }

        $this->assertNotEmpty($booms, 'The run must contain expansions.');
        $this->assertNotEmpty($busts, 'The run must contain contractions.');

        $boomSpeed = array_sum($booms) / count($booms);
        $bustSpeed = array_sum($busts) / count($busts);

        // CBO: booms climb at 2.19pp a year and busts fall at 4.34 (1.21 against 5.05 since 1985).
        $this->assertGreaterThan(
            $boomSpeed,
            $bustSpeed,
            'Contractions must reach their trough faster than expansions reach their peak; a symmetric oscillator fails this.'
        );
        $this->assertGreaterThan(0.020, $bustSpeed, 'A contraction has to be an event, not a drift.');
    }

    public function testNoSeedIsTrappedAgainstTheCapacityClamp(): void
    {
        foreach (self::SEEDS as $seed) {
            $gaps = $this->simulateGapPath([$seed]);

            $this->assertGreaterThan(
                -0.10,
                min($gaps),
                sprintf('Seed %d must not be driven onto the -12%% capacity clamp.', $seed)
            );

            $longestDeepRun = 0;
            $run = 0;
            foreach ($gaps as $gap) {
                $run = $gap < -0.04 ? $run + 1 : 0;
                $longestDeepRun = max($longestDeepRun, $run);
            }

            $this->assertLessThan(
                20,
                $longestDeepRun,
                sprintf('Seed %d must recover from a deep contraction rather than settle into one.', $seed)
            );
        }
    }

    /**
     * @param list<int>|null $seeds
     * @return list<float> Quarterly output gap observations, burn-in discarded.
     */
    private function simulateGapPath(?array $seeds = null): array
    {
        $cacheKey = implode(',', $seeds ?? self::SEEDS);
        if (isset(self::$pathCache[$cacheKey])) {
            return self::$pathCache[$cacheKey];
        }

        $sampleEvery = intdiv(self::TICKS_PER_YEAR, 4);
        $dt = 1.0 / self::TICKS_PER_YEAR;
        $gaps = [];

        foreach ($seeds ?? self::SEEDS as $seed) {
            mt_srand($seed);
            $mathUtility = new MathUtility();
            $engine = new MacroEngine(
                $mathUtility,
                $this->inMemoryRedis(),
                new MacroSnapshotRecorder(),
                new MonetaryPolicySubsystem($mathUtility),
                new LaborMarketSubsystem($mathUtility),
                new MacroAggregateSubsystem($mathUtility),
                new CommodityLogisticsSubsystem($mathUtility),
                new AssetMarketSubsystem($mathUtility),
                new CreditFiscalSubsystem($mathUtility),
            );

            for ($tick = 1; $tick <= self::YEARS * self::TICKS_PER_YEAR; $tick++) {
                $macro = $engine->updateMacroState($dt);
                if ($tick <= self::BURN_IN_YEARS * self::TICKS_PER_YEAR || $tick % $sampleEvery !== 0) {
                    continue;
                }
                $gaps[] = $macro->outputGap;
            }
        }

        return self::$pathCache[$cacheKey] = $gaps;
    }

    /** The bootstrap's Redis stand-in forgets everything; the engine needs its state back each tick. */
    private function inMemoryRedis(): \Redis
    {
        return new class extends \Redis {
            /** @var array<string, string> */
            private array $store = [];

            public function get(mixed $key): mixed
            {
                return $this->store[(string) $key] ?? false;
            }

            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
            {
                $this->store[$key] = (string) $value;

                return true;
            }
        };
    }
}
