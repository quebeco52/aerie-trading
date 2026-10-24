<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\DTO\MacroStateDTO;
use App\Tests\Support\DistrictRun;
use PHPUnit\Framework\TestCase;

/**
 * The credit cycle opens where the District's own cycle averages it: the household and corporate default rates, the
 * recession forecast and the IG, HY and interbank spreads, with their decade-long trends. A trend opened anywhere else
 * is a standing deviation for a decade, and every reader of a rate against its trend (a lender's loss multiple, the
 * CECL outlook, household refinancing, trade credit, the sector models' credit channels) prices it as a cycle that is
 * not there.
 */
class CreditCycleOpeningRealismTest extends TestCase
{
    private const YEARS = 160;
    private const BURN_IN_YEARS = 10;
    /** The macro is timestep-neutral in mean, so a coarse tick buys seeds. */
    private const TICKS_PER_YEAR = 24;
    /** A crisis or two decides a seed's average, so sixteen are pooled. */
    private const SEEDS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16];

    public function testTheCreditCycleOpensAtTheLevelItAverages(): void
    {
        $sampleEvery = intdiv(self::TICKS_PER_YEAR, 4);
        $retail = 0.0;
        $corporate = 0.0;
        $recession = 0.0;
        $investmentGrade = 0.0;
        $highYield = 0.0;
        $interbank = 0.0;
        $quarters = 0;
        foreach (self::SEEDS as $seed) {
            foreach (DistrictRun::ticks($seed, self::YEARS, self::BURN_IN_YEARS, self::TICKS_PER_YEAR) as $tick => $macro) {
                if ($tick % $sampleEvery === 0) {
                    $retail += $macro->retailDefaultRateEma;
                    $corporate += $macro->corporateDefaultRateEma;
                    $recession += $macro->recessionProbabilityEma;
                    $investmentGrade += $macro->macroCreditSpreadEma;
                    $highYield += $macro->highYieldCreditSpreadEma;
                    $interbank += $macro->interbankLiquiditySpreadEma;
                    ++$quarters;
                }
            }
        }

        $opening = new MacroStateDTO();
        // Sixteen seeds read the default rates to about 1.5%; the opening missed them by 11-12% before it was set here.
        $this->assertEqualsWithDelta(1.0, $retail / $quarters / $opening->retailDefaultRateTrend, 0.06, 'The household default rate opens at its own average.');
        $this->assertEqualsWithDelta(1.0, $corporate / $quarters / $opening->corporateDefaultRateTrend, 0.06, 'The corporate default rate opens at its own average.');
        // The forecast is read to about 3%, and under the founding levers it runs ~1.5pp below the played District's.
        $this->assertEqualsWithDelta(1.0, $recession / $quarters / $opening->recessionProbabilityTrend, 0.15, 'The recession forecast opens at its own average.');
        // The spreads settle above the bases they are built from: IG and HY by about 11%, interbank by about 60%.
        $this->assertEqualsWithDelta(1.0, $investmentGrade / $quarters / $opening->macroCreditSpreadTrend, 0.05, 'The IG spread trend opens at its own average.');
        $this->assertEqualsWithDelta(1.0, $highYield / $quarters / $opening->highYieldCreditSpreadTrend, 0.05, 'The HY spread trend opens at its own average.');
        $this->assertEqualsWithDelta(1.0, $interbank / $quarters / $opening->interbankLiquiditySpreadTrend, 0.05, 'The interbank spread trend opens at its own average.');
    }
}
