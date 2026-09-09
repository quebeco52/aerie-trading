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

    public function testTwoTenSpreadDistributionMatchesTheHistoricalRecord(): void
    {
        mt_srand(20260909);
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

        $spreads = [];
        $tenOverPolicy = [];
        $twoOverPolicy = [];
        $thirtyOverTen = [];
        $inverted = 0;
        $dt = 1.0 / self::TICKS_PER_YEAR;
        for ($tick = 0; $tick < self::YEARS * self::TICKS_PER_YEAR; $tick++) {
            $macro = $engine->updateMacroState($dt);
            if ($tick < self::BURN_IN_YEARS * self::TICKS_PER_YEAR) {
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

            public function set(mixed $key, mixed $val): bool
            {
                $this->store[(string) $key] = (string) $val;

                return true;
            }
        };
    }
}
