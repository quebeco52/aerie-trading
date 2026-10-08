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
        $this->assertSame('CCC', $this->agency->convertDistanceToRating(0.6));
        // Floored at CCC. This ladder reads a market-implied distance to default; however far the equity
        // falls, a price is not a missed payment, and D is reserved for obligors that actually missed one.
        $this->assertSame('CCC', $this->agency->convertDistanceToRating(0.2));
        $this->assertSame('CCC', $this->agency->convertDistanceToRating(-1.0));
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
}
