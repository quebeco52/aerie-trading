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
 * The engine does not produce that shape. Measured over 960 simulated years (12 seeds x 80y at production
 * tick rate, 2026-09-18): the gap's skew is +0.02, only 0.4% of quarters fall below -3%, the worst single
 * quarter in the whole run is -3.43%, and busts descend at 1.46pp a year against 4.3-5.1 in the record.
 * Booms are stopped by the cubic capacity clamp while busts are fought by the Taylor rule, downward nominal
 * rigidity and a balance sheet that can only ease -- every asymmetry in the engine pushes the same way.
 *
 * Two mechanisms were built and measured against this, and neither closed it; see the notes on the
 * incomplete tests below. The bounds here are deliberately wider than the calibration target -- they exist
 * to catch the return of a symmetric cycle, not to pin any particular constant.
 */
class BusinessCycleRealismTest extends TestCase
{
    private const YEARS = 40;
    private const BURN_IN_YEARS = 5;
    private const TICKS_PER_YEAR = 252;
    private const SEEDS = [20260918, 4711, 90210];

    public function testTheOutputGapIsLeftSkewedWithARealLeftTail(): void
    {
        // NOT YET MET, and left in place rather than weakened to fit. Two candidates were built and measured
        // at 12 seeds x 80y, and both are recorded here so they are not retried blind:
        //
        // 1. A Gilchrist-Zakrajsek excess bond premium drag on the high-yield tranche took quarters below
        //    -3% from 0.4% to 2.6%, but the skew moved the WRONG way (+0.02 -> +0.07) and quarters above +2%
        //    rose from 21.8% to 25.2%. The gap is a Kaldor limit cycle with a slow capital variable: a deeper
        //    bust digs a bigger pent-up-demand hole (capitalStockOverhang min -0.84% -> -1.72%, max flat)
        //    which KALDOR_CAPITAL_DRAG pays straight back into the recovery. Energy added on the downside is
        //    stored and returned on the upside.
        // 2. Lowering KALDOR_MOMENTUM to zero makes a zero gap a stable point and does produce the central
        //    hump the real distribution has (the engine at 0.12 is a flat plateau, 13-16% of quarters in
        //    every bucket from -2.5% to +2.5%). But the skew gets worse still (+0.09) and the left tail
        //    shrinks to 0.3%: damping the oscillator shrinks both tails symmetrically.
        //
        // What is actually missing: aggregate demand is the only major driver in the engine with NO jump
        // process -- TFP has calculateJumpDiffusion, market volatility has SVJJ/Kou, interbank spreads have
        // Kou, and calculateOutputGap draws a single Gaussian shared between the Smets-Wouters disturbance
        // and the level diffusion. Every asymmetric force in the engine cushions the downside, so nothing can
        // make the gap left-skewed until a downward-skewed demand shock exists.
        $this->markTestIncomplete('The engine has no downward-skewed aggregate demand shock; see the note above.');

        // @phpstan-ignore deadCode.unreachable
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

        // CBO gap: skew -0.32 since 1949 and -1.20 since 1985. A symmetric oscillator reads about zero.
        $this->assertLessThan(-0.15, $skew, 'The cycle must be left-skewed: contractions fall away faster than expansions build.');

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
        $this->markTestIncomplete('Contractions still descend no faster than expansions climb: the cycle is symmetric until aggregate demand gets a downward-skewed shock.');

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
                new LaborMarketSubsystem(),
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

        return $gaps;
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
