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
 * Most tests run on a still world (no foreign drift, no noise, a foreign curve flat at zero) so a quantity the rule
 * sets can be read exactly rather than through a random walk; the tests that need a market run on the real engine.
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

        // Funding 2% of GDP out of half a compound real return of roughly 3.3% takes a fund about one and a fifth times
        // the economy, whatever the economy is worth in currency.
        $this->assertGreaterThan(1.1, $state->sovereignFundToGdp);
        $this->assertLessThan(1.35, $state->sovereignFundToGdp);

        // The draw is struck on the expected return it publishes.
        $this->assertEqualsWithDelta(
            SovereignFundSubsystem::NIR_SPENDING_SHARE * $state->sovereignFundExpectedRealReturn * $fund->fundValue($state),
            $state->sovereignFundAnnualDraw,
            1e-9 * $state->sovereignFundAnnualDraw
        );
        $this->assertSame(SovereignFundSubsystem::RETURN_INDEX_BASE, $state->sovereignFundReturnIndex);
        $this->assertSame(SovereignFundSubsystem::RETURN_INDEX_BASE, $state->sovereignFundRealReturnIndex);

        // GDP in currency is the board over the capitalisation ratio, so the opening holding fixes the board weight.
        $gdpDollars = $state->equityMarketCap / SovereignFundSubsystem::MARKET_CAP_TO_GDP;
        $this->assertEqualsWithDelta(
            SovereignFundSubsystem::DOMESTIC_OWNERSHIP_OPENING * $state->boardFloatCap / ($state->sovereignFundToGdp * $gdpDollars),
            $state->sovereignFundTargetWeight,
            1e-12
        );
    }

    public function testTheDrawIsHalfTheCompoundReturnNotTheArithmeticMean(): void
    {
        $fund = new SovereignFundSubsystem(new MathUtility());
        $state = new MacroState();

        // All in the reserve portfolio: its arithmetic real return less half the variance of equity leg plus currency.
        // The paper earns the forward term premium at its duration over the foreign rate.
        $foreignReal = MacroEngine::GLOBAL_BASELINE_RATE - MacroEngine::TARGET_INFLATION;
        $arithmetic = $foreignReal
            + (SovereignFundSubsystem::FOREIGN_EQUITY_SHARE * MacroEngine::BASE_EQUITY_RISK_PREMIUM)
            + ((1.0 - SovereignFundSubsystem::FOREIGN_EQUITY_SHARE) * SovereignFundSubsystem::foreignBondExpectedPremium());
        $equityLeg = SovereignFundSubsystem::FOREIGN_EQUITY_SHARE * SovereignFundSubsystem::FOREIGN_EQUITY_VOLATILITY;
        $variance = ($equityLeg * $equityLeg) + (MacroEngine::EXCHANGE_RATE_VOLATILITY * MacroEngine::EXCHANGE_RATE_VOLATILITY);

        $this->assertEqualsWithDelta($arithmetic - (0.5 * $variance), $fund->policyCompoundRealReturn($state, 0.0), 1e-15);

        // All on the board: the natural rate plus the premium, less half the market's variance.
        $boardVariance = MacroEngine::MACRO_VOL_BASE_ANCHOR * MacroEngine::MACRO_VOL_BASE_ANCHOR;
        $this->assertEqualsWithDelta(
            $state->naturalRate + MacroEngine::BASE_EQUITY_RISK_PREMIUM - (0.5 * $boardVariance),
            $fund->policyCompoundRealReturn($state, 1.0),
            1e-15
        );

        // Diversification: a blend compounds faster than the weighted compound returns of its parts.
        $blend = $fund->policyCompoundRealReturn($state, 0.3);
        $parts = (0.3 * $fund->policyCompoundRealReturn($state, 1.0)) + (0.7 * $fund->policyCompoundRealReturn($state, 0.0));
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
        $state->sovereignFundForeignEquity *= 2.0;
        $state->sovereignFundForeignBonds *= 2.0;
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

    public function testTheEquityBandIsGpifsOwnGlobalLimitAtGpifsOwnEquityShare(): void
    {
        $fund = new SovereignFundSubsystem(new MathUtility());

        $this->assertEqualsWithDelta(
            SovereignFundSubsystem::GPIF_GLOBAL_EQUITY_DEVIATION_LIMIT,
            $fund->equityBand(SovereignFundSubsystem::GPIF_GLOBAL_EQUITY_TARGET),
            1e-15
        );
    }

    public function testAGlobalCrashTripsTheEquityBandAndBuysTheBoard(): void
    {
        $tpy = 360;
        $dt = 1.0 / $tpy;
        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, $tpy);
        $targetEquity = $fund->policyEquityShare($state->sovereignFundTargetWeight);

        // Every equity market falls 35% together: the board barely moves against the rest of the fund, so the domestic
        // band alone would stay quiet, but the fund's equity share falls through its own band.
        $this->crashDomestic($state, 0.35);
        $state->sovereignFundForeignEquity *= 0.65;
        $this->runToMonthEnd($fund, $state, $dt);

        $this->assertSame(SovereignFundSubsystem::REBALANCE_EXECUTION_MONTHS, $state->sovereignFundRebalanceMonthsLeft);
        $this->assertSame($state->totalTime, $state->lastSovereignRebalanceAt);
        $this->assertGreaterThan(0.0, $state->sovereignFundTrade, 'It buys the board back toward its policy weight.');

        // The foreign sleeves are back on their 65/35 split at once.
        $foreignEquity = $fund->foreignEquityHomeValue($state);
        $this->assertEqualsWithDelta(SovereignFundSubsystem::FOREIGN_EQUITY_SHARE, $foreignEquity / $fund->foreignHomeValue($state), 1e-12);
        $this->assertGreaterThan($targetEquity - 0.02, $state->sovereignFundEquityShare, 'Equities topped back up from paper.');
    }

    public function testAThirtyFivePercentFallInTheBoardAloneDoesNotTripTheEquityBand(): void
    {
        $fund = new SovereignFundSubsystem(new MathUtility());
        $target = $fund->policyEquityShare(0.035);

        // The board is a few percent of the fund, so even a deep District-only fall moves the equity share by a point.
        $this->assertLessThan($fund->equityBand($target), 0.035 * 0.35);
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

    public function testAForeignReRatingMovesTheForeignEquitySleeveAndNothingElse(): void
    {
        $tpy = 720;
        $dt = 1.0 / $tpy;
        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, $tpy);
        $state->sovereignFundAnnualDraw = 0.0;
        $equity = $fund->foreignEquityHomeValue($state);
        $paper = $fund->foreignHomeValue($state) - $equity;
        $index = $state->foreignEquityIndex;

        $state->foreignEquityValuationChange = -0.05;
        $this->step($fund, $state, $dt);

        $this->assertEqualsWithDelta($index * exp(-0.05), $state->foreignEquityIndex, 1e-9);
        $this->assertEqualsWithDelta($equity * exp(-0.05), $fund->foreignEquityHomeValue($state), 1e-3);
        $this->assertEqualsWithDelta($paper, $fund->foreignHomeValue($state) - $fund->foreignEquityHomeValue($state), 1e-3);
    }

    public function testStampDutyIsPaidIntoTheFundAndReportedForTheYear(): void
    {
        $tpy = 360;
        $dt = 1.0 / $tpy;
        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, $tpy);
        $state->sovereignFundAnnualDraw = 0.0;
        $before = $fund->fundValue($state);

        $state->boardStampDuty = 1.0e6;
        $this->step($fund, $state, $dt);
        $this->assertEqualsWithDelta($before + 1.0e6, $fund->fundValue($state), 1e-3, 'Paid in as cash.');
        $this->assertEqualsWithDelta(1.0e6, $state->sovereignFundStampDutyYearToDate, 1e-9);

        // A steady flow for the rest of the year, then the year is closed and published over GDP.
        $perTick = 2.0e6;
        while ($state->totalTime < 1.0 - (1.5 * $dt)) {
            $state->boardStampDuty = $perTick;
            $this->step($fund, $state, $dt);
        }
        $yearToDate = $state->sovereignFundStampDutyYearToDate;
        $state->boardStampDuty = $perTick;
        $this->step($fund, $state, $dt);

        $gdpDollars = $state->sovereignFundDollarsPerGdp * $state->nominalGdpIndex;
        $this->assertEqualsWithDelta(($yearToDate + $perTick) / $gdpDollars, $state->sovereignFundStampDutyToGdp, 1e-15);
        $this->assertSame(0.0, $state->sovereignFundStampDutyYearToDate, 'The next year starts from nothing.');
    }

    public function testTheFundTendersIntoBuybacksAndKeepsItsOwnership(): void
    {
        $tpy = 720;
        $dt = 1.0 / $tpy;
        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, $tpy);
        $state->sovereignFundAnnualDraw = 0.0;
        $ownership = $state->sovereignFundOwnershipShare;
        $foreignBefore = $fund->foreignHomeValue($state);

        // The companies retire 2% of the float this tick: the float shrinks, the fund tenders its share for cash.
        $buyback = -0.02 * $state->boardFloatCap;
        $state->boardFloatCap += $buyback;
        $state->boardNetIssuance = $buyback;
        $this->step($fund, $state, $dt);

        $this->assertEqualsWithDelta($ownership, $state->sovereignFundOwnershipShare, 1e-12, 'Ownership is untouched by the companies\' own flow.');
        $this->assertEqualsWithDelta(-$ownership * $buyback, $fund->foreignHomeValue($state) - $foreignBefore, 1e-3, 'Tendered for cash.');
    }

    public function testTheFundTakesUpItsShareOfAnIssue(): void
    {
        $tpy = 720;
        $dt = 1.0 / $tpy;
        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, $tpy);
        $state->sovereignFundAnnualDraw = 0.0;
        $ownership = $state->sovereignFundOwnershipShare;
        $before = $fund->fundValue($state);

        $issue = 0.01 * $state->boardFloatCap;
        $state->boardFloatCap += $issue;
        $state->boardNetIssuance = $issue;
        $this->step($fund, $state, $dt);

        $this->assertEqualsWithDelta($ownership, $state->sovereignFundOwnershipShare, 1e-12);
        $this->assertEqualsWithDelta($before, $fund->fundValue($state), 1e-3, 'Paid for out of the paper sleeve, not handed over.');
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

    /** The District's strategic stake is not the fund's, so its dividends arrive whole, as cash, and touch no board holding. */
    public function testStrategicDividendsArriveWholeAsCashWithoutChangingTheBoardHolding(): void
    {
        $tpy = 720;
        $dt = 1.0 / $tpy;
        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, $tpy);
        $state->sovereignFundAnnualDraw = 0.0;

        $before = $fund->foreignHomeValue($state);
        $domestic = $state->sovereignFundDomesticEquity;
        $ownership = $state->sovereignFundOwnershipShare;
        $state->strategicStakeCash = 2.5e8;
        $this->step($fund, $state, $dt);

        $this->assertEqualsWithDelta(2.5e8, $fund->foreignHomeValue($state) - $before, 1e-3);
        $this->assertSame($domestic, $state->sovereignFundDomesticEquity);
        $this->assertSame($ownership, $state->sovereignFundOwnershipShare);
    }

    /** A budget surplus the debt floor leaves nothing to retire lands in the paper sleeve once, and is not performance. */
    public function testABudgetSurplusIsPaidIntoThePaperOnceAndIsNotPerformance(): void
    {
        $tpy = 720;
        $dt = 1.0 / $tpy;
        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, $tpy);
        $state->sovereignFundAnnualDraw = 0.0;

        $before = $fund->foreignHomeValue($state);
        $equity = $state->sovereignFundForeignEquity;
        $state->sovereignFundBudgetInflow = 3.0e8;
        $this->step($fund, $state, $dt);
        $this->step($fund, $state, $dt);

        $this->assertEqualsWithDelta(3.0e8, $fund->foreignHomeValue($state) - $before, 1e-3, 'Paid in once, not every tick.');
        $this->assertSame($equity, $state->sovereignFundForeignEquity, 'Cash parks in the paper.');
        $this->assertSame(0.0, $state->sovereignFundBudgetInflow);
        $this->assertEqualsWithDelta(SovereignFundSubsystem::RETURN_INDEX_BASE, $state->sovereignFundReturnIndex, 1e-9);
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

    /** Money paid in and the budget's draw are not performance: with every market still, the nominal index does not move. */
    public function testTheReturnIndexIgnoresTheMoneyPaidInAndDrawnOut(): void
    {
        $tpy = 720;
        $dt = 1.0 / $tpy;
        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, $tpy);
        $state->inflation = 0.03;
        $before = $fund->fundValue($state);

        $ticks = 30;
        for ($i = 0; $i < $ticks; ++$i) {
            $state->boardStampDuty = 4.0e8;
            $state->strategicStakeCash = 1.0e8;
            $this->step($fund, $state, $dt);
        }

        $this->assertGreaterThan($before + 1.0e9, $fund->fundValue($state), 'Money did arrive, and more than the draw took out.');
        $this->assertEqualsWithDelta(SovereignFundSubsystem::RETURN_INDEX_BASE, $state->sovereignFundReturnIndex, 1e-9);
        $this->assertEqualsWithDelta(
            SovereignFundSubsystem::RETURN_INDEX_BASE * exp(-0.03 * $ticks * $dt),
            $state->sovereignFundRealReturnIndex,
            1e-9,
            'The real index loses the District\'s inflation.'
        );
    }

    public function testTheReturnIndexEarnsTheSleevesReturnAtTheirWeights(): void
    {
        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, 720);
        $domesticWeight = $state->sovereignFundDomesticWeight;

        $state->boardPriceReturn = -0.10;
        $this->step($fund, $state, 1.0 / 720.0);

        $this->assertEqualsWithDelta(
            SovereignFundSubsystem::RETURN_INDEX_BASE * (1.0 - (0.10 * $domesticWeight)),
            $state->sovereignFundReturnIndex,
            1e-9
        );
    }

    public function testARebalanceIsNotPerformance(): void
    {
        $tpy = 360;
        $dt = 1.0 / $tpy;
        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, $tpy);
        $this->crashDomestic($state, 0.35);
        $this->runToMonthEnd($fund, $state, $dt);
        $index = $state->sovereignFundReturnIndex;
        $this->assertLessThan(SovereignFundSubsystem::RETURN_INDEX_BASE, $index, 'The crash is performance.');

        for ($i = 0; $i < 10; ++$i) {
            $this->step($fund, $state, $dt);
            $this->assertNotSame(0.0, $state->sovereignFundTrade);
        }

        $this->assertEqualsWithDelta($index, $state->sovereignFundReturnIndex, 1e-9, 'Buying the board with paper at market is not.');
    }

    /** A fund incepted before the index existed opens it at the base on the first tick it is seen, booking nothing for it. */
    public function testAFundWithNoIndexOpensItAtTheBase(): void
    {
        $dt = 1.0 / 720.0;
        $fund = new SovereignFundSubsystem($this->stillMarket());
        $state = $this->openFund($fund, 720);
        $state->sovereignFundReturnIndex = 0.0;
        $state->sovereignFundRealReturnIndex = 0.0;
        $state->sovereignFundValueAtClose = 0.0;

        $state->boardPriceReturn = -0.10;
        $this->step($fund, $state, $dt);
        $this->assertSame(SovereignFundSubsystem::RETURN_INDEX_BASE, $state->sovereignFundReturnIndex);
        $this->assertSame(SovereignFundSubsystem::RETURN_INDEX_BASE, $state->sovereignFundRealReturnIndex);

        $state->boardPriceReturn = -0.10;
        $this->step($fund, $state, $dt);
        $this->assertLessThan(SovereignFundSubsystem::RETURN_INDEX_BASE, $state->sovereignFundReturnIndex, 'From the next tick it chains.');
    }

    public function testTheForeignCurveIsTheRatesPathBackToNeutralPlusTheTermPremium(): void
    {
        $fund = new SovereignFundSubsystem(new MathUtility());
        $state = new MacroState();
        $tau = SovereignFundSubsystem::FOREIGN_BOND_DURATION;
        $neutral = MacroEngine::GLOBAL_BASELINE_RATE;
        $premium = MacroEngine::NS_BASE_TERM_PREMIUM * MathUtility::calculateTermPremiumDurationScale($tau, MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS);

        $state->foreignPolicyRate = $neutral;
        $this->assertEqualsWithDelta($neutral + $premium, $fund->foreignZeroYield($state, $tau), 1e-14, 'At neutral the curve is the level plus the premium.');

        // A cut is expected to unwind at the slope decay, so the long yield takes the Vasicek loading of it, not all of it.
        $state->foreignPolicyRate = $neutral - 0.01;
        $lambda = MacroEngine::SVENSSON_SLOPE_LAMBDA;
        $loading = (1.0 - exp(-$lambda * $tau)) / ($lambda * $tau);
        $this->assertEqualsWithDelta($neutral - (0.01 * $loading) + $premium, $fund->foreignZeroYield($state, $tau), 1e-14);
    }

    /** A rise in the foreign rate marks the paper down by its duration times the move in its own yield. */
    public function testARiseInTheForeignRateMarksThePaperDownByItsDuration(): void
    {
        $dt = 1.0 / 720.0;
        $fund = new SovereignFundSubsystem($this->stillMarket(flatCurve: false));
        $state = $this->openFund($fund, 720, MacroEngine::GLOBAL_BASELINE_RATE);
        $state->sovereignFundAnnualDraw = 0.0;
        $yieldBefore = $state->foreignBondYield;
        $paperBefore = $state->sovereignFundForeignBonds;

        $state->foreignPolicyRate = MacroEngine::GLOBAL_BASELINE_RATE + 0.01;
        $this->step($fund, $state, $dt);

        $yieldMove = $state->foreignBondYield - $yieldBefore;
        $tau = SovereignFundSubsystem::FOREIGN_BOND_DURATION;
        $lambda = MacroEngine::SVENSSON_SLOPE_LAMBDA;
        $this->assertEqualsWithDelta(0.01 * (1.0 - exp(-$lambda * $tau)) / ($lambda * $tau), $yieldMove, 1e-14);

        // Duration and convexity, with a tick of carry: about -2.8% on a 45bp move at a duration of six years.
        $expected = (-$tau * $yieldMove) + (0.5 * $tau * $tau * $yieldMove * $yieldMove);
        $this->assertEqualsWithDelta($expected, ($state->sovereignFundForeignBonds / $paperBefore) - 1.0, 2e-4);
    }

    /**
     * On a curve that does not move, a constant-maturity zero earns the forward rate at its maturity, the yield plus its
     * roll-down, and the same over a year whether the year is 72 ticks or 7,200.
     */
    public function testOnASteadyCurveThePaperEarnsTheForwardRateAtAnyTickRate(): void
    {
        $expected = MacroEngine::GLOBAL_BASELINE_RATE + SovereignFundSubsystem::foreignBondExpectedPremium();

        foreach ([72, 720, 7200] as $tpy) {
            $growth = $this->paperLogGrowthOverAYear($tpy, static fn (float $time): float => MacroEngine::GLOBAL_BASELINE_RATE);
            $this->assertEqualsWithDelta($expected, $growth, 1e-5, "tpy {$tpy}: the paper earns the forward rate, not the yield.");
        }
    }

    public function testThePaperOverAMovingRateIsTheSameAtAnyTickRate(): void
    {
        // A year of easing from neutral to 150bp below it.
        $path = static fn (float $time): float => MacroEngine::GLOBAL_BASELINE_RATE - (0.015 * min(1.0, $time));

        $reference = $this->paperLogGrowthOverAYear(7200, $path);
        foreach ([72, 720] as $tpy) {
            $this->assertEqualsWithDelta($reference, $this->paperLogGrowthOverAYear($tpy, $path), 1e-4, "tpy {$tpy}");
        }
        $this->assertGreaterThan(
            MacroEngine::GLOBAL_BASELINE_RATE + SovereignFundSubsystem::foreignBondExpectedPremium(),
            $reference,
            'Paper rallies while the foreign rate falls.'
        );
    }

    /**
     * One year of the paper sleeve's own return, in log terms, on a scripted foreign rate path. Every other market stands
     * still and the draw is off; a draw or a rebalance would take both foreign sleeves down by the same factor, so the
     * paper's growth is read against the equity sleeve's, which the still market leaves untouched.
     *
     * @param callable(float): float $foreignRate The foreign policy rate at a time since inception.
     */
    private function paperLogGrowthOverAYear(int $tpy, callable $foreignRate): float
    {
        $dt = 1.0 / $tpy;
        $fund = new SovereignFundSubsystem($this->stillMarket(flatCurve: false));
        $state = $this->openFund($fund, $tpy, $foreignRate(0.0));
        $paper = $state->sovereignFundForeignBonds;
        $equity = $state->sovereignFundForeignEquity;

        // The path is read by tick count from the inception tick, so every tick rate walks the same year of it.
        for ($i = 1; $i <= $tpy; ++$i) {
            $state->sovereignFundAnnualDraw = 0.0;
            $state->foreignPolicyRate = $foreignRate($i * $dt);
            $this->step($fund, $state, $dt);
        }

        return log(($state->sovereignFundForeignBonds / $paper) / ($state->sovereignFundForeignEquity / $equity));
    }

    /**
     * The engine's opening economy with a board reported, advanced by one tick so the fund incepts.
     */
    private function openFund(SovereignFundSubsystem $fund, int $tpy, float $foreignPolicyRate = 0.0): MacroState
    {
        $dt = 1.0 / $tpy;
        $state = new MacroState();
        $state->boardFloatCap = self::BOARD_FLOAT_CAP;
        $state->equityMarketCap = self::BOARD_TOTAL_CAP;
        $state->foreignPolicyRate = $foreignPolicyRate;
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
        $state->boardNetIssuance = 0.0;
        $state->foreignEquityValuationChange = 0.0;
        $state->boardStampDuty = 0.0;
        $state->strategicStakeCash = 0.0;
    }

    /** Steps until the tick that crosses the next month end, inclusive. */
    private function runToMonthEnd(SovereignFundSubsystem $fund, MacroState $state, float $dt): void
    {
        do {
            $this->step($fund, $state, $dt);
        } while (!MathUtility::crossedSimulatedBoundary($state->totalTime, $dt, SovereignFundSubsystem::REBALANCE_CHECK_PERIOD_YEARS));
    }

    /**
     * A foreign market that stands still, so the rules can be read without a random walk under them: the equity index
     * does not move, and the foreign curve is flat at zero (the expectations leg cancels the term premium the fund adds
     * on top), so the paper neither carries nor reprices. With $flatCurve off the equities still stand still but the
     * paper is marked on the real foreign curve.
     */
    private function stillMarket(bool $flatCurve = true): MathUtility
    {
        return new class($flatCurve) extends MathUtility {
            public function __construct(private readonly bool $flatCurve)
            {
            }

            public function generateStandardNormal(): float
            {
                return 0.0;
            }

            public function calculateSvenssonYield(
                float $level,
                float $slope,
                float $curvature1,
                float $curvature2,
                float $tau,
                float $lambda1 = 0.5,
                float $lambda2 = 0.15,
                ?float $slopeLambda = null
            ): float {
                if (!$this->flatCurve) {
                    return parent::calculateSvenssonYield($level, $slope, $curvature1, $curvature2, $tau, $lambda1, $lambda2, $slopeLambda);
                }

                return -MacroEngine::NS_BASE_TERM_PREMIUM
                    * self::calculateTermPremiumDurationScale($tau, MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS);
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
