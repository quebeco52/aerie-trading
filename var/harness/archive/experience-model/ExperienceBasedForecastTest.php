<?php

declare(strict_types=1);

namespace App\Tests\Service\Math;

use App\Data\MainlandInflation;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

class ExperienceBasedForecastTest extends TestCase
{
    private const THETA = 3.044;

    /** The quarter index of a mainland year and quarter in MainlandInflation::QUARTERLY. */
    private static function quarter(int $year, int $quarter): int
    {
        return (($year - MainlandInflation::FIRST_YEAR) * 4) + ($quarter - MainlandInflation::FIRST_QUARTER);
    }

    /**
     * The closed form is the recursion: run Malmendier & Nagel's (2016) recursive least squares on the moments,
     * R_t = R_{t-1} + g_t (h h' - R_{t-1}) and r_t = r_{t-1} + g_t (h pi - r_{t-1}) with g_t = theta / age, solve the AR(1)
     * at the end and iterate it forward: the forecast matches.
     */
    public function testTheClosedFormIsTheRecursiveLeastSquares(): void
    {
        $history = [];
        mt_srand(11);
        $inflation = 0.03;
        for ($k = 0; $k < 160; ++$k) {
            $inflation = 0.01 + (0.7 * $inflation) + (0.01 * ((mt_rand() / mt_getrandmax()) - 0.5));
            $history[] = $inflation;
        }
        $birth = 20;
        $last = 150;

        $r = [[0.0, 0.0], [0.0, 0.0]];
        $m = [0.0, 0.0];
        for ($k = $birth + 1; $k <= $last; ++$k) {
            $age = $k - $birth;
            $gain = $age < self::THETA ? 1.0 : self::THETA / $age;
            $h = [1.0, $history[$k - 1]];
            for ($i = 0; $i < 2; ++$i) {
                $m[$i] += $gain * (($h[$i] * $history[$k]) - $m[$i]);
                for ($j = 0; $j < 2; ++$j) {
                    $r[$i][$j] += $gain * (($h[$i] * $h[$j]) - $r[$i][$j]);
                }
            }
        }
        $det = ($r[0][0] * $r[1][1]) - ($r[0][1] * $r[1][0]);
        $intercept = (($r[1][1] * $m[0]) - ($r[0][1] * $m[1])) / $det;
        $slope = (($r[0][0] * $m[1]) - ($r[1][0] * $m[0])) / $det;
        $path = $history[$last];
        $sum = 0.0;
        for ($step = 0; $step < 4; ++$step) {
            $path = $intercept + ($slope * $path);
            $sum += $path;
        }

        $this->assertEqualsWithDelta($sum / 4.0, MathUtility::experienceBasedForecast($history, $birth, $last, self::THETA, 4), 1e-12);
    }

    /**
     * On the mainland's own record the rule reads as it did for the Federal Reserve's committee (Malmendier, Nagel & Yan
     * 2021): a member of the committee's average age, 56, forecast 3.4% on average with a spread of 1.8 points over
     * 1951-2014; on the record to 2008, before the low inflation after it, a little higher.
     */
    public function testAMemberOfAverageAgeForecastsAsTheFedsCommitteeDid(): void
    {
        $forecasts = [];
        for ($t = self::quarter(1951, 1); $t <= self::quarter(2008, 3); ++$t) {
            $forecasts[] = MathUtility::experienceBasedForecast(MainlandInflation::QUARTERLY, $t - (56 * 4), $t, self::THETA, 4);
        }
        $mean = array_sum($forecasts) / count($forecasts);
        $spread = sqrt(array_sum(array_map(static fn(float $f): float => ($f - $mean) ** 2, $forecasts)) / count($forecasts));

        $this->assertEqualsWithDelta(0.036, $mean, 0.004);
        $this->assertEqualsWithDelta(0.018, $spread, 0.003);
    }

    /**
     * The young weigh recent years most: in 1980, after a decade of high inflation, the youngest members' forecasts ran
     * well above the median member's, about a point and a half, and by 1985, with inflation tamed, the gap had closed
     * (Malmendier, Nagel & Yan 2021, Figure 2).
     */
    public function testTheYoungWeighRecentYearsMost(): void
    {
        $gap = static function (int $year) : float {
            $t = self::quarter($year, 1);

            return MathUtility::experienceBasedForecast(MainlandInflation::QUARTERLY, $t - (45 * 4), $t, self::THETA, 4)
                - MathUtility::experienceBasedForecast(MainlandInflation::QUARTERLY, $t - (56 * 4), $t, self::THETA, 4);
        };

        $this->assertEqualsWithDelta(0.015, $gap(1980), 0.005);
        $this->assertLessThan(0.002, abs($gap(1985)));
    }

    /** A life of steady inflation forecasts that inflation, and childhood alone, before any slope can be fitted, forecasts the last quarter seen. */
    public function testSteadyInflationForecastsItself(): void
    {
        $history = array_fill(0, 200, 0.025);

        $this->assertEqualsWithDelta(0.025, MathUtility::experienceBasedForecast($history, 10, 199, self::THETA, 4), 1e-12);
        $this->assertEqualsWithDelta(0.025, MathUtility::experienceBasedForecast($history, 199, 199, self::THETA, 4), 1e-12);
    }
}
