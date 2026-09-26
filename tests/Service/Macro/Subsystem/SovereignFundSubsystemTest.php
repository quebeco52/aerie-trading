<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * Pins the sovereign reserve fund's three rules: the budget draw, the band, and the pace it trades at.
 *
 * Most tests run on a still world (no foreign drift, no noise, a zero foreign rate) so a quantity the rule sets can
 * be read exactly rather than through a random walk; the one test that needs the market runs on the real engine.
 */
class SovereignFundSubsystemTest extends TestCase
{
    /** A board of a trillion in float, at the engine's opening GDP index. */
    private const BOARD_FLOAT_CAP = 1.0e12;

    private const BOARD_TOTAL_CAP = 1.2e12;

    public function testInceptionFundsTheStructuralDeficitAndBooksNoTrade(): void
    {
        $fund = new SovereignFundSubsystem(new MathUtility());
        $state = $this->openFund($fund, 720);

        $this->assertTrue($fund->isIncepted($state));
        $this->assertEqualsWithDelta(MacroEngine::SOVEREIGN_STRUCTURAL_DEFICIT, $state->sovereignFundDrawToGdp, 1e-12, 'Sized so the opening draw funds the structural deficit.');
        $this->assertEqualsWithDelta(SovereignFundSubsystem::DOMESTIC_OWNERSHIP_OPENING, $state->sovereignFundOwnershipShare, 1e-12);
        $this->assertEqualsWithDelta($state->sovereignFundTargetWeight, $state->sovereignFundDomesticWeight, 1e-12, 'It opens at its policy weight.');
        $this->assertSame(0.0, $state->sovereignFundTrade, 'A structural holder opens at its holding, with no order.');
        $this->assertSame(-1.0, $state->lastSovereignRebalanceAt);

        // About 1.4x GDP with a domestic weight near 4% at the World Bank bridge: funding 2% of GDP out of half a
        // compound real return of roughly 2.8% takes a fund near one and a half times the economy.
        $this->assertGreaterThan(1.3, $state->sovereignFundToGdp);
        $this->assertLessThan(1.6, $state->sovereignFundToGdp);
        $this->assertGreaterThan(0.025, $state->sovereignFundTargetWeight);
        $this->assertLessThan(0.05, $state->sovereignFundTargetWeight);
    }

    public function testTheDrawIsHalfTheCompoundReturnNotTheArithmeticMean(): void
    {
        $fund = new SovereignFundSubsystem(new MathUtility());
        $state = new MacroState();

        // All in the reserve portfolio: its arithmetic real return less half the variance of equity leg plus currency.
        $foreignReal = MacroEngine::GLOBAL_BASELINE_RATE - MacroEngine::TARGET_INFLATION;
        $arithmetic = $foreignReal + (SovereignFundSubsystem::FOREIGN_EQUITY_SHARE * MacroEngine::BASE_EQUITY_RISK_PREMIUM);
        $equityLeg = SovereignFundSubsystem::FOREIGN_EQUITY_SHARE * SovereignFundSubsystem::FOREIGN_EQUITY_VOLATILITY;
        $variance = ($equityLeg * $equityLeg) + (MacroEngine::EXCHANGE_RATE_VOLATILITY * MacroEngine::EXCHANGE_RATE_VOLATILITY);

        $this->assertEqualsWithDelta($arithmetic - (0.5 * $variance), $fund->expectedCompoundRealReturn($state, 0.0), 1e-15);

        // All on the board: the natural rate plus the premium, less half the market's variance.
        $boardVariance = MacroEngine::MACRO_VOL_BASE_ANCHOR * MacroEngine::MACRO_VOL_BASE_ANCHOR;
        $this->assertEqualsWithDelta(
            $state->naturalRate + MacroEngine::BASE_EQUITY_RISK_PREMIUM - (0.5 * $boardVariance),
            $fund->expectedCompoundRealReturn($state, 1.0),
            1e-15
        );

        // Diversification: a blend compounds faster than the weighted compound returns of its parts.
        $blend = $fund->expectedCompoundRealReturn($state, 0.3);
        $parts = (0.3 * $fund->expectedCompoundRealReturn($state, 1.0)) + (0.7 * $fund->expectedCompoundRealReturn($state, 0.0));
        $this->assertGreaterThan($parts, $blend);
    }

    public function testWithNoBoardThereIsNoFundAndNoRandomDraw(): void
    {
        $fund = new SovereignFundSubsystem(new MathUtility());
        $state = new MacroState();
        $state->totalTime = 1.0 / 720.0;

        mt_srand(41);
        $expected = mt_rand();
        mt_srand(41);
        $fund->update($state, 1.0 / 720.0);

        $this->assertFalse($fund->isIncepted($state));
        $this->assertSame(0.0, $state->sovereignFundDrawToGdp);
        $this->assertSame(0.0, $state->sovereignFundTrade);
        $this->assertSame($expected, mt_rand(), 'A run with no market must replay exactly the random path it always had.');
    }

    public function testTheDrawIsFixedForTheBudgetYearAndResetAtItsEnd(): void
    {
        $tpy = 360;
        $dt = 1.0 / $tpy;
        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, $tpy);
        $openingDraw = $state->sovereignFundAnnualDraw;

        // The reserve portfolio doubles in the middle of the year; the draw does not move until the year turns.
        $state->sovereignFundForeignAssets *= 2.0;
        for ($i = 0; $i < intdiv($tpy, 2); ++$i) {
            $this->step($fund, $state, $dt);
        }
        $this->assertSame($openingDraw, $state->sovereignFundAnnualDraw);

        while ($state->totalTime < 1.0 + (0.5 * $dt)) {
            $this->step($fund, $state, $dt);
        }
        $this->assertGreaterThan(1.8 * $openingDraw, $state->sovereignFundAnnualDraw, 'The new year is struck on the larger fund.');
        // Struck on the fund as the year turned, one tick of payment and trading before this reading.
        $this->assertEqualsWithDelta($fund->calculateAnnualDraw($state), $state->sovereignFundAnnualDraw, 1e-3 * $state->sovereignFundAnnualDraw);
    }

    public function testTheBandIsGpifsOwnLimitAtGpifsOwnWeight(): void
    {
        $fund = new SovereignFundSubsystem(new MathUtility());

        $this->assertEqualsWithDelta(
            SovereignFundSubsystem::GPIF_DOMESTIC_EQUITY_DEVIATION_LIMIT,
            $fund->rebalanceBand(SovereignFundSubsystem::GPIF_DOMESTIC_EQUITY_TARGET),
            1e-15,
            'Carried by the breaching move, the rule reproduces itself at the weight it was written for.'
        );
    }

    public function testTheFallThatBreachesTheBandIsTheSameAtEveryWeight(): void
    {
        $fund = new SovereignFundSubsystem(new MathUtility());
        $target = SovereignFundSubsystem::GPIF_DOMESTIC_EQUITY_TARGET;
        $limit = SovereignFundSubsystem::GPIF_DOMESTIC_EQUITY_DEVIATION_LIMIT;
        $gpifBreach = $limit / (($target * (1.0 - $target)) + ($target * $limit));

        foreach ([0.02, 0.05, 0.10, 0.25, 0.40] as $weight) {
            $band = $fund->rebalanceBand($weight);
            // A relative fall d moves a weight w by w(1 - w)d / (1 - wd); invert it at the band.
            $breach = $band / (($weight * (1.0 - $weight)) + ($weight * $band));
            $this->assertEqualsWithDelta($gpifBreach, $breach, 1e-12, "At weight {$weight} the band must trip on GPIF's move, about 30%.");
        }
    }

    public function testAThirtyOnePercentFallBreachesTheBandAndTwentySevenDoesNot(): void
    {
        $tpy = 360;
        $dt = 1.0 / $tpy;

        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, $tpy);
        $this->crashDomestic($state, 0.27);
        $this->runToMonthEnd($fund, $state, $dt);
        $this->assertSame(0.0, $state->sovereignFundRebalanceMonthsLeft, 'A 27% fall stays inside the band.');
        $this->assertSame(0.0, $state->sovereignFundTrade);

        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, $tpy);
        $this->crashDomestic($state, 0.31);
        $this->runToMonthEnd($fund, $state, $dt);
        $this->assertSame(SovereignFundSubsystem::REBALANCE_EXECUTION_MONTHS, $state->sovereignFundRebalanceMonthsLeft);
        $this->assertSame($state->totalTime, $state->lastSovereignRebalanceAt);
        $this->assertGreaterThan(0.0, $state->sovereignFundTrade, 'It buys after a fall.');
    }

    public function testAProgrammeTradesAThirdOfTheGapAMonthWhateverTheTickRate(): void
    {
        foreach ([72, 720, 7200] as $tpy) {
            $dt = 1.0 / $tpy;
            $fund = new SovereignFundSubsystem($this->stillMarket());
            $state = $this->openFund($fund, $tpy);
            $this->crashDomestic($state, 0.35);

            $this->runToMonthEnd($fund, $state, $dt);
            $gap = $state->sovereignFundRebalanceBacklog + $state->sovereignFundTrade;

            $traded = $state->sovereignFundTrade;
            for ($i = 1; $i < intdiv($tpy, 12); ++$i) {
                $this->step($fund, $state, $dt);
                $traded += $state->sovereignFundTrade;
            }

            $this->assertEqualsWithDelta($gap / 3.0, $traded, 1e-9 * $gap, "tpy {$tpy}: a month of the programme is a third of the gap.");
        }
    }

    public function testAProgrammeLandsOnThePolicyWeightAndStops(): void
    {
        $tpy = 360;
        $dt = 1.0 / $tpy;
        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, $tpy);
        $this->crashDomestic($state, 0.40);

        $this->runToMonthEnd($fund, $state, $dt);
        $start = $state->totalTime;
        while ($state->totalTime < $start + (3.0 / 12.0) + (0.5 * $dt)) {
            $this->step($fund, $state, $dt);
        }

        $this->assertSame(0.0, $state->sovereignFundRebalanceMonthsLeft);
        $this->assertSame(0.0, $state->sovereignFundTrade, 'The programme is over.');
        $this->assertEqualsWithDelta($state->sovereignFundTargetWeight, $state->sovereignFundDomesticWeight, 1e-4, 'Back at the policy weight, give or take a month of the draw.');
    }

    public function testTheFundNeverBuysPastTheOwnershipCeiling(): void
    {
        $tpy = 360;
        $dt = 1.0 / $tpy;
        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, $tpy);

        // The board collapses against everything else the fund holds: restoring the weight would mean owning most of it.
        $this->crashDomestic($state, 0.80);
        $this->runToMonthEnd($fund, $state, $dt);
        $this->assertGreaterThan(0.0, $state->sovereignFundTrade, 'It still buys the fall.');

        for ($i = 0; $i < 3 * $tpy; ++$i) {
            $this->step($fund, $state, $dt);
            $this->assertLessThanOrEqual(
                SovereignFundSubsystem::MAX_OWNERSHIP_SHARE + 1e-12,
                $state->sovereignFundDomesticEquity / $state->boardFloatCap
            );
        }
        $this->assertEqualsWithDelta(SovereignFundSubsystem::MAX_OWNERSHIP_SHARE, $state->sovereignFundOwnershipShare, 1e-9, 'It stops at the ceiling.');
        $this->assertSame(0.0, $state->sovereignFundRebalanceMonthsLeft, 'With no room left there is no programme to run or announce.');
    }

    public function testATradeMovesMoneyBetweenTheSleevesWithoutChangingTheFund(): void
    {
        $tpy = 360;
        $dt = 1.0 / $tpy;
        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, $tpy);
        $this->crashDomestic($state, 0.35);
        $this->runToMonthEnd($fund, $state, $dt);
        $state->sovereignFundAnnualDraw = 0.0;

        $before = $fund->fundValue($state);
        for ($i = 0; $i < 10; ++$i) {
            $this->step($fund, $state, $dt);
            $this->assertNotSame(0.0, $state->sovereignFundTrade);
        }

        $this->assertEqualsWithDelta($before, $fund->fundValue($state), 1e-9 * $before);
    }

    public function testAStrongerHomeCurrencyLowersTheReservePortfolioInHomeTerms(): void
    {
        $fund = new SovereignFundSubsystem(new MathUtility());
        $state = $this->openFund($fund, 720);
        $atPar = $fund->foreignHomeValue($state);

        $state->exchangeRateIndex = 110.0;

        $this->assertEqualsWithDelta($atPar * 100.0 / 110.0, $fund->foreignHomeValue($state), 1e-6 * $atPar);
    }

    public function testDividendsArriveAsCashAtTheOwnershipShare(): void
    {
        $tpy = 720;
        $dt = 1.0 / $tpy;
        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, $tpy);
        $state->sovereignFundAnnualDraw = 0.0;

        $before = $fund->foreignHomeValue($state);
        $state->boardDividendCash = 1.0e9;
        $this->step($fund, $state, $dt);

        $this->assertEqualsWithDelta(SovereignFundSubsystem::DOMESTIC_OWNERSHIP_OPENING * 1.0e9, $fund->foreignHomeValue($state) - $before, 1e-3);
    }

    public function testTheDomesticSleeveEarnsTheBoardsPriceReturn(): void
    {
        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, 720);
        $before = $state->sovereignFundDomesticEquity;

        $state->boardPriceReturn = -0.02;
        $this->step($fund, $state, 1.0 / 720.0);

        $this->assertEqualsWithDelta(0.98 * $before, $state->sovereignFundDomesticEquity, 1e-6);
    }

    /**
     * The engine's opening economy with a board reported, advanced by one tick so the fund incepts.
     */
    private function openFund(SovereignFundSubsystem $fund, int $tpy): MacroState
    {
        $dt = 1.0 / $tpy;
        $state = new MacroState();
        $state->boardFloatCap = self::BOARD_FLOAT_CAP;
        $state->equityMarketCap = self::BOARD_TOTAL_CAP;
        $state->foreignPolicyRate = 0.0;
        $state->totalTime = $dt;
        $fund->update($state, $dt);

        return $state;
    }

    /** The domestic sleeve and the board fall together by $fall, as a crash marks them. */
    private function crashDomestic(MacroState $state, float $fall): void
    {
        $state->sovereignFundDomesticEquity *= 1.0 - $fall;
        $state->boardFloatCap *= 1.0 - $fall;
    }

    private function step(SovereignFundSubsystem $fund, MacroState $state, float $dt): void
    {
        $state->totalTime += $dt;
        $fund->update($state, $dt);
        $state->boardPriceReturn = 0.0;
        $state->boardDividendCash = 0.0;
    }

    /** Steps until the tick that crosses the next month end, inclusive. */
    private function runToMonthEnd(SovereignFundSubsystem $fund, MacroState $state, float $dt): void
    {
        do {
            $this->step($fund, $state, $dt);
        } while (!MathUtility::crossedSimulatedBoundary($state->totalTime, $dt, SovereignFundSubsystem::REBALANCE_CHECK_PERIOD_YEARS));
    }

    /** A foreign market that stands still, so the rules can be read without a random walk under them. */
    private function stillMarket(): MathUtility
    {
        return new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }

            public function calculateCorrelatedGBM(
                float $currentPrice,
                float $idiosyncraticVolatility,
                float $drift,
                float $gravityDrift,
                float $dt,
                float $beta,
                float $marketVol,
                float $marketZ,
                float $w1,
                float $sectorZ = 0.0,
                float $sectorVarianceShare = 0.0
            ): float {
                return $currentPrice;
            }
        };
    }
}
