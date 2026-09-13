<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\Stock;
use App\Service\Market\SecuritiesLendingDesk;
use App\Service\Math\FinancialConstants;
use PHPUnit\Framework\TestCase;

/**
 * The borrow, and the shape of the fee curve that turns a crowded short into an expensive one.
 */
class SecuritiesLendingDeskTest extends TestCase
{
    private SecuritiesLendingDesk $desk;

    protected function setUp(): void
    {
        $this->desk = new SecuritiesLendingDesk();
    }

    private function stock(float $shortInterest = 0.0, float $float = 0.90, ?float $lendable = null): Stock
    {
        $stock = new Stock();
        $stock->setTicker('TEST')
            ->setName('test')
            ->setSharesOutstanding('100000000')
            ->setPublicFloatPercentage((string) $float)
            ->setPrice('100');

        $stock->setLendableSupplyRatio($lendable);
        $stock->setShortInterestShares((string) $shortInterest);

        return $stock;
    }

    public function testOnlyAFractionOfTheFloatIsActuallyLendable(): void
    {
        // Most holders do not lend, which is what makes a name hard to borrow long before anything like
        // its whole float has been shorted.
        $supply = $this->desk->lendableSupply($this->stock());

        $this->assertLessThan(1.0e8 * 0.90, $supply);
        $this->assertEqualsWithDelta(1.0e8 * 0.90 * FinancialConstants::DEFAULT_LENDABLE_SUPPLY_RATIO, $supply, 1.0);
    }

    public function testATightlyHeldNameHasLessToLend(): void
    {
        $this->assertGreaterThan(
            $this->desk->lendableSupply($this->stock(float: 0.20)),
            $this->desk->lendableSupply($this->stock(float: 0.95))
        );
    }

    public function testAvailabilityFallsAsShortsAccumulateAndNeverGoesNegative(): void
    {
        $supply = $this->desk->lendableSupply($this->stock());

        $this->assertEqualsWithDelta($supply, $this->desk->availableToBorrow($this->stock()), 1.0);
        $this->assertEqualsWithDelta($supply * 0.5, $this->desk->availableToBorrow($this->stock($supply * 0.5)), 1.0);
        $this->assertSame(0.0, $this->desk->availableToBorrow($this->stock($supply * 2.0)));
    }

    public function testAnEasyToBorrowNameCostsGeneralCollateral(): void
    {
        $this->assertEqualsWithDelta(
            FinancialConstants::GENERAL_COLLATERAL_BORROW_FEE,
            $this->desk->borrowFee($this->stock()),
            1e-9
        );
    }

    public function testTheFeeCurveIsFlatWhileSupplyIsAmpleAndSteepensSharplyAtTheEnd(): void
    {
        // The convexity is the point. A linear fee would be a mild tax at every level and no squeeze would
        // ever develop; this shape is what makes the last of the borrow genuinely scarce.
        $supply = $this->desk->lendableSupply($this->stock());

        $atHalf = $this->desk->borrowFee($this->stock($supply * 0.50));
        $atThreeQuarters = $this->desk->borrowFee($this->stock($supply * 0.75));
        $atNinety = $this->desk->borrowFee($this->stock($supply * 0.90));

        $this->assertLessThan(0.02, $atHalf, 'Half utilization is still general collateral.');
        $this->assertGreaterThan(0.05, $atThreeQuarters);
        $this->assertGreaterThan(0.30, $atNinety);

        // Each step up costs more than the one before it: that is what convex means here.
        $this->assertGreaterThan($atThreeQuarters - $atHalf, $atNinety - $atThreeQuarters);
    }

    public function testTheFeeIsMonotonicInUtilizationAndBounded(): void
    {
        $supply = $this->desk->lendableSupply($this->stock());
        $previous = -1.0;

        for ($step = 0; $step <= 20; $step++) {
            $fee = $this->desk->borrowFee($this->stock($supply * ($step / 20.0)));

            $this->assertGreaterThan($previous, $fee);
            $this->assertGreaterThanOrEqual(FinancialConstants::GENERAL_COLLATERAL_BORROW_FEE, $fee);
            $this->assertLessThanOrEqual(FinancialConstants::MAX_BORROW_FEE, $fee);

            $previous = $fee;
        }
    }

    public function testAccruedFeeScalesWithPositionValueAndTime(): void
    {
        $stock = $this->stock();

        $oneYear = $this->desk->accruedFee($stock, 1000.0, 100.0, 1.0);

        $this->assertEqualsWithDelta(1000.0 * 100.0 * FinancialConstants::GENERAL_COLLATERAL_BORROW_FEE, $oneYear, 1e-9);
        $this->assertEqualsWithDelta($oneYear / 2.0, $this->desk->accruedFee($stock, 1000.0, 100.0, 0.5), 1e-9);
        $this->assertEqualsWithDelta($oneYear * 2.0, $this->desk->accruedFee($stock, 2000.0, 100.0, 1.0), 1e-9);
    }

    public function testNothingAccruesOnALongOrOverNoTime(): void
    {
        $stock = $this->stock();

        $this->assertSame(0.0, $this->desk->accruedFee($stock, 0.0, 100.0, 1.0));
        $this->assertSame(0.0, $this->desk->accruedFee($stock, 1000.0, 100.0, 0.0));
    }

    public function testLendersRecallOnlyWhenTheSupplyIsAllButGone(): void
    {
        $supply = $this->desk->lendableSupply($this->stock());

        $this->assertFalse($this->desk->isRecalling($this->stock($supply * 0.90)));
        $this->assertTrue($this->desk->isRecalling($this->stock($supply * 0.99)));
    }

    public function testABuyInTakesBackPartOfThePositionRegardlessOfHowItIsDoing(): void
    {
        // A recall is not a margin call. It lands on a short that is perfectly well collateralized and
        // winning, which is what makes a genuine squeeze inescapable rather than merely expensive.
        $supply = $this->desk->lendableSupply($this->stock());
        $recalling = $this->stock($supply * 0.99);

        $quantity = $this->desk->buyInQuantity($recalling, 10000.0);

        $this->assertGreaterThan(0.0, $quantity);
        $this->assertLessThan(10000.0, $quantity, 'A buy-in takes a slice, not the whole position.');
        $this->assertEqualsWithDelta(10000.0 * FinancialConstants::BUY_IN_FRACTION, $quantity, 1.0);
    }

    public function testNoBuyInWhileBorrowIsStillAvailable(): void
    {
        $supply = $this->desk->lendableSupply($this->stock());

        $this->assertSame(0.0, $this->desk->buyInQuantity($this->stock($supply * 0.50), 10000.0));
    }

    public function testABuyInNeverExceedsThePositionItIsRecalling(): void
    {
        $supply = $this->desk->lendableSupply($this->stock());

        $this->assertSame(1.0, $this->desk->buyInQuantity($this->stock($supply * 0.99), 1.0));
        $this->assertSame(0.0, $this->desk->buyInQuantity($this->stock($supply * 0.99), 0.0));
    }

    public function testANameWithNoLendableSupplyIsFullyUtilizedRatherThanDividingByZero(): void
    {
        $stock = $this->stock(float: 0.0);

        $this->assertSame(1.0, $this->desk->utilization($stock));
        $this->assertSame(0.0, $this->desk->availableToBorrow($stock));
        $this->assertEqualsWithDelta(FinancialConstants::MAX_BORROW_FEE, $this->desk->borrowFee($stock), 1e-9);
    }
}
