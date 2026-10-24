<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\Service\Macro\Subsystem\Aggregate\OutputGap;
use App\Tests\Support\DistrictRun;
use PHPUnit\Framework\TestCase;

/**
 * The SHAPE of the cycle the engine produces. Business cycles are not symmetric: expansions climb slowly and
 * contractions fall away, so output gaps are left-skewed with a real left tail. That holds across economies (the US
 * CBO gap since 1949, and the HP-filtered GDP of the advanced economies, whose cycles run 1-2% in sd, emerging ones
 * near 3%). The bounds catch a symmetric cycle, a trap, and amplitudes no economy shows; they pin no constant.
 */
class BusinessCycleRealismTest extends TestCase
{
    private const YEARS = 40;
    private const BURN_IN_YEARS = 5;
    private const TICKS_PER_YEAR = 252;
    private const SEEDS = [20260918, 4711, 90210, 1, 2, 3, 5, 8];

    /** @var array<int, list<float>> Quarterly gap paths by seed; the runs are the whole cost of this class. */
    private static array $pathCache = [];

    public function testTheOutputGapIsLeftSkewedWithARealLeftTail(): void
    {
        // A symmetric capacity bound plus symmetric-or-pro-boom economics gives a symmetric distribution; this is what
        // catches one coming back.
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

        $belowThree = count(array_filter($gaps, static fn(float $g): bool => $g < -0.03)) / $count;
        $aboveTwo = count(array_filter($gaps, static fn(float $g): bool => $g > 0.02)) / $count;

        // A fifth of quarters deep in slack is the most an economy whose households borrow at floating rates and whose
        // banks have a crisis every twenty years or so can spend there and still have recessions as episodes.
        $this->assertLessThan(0.20, $belowThree, 'A recession must stay an episode rather than the usual state of the world.');
        $this->assertLessThan(0.20, $aboveTwo, 'The economy must not spend a fifth of its life running two percent hot.');
        $this->assertEqualsWithDelta(0.0, $mean, 0.008, 'A cyclical gap averages near zero over four decades.');
        $this->assertGreaterThan(0.010, $stdDev, 'The cycle has to actually move.');
        $this->assertLessThan(0.030, $stdDev, 'And stay inside business-cycle amplitude.');

        // The skew is only held below zero: eight seeds read it to about a quarter either way, so a tighter bound would
        // fail on a change that reshuffles the paths without changing the shape.
        $this->assertLessThan(0.0, $skew, 'The cycle must be left-skewed: contractions are deeper than expansions are high.');
        $this->assertGreaterThan(0.02, $belowThree, 'A real left tail: some quarters must fall three points below potential.');
    }

    /**
     * A rare bust may reach the clamp: a disaster-sized demand draw can take the gap past -9% and the excess bond
     * premium spike on the fall, and the gap touch the clamp for a few quarters before recovering. Touching is a
     * depression; sitting there for more than a year, or below -4% for ten, is a trap. Five years below -4% is a
     * financial recession, not a trap: Greece, Spain, Portugal and Latvia stayed that deep after 2008.
     */
    public function testNoSeedIsTrappedAgainstTheCapacityClamp(): void
    {
        foreach (self::SEEDS as $seed) {
            $gaps = $this->simulateGapPath([$seed]);

            $atClamp = count(array_filter($gaps, static fn(float $gap): bool => $gap < OutputGap::OUTPUT_GAP_FLOOR + 0.01));
            $this->assertLessThanOrEqual(
                4,
                $atClamp,
                sprintf('Seed %d must not sit on the -12%% capacity clamp.', $seed),
            );

            $longestDeepRun = 0;
            $run = 0;
            foreach ($gaps as $gap) {
                $run = $gap < -0.04 ? $run + 1 : 0;
                $longestDeepRun = max($longestDeepRun, $run);
            }

            $this->assertLessThanOrEqual(
                40,
                $longestDeepRun,
                sprintf('Seed %d must recover from a deep contraction rather than settle into one.', $seed),
            );
        }
    }

    /**
     * @param list<int>|null $seeds
     * @return list<float> Quarterly output gap observations, burn-in discarded, the seeds' paths one after another.
     */
    private function simulateGapPath(?array $seeds = null): array
    {
        $sampleEvery = intdiv(self::TICKS_PER_YEAR, 4);
        $gaps = [];
        foreach ($seeds ?? self::SEEDS as $seed) {
            if (!isset(self::$pathCache[$seed])) {
                self::$pathCache[$seed] = [];
                foreach (DistrictRun::ticks($seed, self::YEARS, self::BURN_IN_YEARS, self::TICKS_PER_YEAR) as $tick => $macro) {
                    if ($tick % $sampleEvery === 0) {
                        self::$pathCache[$seed][] = $macro->outputGap;
                    }
                }
            }
            array_push($gaps, ...self::$pathCache[$seed]);
        }

        return $gaps;
    }
}
