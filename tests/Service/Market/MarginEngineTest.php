<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Service\Market\MarginEngine;
use App\Service\Market\OptionMarginCalculator;
use App\Service\Math\MathUtility;
use App\Service\Math\FinancialConstants;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Regulation T arithmetic.
 *
 * Leverage is what lets a position end an account rather than merely dent it, and every number below is
 * part of deciding when that happens. The evaluate() path takes explicit balances so the accounting can be
 * checked without a database standing behind it.
 */
#[AllowMockObjectsWithoutExpectations]
class MarginEngineTest extends TestCase
{
    private MarginEngine $engine;

    protected function setUp(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $this->engine = new MarginEngine($em, new OptionMarginCalculator($em, new MathUtility()));
    }

    public function testAnUnleveredCashAccountIsAllEquity(): void
    {
        $status = $this->engine->evaluate(cash: 10000.0, longMarketValue: 0.0, shortMarketValue: 0.0, marginDebit: 0.0);

        $this->assertSame(10000.0, $status->equity);
        $this->assertSame(0.0, $status->maintenanceRequirement);
        $this->assertFalse($status->isCalled());
    }

    public function testBorrowedCashComesStraightBackOutOfEquity(): void
    {
        // $10k of cash turned into $20k of stock on a $10k loan is still $10k of equity. Anything else
        // would report leverage as wealth at the moment it is taken on.
        $status = $this->engine->evaluate(cash: 0.0, longMarketValue: 20000.0, shortMarketValue: 0.0, marginDebit: 10000.0);

        $this->assertSame(10000.0, $status->equity);
    }

    public function testAShortIsNotFreeMoneyAtTheMomentItIsOpened(): void
    {
        // Proceeds are already in cash, so the obligation has to come back out or the short reads as an
        // instant doubling of the account.
        $status = $this->engine->evaluate(cash: 15000.0, longMarketValue: 0.0, shortMarketValue: 5000.0, marginDebit: 0.0);

        $this->assertSame(10000.0, $status->equity);
    }

    public function testAShortMakesMoneyWhenThePriceFallsAndLosesWhenItRises(): void
    {
        $opened = $this->engine->evaluate(cash: 15000.0, longMarketValue: 0.0, shortMarketValue: 5000.0, marginDebit: 0.0);
        $winning = $this->engine->evaluate(cash: 15000.0, longMarketValue: 0.0, shortMarketValue: 4000.0, marginDebit: 0.0);
        $losing = $this->engine->evaluate(cash: 15000.0, longMarketValue: 0.0, shortMarketValue: 7000.0, marginDebit: 0.0);

        $this->assertGreaterThan($opened->equity, $winning->equity);
        $this->assertLessThan($opened->equity, $losing->equity);
    }

    public function testAShortIsHeldToAHigherMaintenanceRateThanALong(): void
    {
        // A long's loss stops at zero. A short's does not, which is the whole reason the rates differ.
        $long = $this->engine->evaluate(cash: 0.0, longMarketValue: 10000.0, shortMarketValue: 0.0, marginDebit: 0.0);
        $short = $this->engine->evaluate(cash: 20000.0, longMarketValue: 0.0, shortMarketValue: 10000.0, marginDebit: 0.0);

        $this->assertGreaterThan($long->maintenanceRequirement, $short->maintenanceRequirement);
        $this->assertSame(FinancialConstants::MAINTENANCE_MARGIN_LONG * 10000.0, $long->maintenanceRequirement);
        $this->assertSame(FinancialConstants::MAINTENANCE_MARGIN_SHORT * 10000.0, $short->maintenanceRequirement);
    }

    public function testBuyingPowerIsFreeEquityGearedByTheInitialRequirement(): void
    {
        // At a 50% requirement a dollar of free equity supports two dollars of new position.
        $status = $this->engine->evaluate(cash: 10000.0, longMarketValue: 0.0, shortMarketValue: 0.0, marginDebit: 0.0);

        $this->assertSame(20000.0, $status->buyingPower);
    }

    public function testAFullyDeployedAccountHasNoBuyingPowerLeft(): void
    {
        $status = $this->engine->evaluate(cash: 0.0, longMarketValue: 20000.0, shortMarketValue: 0.0, marginDebit: 10000.0);

        $this->assertSame(0.0, $status->buyingPower);
    }

    public function testBuyingPowerNeverGoesNegative(): void
    {
        $status = $this->engine->evaluate(cash: 0.0, longMarketValue: 20000.0, shortMarketValue: 0.0, marginDebit: 18000.0);

        $this->assertSame(0.0, $status->buyingPower);
    }

    /**
     * Working a buy order must not call the account that placed it.
     *
     * Escrow moves cash out of cash_balance, and the margin surface read that as a loss while the holdings
     * it was committed to buy had not arrived yet — so equity fell by the full notional and nothing offset
     * it. An account with $80k of equity against $100k of stock could place a $60k limit order that was
     * within its own buying power and be called the instant it rested, with ForcedLiquidationService then
     * selling the longs. Placing an order does not change what an account owns.
     */
    public function testWorkingABuyOrderDoesNotCallTheAccountThatPlacedIt(): void
    {
        // $30k cash, $100k of stock against $50k borrowed: $80k of equity, $25k required, $60k of capacity.
        $before = $this->engine->evaluate(cash: 30000.0, longMarketValue: 100000.0, shortMarketValue: 0.0, marginDebit: 50000.0);
        $this->assertSame(60000.0, $before->buyingPower);
        $this->assertFalse($before->isCalled());

        // The whole $60k is committed to a resting buy: cash goes to zero and the rest is borrowed.
        $after = $this->engine->evaluate(
            cash: 0.0 + 60000.0,
            longMarketValue: 100000.0,
            shortMarketValue: 0.0,
            marginDebit: 80000.0,
            openBuyCommitment: 60000.0
        );

        $this->assertSame($before->equity, $after->equity, 'Escrowed cash is still the account\'s cash.');
        $this->assertFalse($after->isCalled());
    }

    /**
     * The same free equity cannot back two resting orders.
     *
     * This is the reason escrow cannot simply be added back and left there. Equity has to include it or the
     * account is called for placing an order; capacity has to exclude it or the account can work ten orders
     * against the money for one, and every one of them fills.
     */
    public function testTheSameFreeEquityCannotBackTwoRestingOrders(): void
    {
        $status = $this->engine->evaluate(
            cash: 60000.0,
            longMarketValue: 100000.0,
            shortMarketValue: 0.0,
            marginDebit: 80000.0,
            openBuyCommitment: 60000.0
        );

        $this->assertSame(0.0, $status->buyingPower);
    }

    public function testACallFiresExactlyAtTheMaintenanceThresholdAndNotBefore(): void
    {
        // Off-by-one here is the difference between calling an account a tick early and letting it go
        // through the floor before anyone notices.
        $longValue = 20000.0;
        $threshold = FinancialConstants::MAINTENANCE_MARGIN_LONG * $longValue;

        $justAbove = $this->engine->evaluate(cash: 0.0, longMarketValue: $longValue, shortMarketValue: 0.0, marginDebit: $longValue - $threshold - 0.01);
        $justBelow = $this->engine->evaluate(cash: 0.0, longMarketValue: $longValue, shortMarketValue: 0.0, marginDebit: $longValue - $threshold + 0.01);

        $this->assertFalse($justAbove->isCalled());
        $this->assertTrue($justBelow->isCalled());
    }

    public function testTheCallAmountIsTheEquityShortfall(): void
    {
        $status = $this->engine->evaluate(cash: 0.0, longMarketValue: 20000.0, shortMarketValue: 0.0, marginDebit: 16000.0);

        $this->assertSame(1000.0, $status->callAmount());
        $this->assertSame(0.0, $this->engine->evaluate(cash: 10000.0, longMarketValue: 0.0, shortMarketValue: 0.0, marginDebit: 0.0)->callAmount());
    }

    public function testLiquidatingRestoresMoreThanTheBareMinimum(): void
    {
        // A liquidation that clears exactly the shortfall is called again on the next tick, and the one
        // after that. The buffer is what stops a single bad day becoming an unending sequence of calls.
        $status = $this->engine->evaluate(cash: 0.0, longMarketValue: 20000.0, shortMarketValue: 0.0, marginDebit: 16000.0);

        $notional = $this->engine->liquidationNotional($status);

        // The bare minimum that clears the call is shortfall / maintenance rate. The buffer sells MORE than
        // that, not less: selling less would leave the account called again on the next tick.
        $this->assertGreaterThan(
            $status->callAmount() / FinancialConstants::MAINTENANCE_MARGIN_LONG,
            $notional
        );

        // Selling that much genuinely clears the call: equity is unchanged, the requirement falls.
        $after = $this->engine->evaluate(
            cash: 0.0,
            longMarketValue: 20000.0 - $notional,
            shortMarketValue: 0.0,
            marginDebit: 16000.0 - $notional
        );

        $this->assertFalse($after->isCalled());
    }

    public function testAHealthyAccountLiquidatesNothing(): void
    {
        $status = $this->engine->evaluate(cash: 10000.0, longMarketValue: 10000.0, shortMarketValue: 0.0, marginDebit: 0.0);

        $this->assertSame(0.0, $this->engine->liquidationNotional($status));
    }

    public function testLiquidationCannotSellMoreThanTheAccountHolds(): void
    {
        $status = $this->engine->evaluate(cash: 0.0, longMarketValue: 5000.0, shortMarketValue: 20000.0, marginDebit: 0.0);

        $this->assertLessThanOrEqual($status->longMarketValue, $this->engine->liquidationNotional($status));
    }

    public function testEquityRatioReportsAgainstGrossExposure(): void
    {
        // A long and a short of the same size are not a flat book for risk purposes; both sides need
        // collateral, so gross is the denominator.
        $status = $this->engine->evaluate(cash: 20000.0, longMarketValue: 10000.0, shortMarketValue: 10000.0, marginDebit: 0.0);

        $this->assertSame(20000.0, $status->equity);
        $this->assertSame(1.0, $status->equityRatio());

        $this->assertSame(1.0, $this->engine->evaluate(cash: 100.0, longMarketValue: 0.0, shortMarketValue: 0.0, marginDebit: 0.0)->equityRatio());
    }
}
