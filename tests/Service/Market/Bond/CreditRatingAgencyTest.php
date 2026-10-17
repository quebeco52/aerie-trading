<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Bond;

use App\Entity\Stock;
use App\Service\Market\Bond\CreditRatingAgency;
use PHPUnit\Framework\TestCase;

class CreditRatingAgencyTest extends TestCase
{
    private CreditRatingAgency $agency;

    protected function setUp(): void
    {
        $this->agency = new CreditRatingAgency();
    }

    /**
     * The zone caps carry the grade brackets' hysteresis: a BBB firm is not capped at BB until its Z'' is past the 2.60
     * edge by the buffer, and a firm held at BB is not released until it clears the edge by as much, so a score
     * sitting on the line no longer caps and releases the rating on alternate quarters.
     */
    public function testAltmanZoneCapsBindAndLiftPastABuffer(): void
    {
        $edge = CreditRatingAgency::ALTMAN_GREY_THRESHOLD;
        $buffer = CreditRatingAgency::ALTMAN_ZONE_HYSTERESIS;

        $this->assertSame('Safe', CreditRatingAgency::altmanZoneWithHysteresis($edge - (0.5 * $buffer), 'BBB'), 'just under the edge does not yet cap a BBB');
        $this->assertSame('Grey', CreditRatingAgency::altmanZoneWithHysteresis($edge - (1.5 * $buffer), 'BBB'));
        $this->assertSame('Grey', CreditRatingAgency::altmanZoneWithHysteresis($edge + (0.5 * $buffer), 'BB'), 'just over the edge does not yet release a BB');
        $this->assertSame('Safe', CreditRatingAgency::altmanZoneWithHysteresis($edge + (1.5 * $buffer), 'BB'));

        $distress = CreditRatingAgency::ALTMAN_DISTRESS_THRESHOLD;
        $this->assertSame('Grey', CreditRatingAgency::altmanZoneWithHysteresis($distress - (0.5 * $buffer), 'BB'));
        $this->assertSame('Distress', CreditRatingAgency::altmanZoneWithHysteresis($distress + (0.5 * $buffer), 'CCC'));
    }

    public function testConvertDistanceToRatingBrackets(): void
    {
        $this->assertSame('AAA', $this->agency->convertDistanceToRating(4.0));
        $this->assertSame('AAA', $this->agency->convertDistanceToRating(3.5, 'AAA'));
        $this->assertSame('AA', $this->agency->convertDistanceToRating(3.2));
        $this->assertSame('A', $this->agency->convertDistanceToRating(2.7));
        $this->assertSame('BBB', $this->agency->convertDistanceToRating(2.1));
        $this->assertSame('BB', $this->agency->convertDistanceToRating(1.8));
        $this->assertSame('B', $this->agency->convertDistanceToRating(1.2));
        $this->assertSame('B', $this->agency->convertDistanceToRating(0.6), 'the market-implied ladder floors at B: CCC needs the accounts');
        // Floored at B. This ladder reads a market-implied distance to default; however far the equity falls, a
        // price is neither a missed payment nor an unsustainable capital structure (S&P's CCC criteria).
        $this->assertSame('B', $this->agency->convertDistanceToRating(0.2));
        $this->assertSame('B', $this->agency->convertDistanceToRating(-1.0));
    }

    public function testAMarketImpliedCollapseCannotProduceD(): void
    {
        $stock = new Stock();
        $stock->setTicker('CRSH');
        $stock->setCreditRating('BBB');
        $stock->setTotalEquity('50000000000');

        // A catastrophic distance to default and a distressed Altman score, but the firm is current on
        // everything it owes. Letting this reach D shut the primary bond market on a drawdown alone.
        $this->agency->evaluateRating($stock, -6.0, 0.5);

        $this->assertNotSame('D', $stock->getCreditRating());
    }

    public function testAPaymentDefaultAssignsRatingD(): void
    {
        $stock = new Stock();
        $stock->setTicker('MISS');
        $stock->setCreditRating('BBB');
        $stock->setTotalEquity('50000000000');
        $stock->setPaymentDefault(true);

        $this->assertSame('D', $this->agency->evaluateRating($stock, 4.0, 9.0));
        $this->assertSame('D', $stock->getCreditRating());
    }

    public function testACuredDefaultLeavesRatingD(): void
    {
        $stock = new Stock();
        $stock->setTicker('CURE');
        $stock->setCreditRating('D');
        $stock->setTotalEquity('50000000000');
        $stock->setPaymentDefault(false);

        $this->assertNotSame('D', $this->agency->evaluateRating($stock, 4.0, 9.0));
    }

    public function testEvaluateRatingUpdatesStockWhenRatingChanges(): void
    {
        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setCreditRating('BBB');
        $stock->setTotalEquity('100000000');

        // High Distance to Default -> Upgrade is clamped to 1 notch per evaluation ('A')
        $transition = $this->agency->evaluateRating($stock, 3.8);

        $this->assertSame('A', $transition);
        $this->assertSame('A', $stock->getCreditRating());
        $this->assertFalse($this->agency->isDowngrade('BBB', 'A'));

        // Next evaluation continues climbing to AA
        $transitionNext = $this->agency->evaluateRating($stock, 3.8);
        $this->assertSame('AA', $transitionNext);
        $this->assertSame('AA', $stock->getCreditRating());
    }

    public function testEvaluateRatingReturnsNullWhenNoChange(): void
    {
        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setCreditRating('BBB');
        $stock->setTotalEquity('100000000');

        // Distance to Default in BBB bracket (2.0 <= d2 < 2.5) -> No change
        $transition = $this->agency->evaluateRating($stock, 2.3);

        $this->assertNull($transition);
        $this->assertSame('BBB', $stock->getCreditRating());
    }

    public function testIsDowngradeDetection(): void
    {
        $this->assertTrue($this->agency->isDowngrade('BBB', 'BB'));
        $this->assertTrue($this->agency->isDowngrade('AA', 'CCC'));
        $this->assertFalse($this->agency->isDowngrade('BB', 'BBB'));
        $this->assertFalse($this->agency->isDowngrade('A', 'AAA'));
    }

    public function testAltmanDistressCapsRatingAtCCCAndFastTracksDowngrade(): void
    {
        $stock = new Stock();
        $stock->setTicker('FAIL');
        $stock->setCreditRating('AAA');
        $stock->setTotalEquity('50000000');

        // High d2 from low debt (4.0), but Altman Z is in severe distress (0.8 < 1.10)
        $transition = $this->agency->evaluateRating($stock, 4.0, 0.8);

        // Should immediately fast-track downgrade to CCC without being blocked at AA
        $this->assertSame('CCC', $transition);
        $this->assertSame('CCC', $stock->getCreditRating());
    }

    public function testAltmanInsolventCapsRatingAtCcc(): void
    {
        $stock = new Stock();
        $stock->setTicker('DEAD');
        $stock->setCreditRating('AA');
        $stock->setTotalEquity('10000000');

        // Altman Z < 0 indicates insolvency — but an insolvent obligor that is still paying has not
        // defaulted, and FailureSweep liquidates on this score in any case.
        $transition = $this->agency->evaluateRating($stock, 3.8, -1.5);

        $this->assertSame('CCC', $transition);
        $this->assertSame('CCC', $stock->getCreditRating());
    }

    public function testAltmanGreyZoneCapsRatingAtBB(): void
    {
        $stock = new Stock();
        $stock->setTicker('GREY');
        $stock->setCreditRating('BBB');
        $stock->setTotalEquity('100000000');

        // Merton suggests AAA (d2 = 4.0), but Altman Z is in Grey Zone (1.8)
        $transition = $this->agency->evaluateRating($stock, 4.0, 1.8);

        // Clamped 1-notch transition from BBB -> BB (since target is capped at BB)
        $this->assertSame('BB', $transition);
        $this->assertSame('BB', $stock->getCreditRating());
    }

    public function testNegativeEquityCapsRatingAtCCC(): void
    {
        $stock = new Stock();
        $stock->setTicker('NEGEQ');
        $stock->setCreditRating('A');
        $stock->setTotalEquity('-10000000');

        // Negative equity triggers immediate cap and emergency downgrade
        $transition = $this->agency->evaluateRating($stock, 4.0, 3.0);

        $this->assertSame('CCC', $transition);
        $this->assertSame('CCC', $stock->getCreditRating());
    }

    public function testBankruptStockImmediatelyReceivesRatingD(): void
    {
        $stock = new Stock();
        $stock->setTicker('BK');
        $stock->setCreditRating('AAA');
        $stock->setIsBankrupt(true);

        $transition = $this->agency->evaluateRating($stock, 5.0, 5.0);

        $this->assertSame('D', $transition);
        $this->assertSame('D', $stock->getCreditRating());
    }

    /**
     * A financial's ALTERNATIVE score is a capital ratio in percentage points, not a 1968 Z''-score, so the
     * numeric brackets read it on the wrong scale. The zone the firm's own model assigns is authoritative:
     * a bank three points from statutory failure is in distress however comfortably 3.7 clears a grey zone
     * of 2.60, and a clearinghouse whose ratio is healthy is not distressed merely because the number is low.
     */
    public function testTheModelsOwnZoneOverridesTheNumericAltmanBrackets(): void
    {
        $bank = new Stock();
        $bank->setTicker('BNK');
        $bank->setCreditRating('AAA');
        $bank->setTotalEquity('50000000000');

        // 3.68 sits above the 2.60 grey bound, so the numeric path would apply no cap at all.
        $this->assertNull($this->agency->evaluateRating($bank, 4.0, 3.68));

        $bank->setCreditRating('AAA');
        $this->assertSame('CCC', $this->agency->evaluateRating($bank, 4.0, 3.68, 'Distress'));

        $ccp = new Stock();
        $ccp->setTicker('ACC');
        $ccp->setCreditRating('BBB');
        $ccp->setTotalEquity('50000000000');

        // 2.40 is inside the numeric grey band, but the CCP's own thresholds call that ratio safe.
        $this->assertSame('BB', $this->agency->evaluateRating($ccp, 4.0, 2.40));

        $ccp->setCreditRating('BBB');
        $zoned = $this->agency->evaluateRating($ccp, 4.0, 2.40, 'Safe');
        $this->assertNotNull($zoned);
        $this->assertFalse(
            $this->agency->isDowngrade('BBB', $zoned),
            'a capital ratio the CCP\'s own model calls safe may not cap its rating',
        );
    }

    /** With no zone supplied the numeric brackets still govern, so operating firms are untouched. */
    public function testAnOperatingFirmRatesIdenticallyWithOrWithoutItsZone(): void
    {
        foreach ([[0.8, 'Distress'], [1.8, 'Grey'], [4.0, 'Safe']] as [$zScore, $zone]) {
            $numeric = new Stock();
            $numeric->setTicker('NUM');
            $numeric->setCreditRating('BBB');
            $numeric->setTotalEquity('100000000');

            $zoned = new Stock();
            $zoned->setTicker('ZON');
            $zoned->setCreditRating('BBB');
            $zoned->setTotalEquity('100000000');

            $this->assertSame(
                $this->agency->evaluateRating($numeric, 4.0, $zScore),
                $this->agency->evaluateRating($zoned, 4.0, $zScore, $zone),
                'the standard path zones on the same two numbers the brackets use',
            );
        }
    }

    /** S&P's matrix: a satisfactory business at minimal to highly leveraged financial risk, by net debt to EBITDA. */
    public function testTheLeverageAnchorFollowsTheFinancialRiskBands(): void
    {
        $cases = [[0.0, 'A'], [1.4, 'A'], [1.5, 'BBB'], [2.9, 'BBB'], [3.0, 'BB'], [4.9, 'BB'], [5.0, 'B'], [9.0, 'B']];
        foreach ($cases as [$leverage, $expected]) {
            $this->assertSame($expected, CreditRatingAgency::financialRiskAnchor($leverage * 100.0, 100.0, false), "{$leverage}x");
        }

        // A rate-regulated utility carries an excellent business risk profile: significant leverage still anchors at A.
        $this->assertSame('A', CreditRatingAgency::financialRiskAnchor(350.0, 100.0, true));
        $this->assertSame('BBB', CreditRatingAgency::financialRiskAnchor(450.0, 100.0, true));
        $this->assertSame('AAA', CreditRatingAgency::financialRiskAnchor(0.0, 100.0, true));

        // Debt with no EBITDA is highly leveraged; no debt is minimal whatever the EBITDA.
        $this->assertSame('B', CreditRatingAgency::financialRiskAnchor(100.0, -5.0, false));
        $this->assertSame('A', CreditRatingAgency::financialRiskAnchor(0.0, -5.0, false));
    }

    /**
     * S&P's time-weighted core ratio: the current year, the year before and the one before that at 25/15/10, read a
     * year (four quarterly reviews) apart; the quarters between do not enter, and with fewer years observed the weights
     * of those that are keep their proportions.
     */
    public function testTimeWeightedLeverageWeighsTheObservedYearsAtSAndPWeights(): void
    {
        $quarters = [];
        for ($q = 0; $q < CreditRatingAgency::timeWeightedLeverageQuarters(); $q++) {
            $quarters[] = ['net_debt' => 100.0 + $q, 'ebitda' => 10.0 * ($q + 1)];
        }
        $this->assertCount(9, $quarters);

        $full = CreditRatingAgency::timeWeightedLeverage($quarters);
        $this->assertEqualsWithDelta((0.25 * 100.0 + 0.15 * 104.0 + 0.10 * 108.0) / 0.50, $full['net_debt'], 1e-9);
        $this->assertEqualsWithDelta((0.25 * 10.0 + 0.15 * 50.0 + 0.10 * 90.0) / 0.50, $full['ebitda'], 1e-9);

        $twoYears = CreditRatingAgency::timeWeightedLeverage(array_slice($quarters, 0, 8));
        $this->assertEqualsWithDelta((0.25 * 10.0 + 0.15 * 50.0) / 0.40, $twoYears['ebitda'], 1e-9, 'the third year is not observed yet');

        $this->assertSame(['net_debt' => 100.0, 'ebitda' => 10.0], CreditRatingAgency::timeWeightedLeverage(array_slice($quarters, 0, 4)), 'one year: its own figures');
        $this->assertSame(['net_debt' => 0.0, 'ebitda' => 0.0], CreditRatingAgency::timeWeightedLeverage([]));
    }

    /**
     * The base case for S&P's two forecast years weighs 50%, against 25/15/10 for the observed years, and keeps its
     * share when fewer years are observed.
     */
    public function testTheForecastYearsWeighHalfOfTheTimeWeightedBasis(): void
    {
        $year = static fn(float $netDebt, float $ebitda): array => ['net_debt' => $netDebt, 'ebitda' => $ebitda];
        $quarters = [$year(300.0, 60.0), $year(0.0, 0.0), $year(0.0, 0.0), $year(0.0, 0.0), $year(200.0, 80.0), $year(0.0, 0.0), $year(0.0, 0.0), $year(0.0, 0.0), $year(100.0, 100.0)];
        $baseCase = $year(300.0, 120.0);

        $full = CreditRatingAgency::timeWeightedLeverage($quarters, $baseCase);
        $this->assertEqualsWithDelta(0.50 * 300.0 + 0.25 * 300.0 + 0.15 * 200.0 + 0.10 * 100.0, $full['net_debt'], 1e-9);
        $this->assertEqualsWithDelta(0.50 * 120.0 + 0.25 * 60.0 + 0.15 * 80.0 + 0.10 * 100.0, $full['ebitda'], 1e-9);

        $firstYear = CreditRatingAgency::timeWeightedLeverage([$year(300.0, 60.0)], $baseCase);
        $this->assertEqualsWithDelta((0.50 * 120.0 + 0.25 * 60.0) / 0.75, $firstYear['ebitda'], 1e-9, 'two thirds base case, one third this year');
        $this->assertSame($baseCase, CreditRatingAgency::timeWeightedLeverage([], $baseCase));
    }

    /**
     * A loss year leaves the weighted basis defined: two good years outweigh one with no EBITDA, which alone reads
     * highly leveraged.
     */
    public function testATimeWeightedLossYearIsNotHighlyLeveragedOnItsOwn(): void
    {
        $good = ['net_debt' => 200.0, 'ebitda' => 100.0];
        $quarters = [['net_debt' => 200.0, 'ebitda' => -10.0], $good, $good, $good, $good, $good, $good, $good, $good];

        $this->assertSame('B', CreditRatingAgency::financialRiskAnchor(200.0, -10.0, false));
        $basis = CreditRatingAgency::timeWeightedLeverage($quarters);
        $this->assertEqualsWithDelta(200.0 / 45.0, $basis['net_debt'] / $basis['ebitda'], 1e-9);
        $this->assertSame('BB', CreditRatingAgency::financialRiskAnchor($basis['net_debt'], $basis['ebitda'], false));
    }

    /**
     * S&P's medial volatility table, on which a regulated utility is read: its bands sit 0.25x-0.5x wider than the
     * standard table's, so 4.2x is still significant (A on the excellent row) where the standard table calls it
     * aggressive.
     */
    public function testTheMedialVolatilityTableWidensTheBands(): void
    {
        $medial = CreditRatingAgency::MEDIAL_VOLATILITY_DEBT_TO_EBITDA_BOUNDS;
        $cases = [[1.7, 'AAA'], [1.8, 'AA'], [2.4, 'AA'], [2.5, 'A'], [3.4, 'A'], [4.4, 'A'], [4.5, 'BBB'], [5.4, 'BBB'], [5.5, 'BB'], [8.0, 'BB']];
        foreach ($cases as [$leverage, $expected]) {
            $this->assertSame($expected, CreditRatingAgency::financialRiskAnchor($leverage * 100.0, 100.0, true, $medial), "{$leverage}x");
        }
        $this->assertSame('BBB', CreditRatingAgency::financialRiskAnchor(420.0, 100.0, true));
        $this->assertSame('A', CreditRatingAgency::financialRiskAnchor(420.0, 100.0, true, $medial));
    }

    /**
     * The market-implied grade moves only past the bracket edge by the buffer, either way: Altman & Rijken's (2004)
     * prudent-migration threshold. A d2 inside the buffer holds the grade from both sides.
     */
    public function testTheDistanceToDefaultHysteresisHoldsInsideTheBuffer(): void
    {
        $edge = CreditRatingAgency::THRESHOLD_A;
        $inside = CreditRatingAgency::HYSTERESIS_BUFFER * 0.5;

        $this->assertSame('A', $this->agency->convertDistanceToRating($edge - $inside, 'A'), 'an A holds just under its edge');
        $this->assertSame('BBB', $this->agency->convertDistanceToRating($edge + $inside, 'BBB'), 'a BBB is not upgraded just over it');
        $this->assertSame('BBB', $this->agency->convertDistanceToRating($edge - CreditRatingAgency::HYSTERESIS_BUFFER - 0.01, 'A'));
        $this->assertSame('A', $this->agency->convertDistanceToRating($edge + CreditRatingAgency::HYSTERESIS_BUFFER + 0.01, 'BBB'));
    }

    /** The anchor caps a high distance to default, and the cap is reached a notch per evaluation like any move. */
    public function testTheLeverageAnchorCapsAMarketImpliedAaa(): void
    {
        $stock = new Stock();
        $stock->setTicker('LEVR');
        $stock->setCreditRating('AAA');
        $stock->setTotalEquity('100000000');

        $this->assertSame('AA', $this->agency->evaluateRating($stock, 8.0, 4.0, 'Safe', 'BBB'));
        $this->assertSame('A', $this->agency->evaluateRating($stock, 8.0, 4.0, 'Safe', 'BBB'));
        $this->assertSame('BBB', $this->agency->evaluateRating($stock, 8.0, 4.0, 'Safe', 'BBB'));
        $this->assertNull($this->agency->evaluateRating($stock, 8.0, 4.0, 'Safe', 'BBB'));

        // The market-implied grade still lowers it below the anchor.
        $this->assertSame('BB', $this->agency->evaluateRating($stock, 1.7, 4.0, 'Safe', 'BBB'));
    }

    /**
     * A crash alone takes a firm no lower than B; CCC is for an unsustainable capital structure, which the Altman
     * distress zone or negative equity reads (S&P's CCC criteria).
     */
    public function testOnlyTheAccountsTakeAFirmToCcc(): void
    {
        $stock = new Stock();
        $stock->setTicker('CRSB');
        $stock->setCreditRating('B');
        $stock->setTotalEquity('50000000000');

        $this->assertNull($this->agency->evaluateRating($stock, -2.0, 3.0, 'Safe'));
        $this->assertSame('B', $stock->getCreditRating());
        $this->assertSame('CCC', $this->agency->evaluateRating($stock, -2.0, 0.5, 'Distress'));
    }

    /**
     * The sector tables: a REIT is read on S&P's real estate debt/EBITDA bands, so 6x is intermediate (BBB) where
     * the corporate table calls it highly leveraged (B); an asset manager investing its balance sheet on debt to
     * adjusted total equity.
     */
    public function testRealEstateAndBalanceSheetAssetManagersAreReadOnTheirOwnTables(): void
    {
        $this->assertSame('B', CreditRatingAgency::financialRiskAnchor(600.0, 100.0, false));
        $this->assertSame('BBB', CreditRatingAgency::financialRiskAnchor(600.0, 100.0, false, CreditRatingAgency::REAL_ESTATE_DEBT_TO_EBITDA_BOUNDS));
        $this->assertSame('B', CreditRatingAgency::financialRiskAnchor(1_400.0, 100.0, false, CreditRatingAgency::REAL_ESTATE_DEBT_TO_EBITDA_BOUNDS));
        $this->assertSame('A', CreditRatingAgency::adjustedEquityLeverageAnchor(30.0, 100.0));
        $this->assertSame('BBB', CreditRatingAgency::adjustedEquityLeverageAnchor(120.0, 100.0));
        $this->assertSame('BB', CreditRatingAgency::adjustedEquityLeverageAnchor(240.0, 100.0));
        $this->assertSame('B', CreditRatingAgency::adjustedEquityLeverageAnchor(240.0, -1.0), 'no tangible equity is highly leveraged');
    }
}
