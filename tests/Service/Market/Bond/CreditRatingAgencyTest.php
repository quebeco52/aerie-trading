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
            'a capital ratio the CCP\'s own model calls safe may not cap its rating'
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
                'the standard path zones on the same two numbers the brackets use'
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
