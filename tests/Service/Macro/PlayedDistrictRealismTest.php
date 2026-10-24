<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\Service\Macro\Subsystem\Aggregate\OutputGap;
use App\Tests\Support\DistrictRun;
use PHPUnit\Framework\TestCase;

/**
 * The economy players meet: the reserve fund in it and the Diet beside it, elections, cabinets and the laws they pass
 * moving taxes, spending, migration and the fund's draw. BusinessCycleRealismTest and YieldCurveRealismTest hold the
 * founding levers still to read the macro's own shape over many seeds; this holds the played District to the same
 * plausible ranges on four, since the Diet costs about a second a simulated year.
 */
class PlayedDistrictRealismTest extends TestCase
{
    private const YEARS = 25;
    private const BURN_IN_YEARS = 5;
    private const TICKS_PER_YEAR = 52;
    private const SEEDS = [20261018, 4711, 90210, 1];

    /**
     * @var array<int, array{gaps: list<float>, spreads: list<float>, tenOverPolicy: list<float>, thirtyOverTen: list<float>}>
     */
    private static array $runs = [];

    public function testThePlayedCycleStaysInsideBusinessCycleAmplitude(): void
    {
        $gaps = array_merge(...array_column(array_map(self::playedRun(...), self::SEEDS), 'gaps'));
        $count = count($gaps);
        $mean = array_sum($gaps) / $count;
        $stdDev = sqrt(array_sum(array_map(static fn(float $gap): float => ($gap - $mean) ** 2, $gaps)) / $count);

        // A District can run below potential for decades under the laws it passes, as Japan did after 1992; a point and
        // a half off, either way, is a trap.
        $this->assertEqualsWithDelta(0.0, $mean, 0.015, 'A cyclical gap averages near potential, whatever the Diet passes.');
        $this->assertGreaterThan(0.010, $stdDev, 'The cycle has to actually move.');
        $this->assertLessThan(0.030, $stdDev, 'And stay inside business-cycle amplitude.');
        $this->assertLessThan(0.20, count(array_filter($gaps, static fn(float $g): bool => $g < -0.03)) / $count, 'A recession stays an episode.');
        $this->assertLessThan(0.20, count(array_filter($gaps, static fn(float $g): bool => $g > 0.02)) / $count, 'And so does a boom.');
    }

    public function testNoGovernmentTrapsTheEconomyAgainstTheClamp(): void
    {
        foreach (self::SEEDS as $seed) {
            $gaps = self::playedRun($seed)['gaps'];
            $atClamp = count(array_filter($gaps, static fn(float $gap): bool => $gap < OutputGap::OUTPUT_GAP_FLOOR + 0.01));
            $this->assertLessThanOrEqual(4, $atClamp, sprintf('Seed %d must not sit on the capacity clamp.', $seed));

            $longestDeepRun = 0;
            $run = 0;
            foreach ($gaps as $gap) {
                $run = $gap < -0.04 ? $run + 1 : 0;
                $longestDeepRun = max($longestDeepRun, $run);
            }
            $this->assertLessThanOrEqual(40, $longestDeepRun, sprintf('Seed %d must recover from a deep contraction.', $seed));
        }
    }

    public function testThePlayedCurveIsAPlausibleTermStructure(): void
    {
        $runs = array_map(self::playedRun(...), self::SEEDS);
        $spreads = array_merge(...array_column($runs, 'spreads'));
        $count = count($spreads);
        $mean = array_sum($spreads) / $count;

        $this->assertGreaterThan(0.0, $mean, 'The slope averages a real premium');
        $this->assertLessThan(0.02, $mean, 'of under two points.');
        $this->assertLessThan(0.30, count(array_filter($spreads, static fn(float $s): bool => $s < 0.0)) / $count, 'An inversion ends.');
        $this->assertEqualsWithDelta(0.012, array_sum(array_merge(...array_column($runs, 'tenOverPolicy'))) / $count, 0.007, 'The ten-year runs about a percent over policy.');
        $this->assertGreaterThan(0.0, array_sum(array_merge(...array_column($runs, 'thirtyOverTen'))) / $count, 'The long end keeps rising past ten years.');
    }

    /**
     * @return array{gaps: list<float>, spreads: list<float>, tenOverPolicy: list<float>, thirtyOverTen: list<float>}
     */
    private static function playedRun(int $seed): array
    {
        if (!isset(self::$runs[$seed])) {
            $sampleEvery = intdiv(self::TICKS_PER_YEAR, 4);
            $run = ['gaps' => [], 'spreads' => [], 'tenOverPolicy' => [], 'thirtyOverTen' => []];
            foreach (DistrictRun::ticks($seed, self::YEARS, self::BURN_IN_YEARS, self::TICKS_PER_YEAR, withDiet: true) as $tick => $macro) {
                if ($tick % $sampleEvery === 0) {
                    $run['gaps'][] = $macro->outputGap;
                }
                $run['spreads'][] = $macro->yield10y - $macro->yield2y;
                $run['tenOverPolicy'][] = $macro->yield10y - $macro->policyRate;
                $run['thirtyOverTen'][] = $macro->yield30y - $macro->yield10y;
            }
            self::$runs[$seed] = $run;
        }

        return self::$runs[$seed];
    }
}
