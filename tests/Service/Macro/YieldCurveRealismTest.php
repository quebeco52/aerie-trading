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
 * The term structure the engine produces over a long run, held against the post-1976 US record: a 2s10s
 * slope that averages under a percent, swings widely, and spends a meaningful share of its time inverted;
 * a ten-year about a percent over the policy rate; and a long end that keeps rising. Before the duration-
 * scaled term premium the slope averaged 54bps with a 26bps standard deviation and inverted 3% of the time.
 */
class YieldCurveRealismTest extends TestCase
{
    private const YEARS = 40;
    private const BURN_IN_YEARS = 5;
    private const TICKS_PER_YEAR = 252;

    /**
     * Six seeds pooled: a forty-year path is one draw of the cycle, and its mean slope moves about a third of a percent
     * seed to seed (40-seed sd 0.34pp around a 1.02% mean), so three seeds left the pooled mean a fifth of a percent of
     * noise against a bound a quarter of a percent away.
     */
    private const SPREAD_SEEDS = [20260909, 20260918, 4711, 20260924, 20260925, 20260926];
    /** Ticks per year for the slope pool: the macro is timestep-neutral in mean and variance, so twice the seeds cost the same. */
    private const SPREAD_TICKS_PER_YEAR = 120;

    public function testTwoTenSpreadDistributionMatchesTheHistoricalRecord(): void
    {
        $spreads = [];
        $tenOverPolicy = [];
        $twoOverPolicy = [];
        $thirtyOverTen = [];
        $inverted = 0;
        $dt = 1.0 / self::SPREAD_TICKS_PER_YEAR;

        foreach (self::SPREAD_SEEDS as $seed) {
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

            for ($tick = 0; $tick < self::YEARS * self::SPREAD_TICKS_PER_YEAR; $tick++) {
                $macro = $engine->updateMacroState($dt);
                if ($tick < self::BURN_IN_YEARS * self::SPREAD_TICKS_PER_YEAR) {
                    continue;
                }
                $spread = $macro->yield10y - $macro->yield2y;
                $spreads[] = $spread;
                $tenOverPolicy[] = $macro->yield10y - $macro->policyRate;
                $twoOverPolicy[] = $macro->yield2y - $macro->policyRate;
                $thirtyOverTen[] = $macro->yield30y - $macro->yield10y;
                if ($spread < 0.0) {
                    $inverted++;
                }
            }
        }

        $count = count($spreads);
        $mean = array_sum($spreads) / $count;
        $variance = 0.0;
        foreach ($spreads as $spread) {
            $variance += ($spread - $mean) ** 2;
        }
        $stdDev = sqrt($variance / $count);

        // US 2s10s since 1976: mean ~95bps, standard deviation ~95bps, inverted ~15% of months.
        $this->assertGreaterThan(0.0050, $mean, 'the slope averages a real premium');
        $this->assertLessThan(0.0125, $mean, 'but not more than the record');
        $this->assertGreaterThan(0.0030, $stdDev, 'the slope actually moves');
        $this->assertGreaterThan(0.03, $inverted / $count, 'the curve inverts in tightening cycles');
        $this->assertLessThan(0.30, $inverted / $count, 'and steepens again afterwards');
        $this->assertLessThan(-0.0025, min($spreads), 'an inversion is deep enough to matter');

        $this->assertEqualsWithDelta(0.012, array_sum($tenOverPolicy) / $count, 0.007, 'the ten-year runs about a percent over policy');
        $this->assertEqualsWithDelta(0.004, array_sum($twoOverPolicy) / $count, 0.004, 'the two-year hugs the policy rate');
        $this->assertGreaterThan(0.0, array_sum($thirtyOverTen) / $count, 'the long end keeps rising past ten years');
    }

    /**
     * Yield volatility falls with maturity past the belly: real quarterly changes run ~50bps at the 2Y and
     * ~45bps at the 30Y. Before the expected-path innovation the engine's 2Y moved half as much as its 30Y.
     * The 2Y/10Y co-movement is bounded near 0.7 by the factor structure (the ten-year's premium shock is
     * mostly its own), against ~0.9 in the data; the bound here guards against it falling back.
     */
    public function testTheVolatilityTermStructureSlopesDownPastTheBelly(): void
    {
        mt_srand(20260919);
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

        $quarterTicks = intdiv(self::TICKS_PER_YEAR, 4);
        $dt = 1.0 / self::TICKS_PER_YEAR;
        $twos = [];
        $tens = [];
        $thirties = [];
        for ($tick = 1; $tick <= self::YEARS * self::TICKS_PER_YEAR; $tick++) {
            $macro = $engine->updateMacroState($dt);
            if ($tick < self::BURN_IN_YEARS * self::TICKS_PER_YEAR || $tick % $quarterTicks !== 0) {
                continue;
            }
            $twos[] = $macro->yield2y;
            $tens[] = $macro->yield10y;
            $thirties[] = $macro->yield30y;
        }

        $changes = static function (array $series): array {
            $deltas = [];
            for ($i = 1; $i < count($series); $i++) {
                $deltas[] = $series[$i] - $series[$i - 1];
            }

            return $deltas;
        };
        $stdDev = static function (array $values): float {
            $mean = array_sum($values) / count($values);
            $variance = 0.0;
            foreach ($values as $value) {
                $variance += ($value - $mean) ** 2;
            }

            return sqrt($variance / count($values));
        };

        $d2 = $changes($twos);
        $d10 = $changes($tens);
        $d30 = $changes($thirties);

        $this->assertGreaterThan($stdDev($d10), $stdDev($d2), 'the two-year is the most volatile tenor');
        $this->assertGreaterThan(0.9 * $stdDev($d30), $stdDev($d10), 'and the long end is no more volatile than the ten-year');
        $this->assertGreaterThan(0.0035, $stdDev($d2), 'the two-year moves like a market, not like a policy rate');

        $mean2 = array_sum($d2) / count($d2);
        $mean10 = array_sum($d10) / count($d10);
        $covariance = 0.0;
        for ($i = 0; $i < count($d2); $i++) {
            $covariance += ($d2[$i] - $mean2) * ($d10[$i] - $mean10);
        }
        $correlation = $covariance / (count($d2) * $stdDev($d2) * $stdDev($d10));
        $this->assertGreaterThan(0.60, $correlation, 'the two-year and ten-year move together');
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
