<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\Tests\Support\DistrictRun;
use PHPUnit\Framework\TestCase;

/**
 * The term structure the engine produces over a long run: a 2s10s slope that averages a positive premium of under two
 * points, moves, and inverts at times without living there; a ten-year about a point over the policy rate; and a long
 * end that keeps rising. These hold across the economies with a long record (the US, Germany, Japan, the UK outside its
 * pension-driven long end), whose levels, volatilities and inversion shares differ and are not held here.
 */
class YieldCurveRealismTest extends TestCase
{
    private const YEARS = 40;
    private const BURN_IN_YEARS = 5;
    private const TICKS_PER_YEAR = 252;

    /** Twenty-four seeds pooled: a forty-year path is one draw of the cycle, and the slope's moments move with it. */
    private const SPREAD_SEEDS = [
        20260909, 20260918, 4711, 20260924, 20260925, 20260926,
        20261001, 20261002, 20261003, 20261004, 20261005, 20261006, 20261007, 20261008, 20261009,
        20261010, 20261011, 20261012, 20261013, 20261014, 20261015, 20261016, 20261017, 20261018,
    ];
    /**
     * Ticks per year for the slope pool: the macro is timestep-neutral in mean and variance, so twice the seeds cost
     * the same.
     */
    private const SPREAD_TICKS_PER_YEAR = 120;

    public function testTwoTenSpreadDistributionIsAPlausibleTermStructure(): void
    {
        $spreads = [];
        $tenOverPolicy = [];
        $twoOverPolicy = [];
        $thirtyOverTen = [];
        $inverted = 0;

        foreach (self::SPREAD_SEEDS as $seed) {
            foreach (DistrictRun::ticks($seed, self::YEARS, self::BURN_IN_YEARS, self::SPREAD_TICKS_PER_YEAR) as $macro) {
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

        $this->assertNotEmpty($spreads, 'The runs record the slope after burn-in.');
        $count = count($spreads);
        $mean = array_sum($spreads) / $count;
        $variance = 0.0;
        foreach ($spreads as $spread) {
            $variance += ($spread - $mean) ** 2;
        }
        $stdDev = sqrt($variance / $count);

        $this->assertGreaterThan(0.0, $mean, 'the slope averages a real premium');
        $this->assertLessThan(0.02, $mean, 'of under two points');
        $this->assertGreaterThan(0.0030, $stdDev, 'the slope actually moves');
        $this->assertLessThan(0.30, $inverted / $count, 'an inversion ends: the curve steepens again afterwards');
        $this->assertLessThan(-0.0025, min($spreads), 'tightening can invert it deeply enough to matter');

        $this->assertEqualsWithDelta(0.012, array_sum($tenOverPolicy) / $count, 0.007, 'the ten-year runs about a percent over policy');
        $this->assertEqualsWithDelta(0.004, array_sum($twoOverPolicy) / $count, 0.004, 'the two-year hugs the policy rate');
        // The term premium grows with duration, and the District has no regulated pension buyer large enough to push
        // the long end under the ten-year, as the UK's did in 1997-2008.
        $this->assertGreaterThan(0.0, array_sum($thirtyOverTen) / $count, 'the long end keeps rising past ten years');
    }

    /**
     * Yield volatility falls with maturity past the belly: the two-year moves most, the long end no more than the
     * ten-year, and the short and long ends move together.
     */
    public function testTheVolatilityTermStructureSlopesDownPastTheBelly(): void
    {
        $quarterTicks = intdiv(self::TICKS_PER_YEAR, 4);
        $twos = [];
        $tens = [];
        $thirties = [];
        foreach (DistrictRun::ticks(20260919, self::YEARS, self::BURN_IN_YEARS, self::TICKS_PER_YEAR) as $tick => $macro) {
            if ($tick % $quarterTicks !== 0) {
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

        $mean2 = array_sum($d2) / count($d2);
        $mean10 = array_sum($d10) / count($d10);
        $covariance = 0.0;
        for ($i = 0; $i < count($d2); $i++) {
            $covariance += ($d2[$i] - $mean2) * ($d10[$i] - $mean10);
        }
        $correlation = $covariance / (count($d2) * $stdDev($d2) * $stdDev($d10));
        $this->assertGreaterThan(0.60, $correlation, 'the two-year and ten-year move together');
    }
}
