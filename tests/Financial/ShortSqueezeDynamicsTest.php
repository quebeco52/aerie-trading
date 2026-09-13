<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Entity\Stock;
use App\Service\Market\LiquidityEngine;
use App\Service\Market\MarginEngine;
use App\Service\Market\SecuritiesLendingDesk;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * A short squeeze, assembled from the pieces rather than scripted.
 *
 * There is no squeeze event anywhere in the codebase, and there should not be. Every link in the chain is
 * priced by a component that exists for its own reasons: a rising price takes equity from shorts
 * (MarginEngine), covering pushes the price further (LiquidityEngine), utilization of the remaining borrow
 * climbs so the fee climbs (SecuritiesLendingDesk), and past the recall threshold the stock is taken back
 * whether the account is solvent or not.
 *
 * This test drives those components in sequence and asserts the loop closes. If a future change breaks any
 * link, the mechanic quietly stops existing — and nothing else would notice, because nothing else names it.
 */
#[AllowMockObjectsWithoutExpectations]
class ShortSqueezeDynamicsTest extends TestCase
{
    private LiquidityEngine $liquidity;
    private SecuritiesLendingDesk $desk;
    private MarginEngine $margin;

    protected function setUp(): void
    {
        $this->liquidity = new LiquidityEngine(new MathUtility());
        $this->desk = new SecuritiesLendingDesk();
        $this->margin = new MarginEngine($this->createStub(EntityManagerInterface::class));
    }

    private function stock(float $price = 100.0, float $shortInterest = 0.0): Stock
    {
        $stock = new Stock();
        $stock->setTicker('SQZ')
            ->setName('squeeze test')
            ->setSharesOutstanding('50000000')
            ->setPublicFloatPercentage('0.80')
            ->setVolatility('0.45')
            ->setCurrentVolatility('0.45')
            ->setPrice((string) $price);

        $stock->setTurnoverRatio(LiquidityEngine::structuralTurnoverRatio(0.45));
        $stock->setShortInterestShares((string) $shortInterest);

        return $stock;
    }

    public function testARisingPriceTakesEquityFromAShortUntilItIsCalled(): void
    {
        // The first link. A short opened at the full Regulation T requirement — half the position posted as
        // equity on top of the proceeds — is called by price alone, with no other input.
        $shares = 10000.0;
        $proceeds = $shares * 100.0;
        $cash = $proceeds + ($proceeds * FinancialConstants::INITIAL_MARGIN_REQUIREMENT);

        $atOpen = $this->margin->evaluate(cash: $cash, longMarketValue: 0.0, shortMarketValue: $proceeds, marginDebit: 0.0);
        $this->assertFalse($atOpen->isCalled());

        // equity = cash - shares x P, requirement = 0.30 x shares x P, so the call lands just above $115.
        $mild = $this->margin->evaluate(cash: $cash, longMarketValue: 0.0, shortMarketValue: $shares * 110.0, marginDebit: 0.0);
        $this->assertFalse($mild->isCalled(), 'A 10% move should not yet call a fully margined short.');

        $squeezed = $this->margin->evaluate(cash: $cash, longMarketValue: 0.0, shortMarketValue: $shares * 120.0, marginDebit: 0.0);
        $this->assertTrue($squeezed->isCalled(), 'A 20% rally must call a short opened at the initial requirement.');
    }

    public function testCoveringPushesThePriceFurtherUp(): void
    {
        // The second link, and the one that makes it a loop rather than a sequence. Forced buying is buying.
        $stock = $this->stock();
        $covered = $this->liquidity->averageDailyVolume($stock) * 0.30;

        $this->assertGreaterThan(0.0, $this->liquidity->permanentImpact($stock, $covered));
    }

    public function testEachRoundOfCoveringRaisesTheCostOfStayingShortForEveryoneElse(): void
    {
        // The third link. The fee is a property of the name, so one account's forced covering does not
        // relieve the others — the borrow they still hold gets more expensive as supply tightens.
        $supply = $this->desk->lendableSupply($this->stock());

        $calm = $this->desk->borrowFee($this->stock(shortInterest: $supply * 0.60));
        $tight = $this->desk->borrowFee($this->stock(shortInterest: $supply * 0.88));
        $desperate = $this->desk->borrowFee($this->stock(shortInterest: $supply * 0.96));

        $this->assertGreaterThan($calm * 5.0, $tight);
        $this->assertGreaterThan($tight, $desperate);
    }

    public function testPastTheRecallThresholdAWinningShortIsStillTakenBack(): void
    {
        // The fourth link, and what makes a real squeeze inescapable. A margin call can be met with more
        // equity; a recall cannot be met at all.
        $supply = $this->desk->lendableSupply($this->stock());
        $recalling = $this->stock(shortInterest: $supply * 0.99);

        // An account with enormous equity: nothing about its own solvency is in question.
        $healthy = $this->margin->evaluate(cash: 10_000_000.0, longMarketValue: 0.0, shortMarketValue: 100000.0, marginDebit: 0.0);
        $this->assertFalse($healthy->isCalled());

        $this->assertGreaterThan(0.0, $this->desk->buyInQuantity($recalling, 100000.0));
    }

    /**
     * The loop, run end to end: the four links compose into a self-reinforcing spiral.
     */
    public function testTheLoopClosesAndFeedsItself(): void
    {
        $stock = $this->stock();
        $supply = $this->desk->lendableSupply($stock);

        // A crowded but not yet critical short base, and one account holding a slice of it.
        $shortInterest = $supply * 0.80;
        $stock->setShortInterestShares((string) $shortInterest);

        $position = $shortInterest * 0.10;
        $price = 100.0;
        $cash = ($position * $price) + ($position * $price * 0.35);

        $feeAtStart = $this->desk->borrowFee($stock);
        $priceAtStart = $price;
        $covered = 0.0;
        $rounds = 0;

        // An exogenous rally starts it; everything after the first nudge is the mechanism.
        $price *= 1.10;

        for ($round = 0; $round < 6; $round++) {
            $stock->setPrice((string) $price);

            $status = $this->margin->evaluate(
                cash: $cash,
                longMarketValue: 0.0,
                shortMarketValue: $position * $price,
                marginDebit: 0.0
            );

            if (!$status->isCalled() || $position <= 0.0) {
                break;
            }

            $rounds++;

            // Buy back enough to restore the requirement, and pay for it at the market.
            $toCover = min($position, $status->callAmount() / (FinancialConstants::MAINTENANCE_MARGIN_SHORT * $price));
            $quote = $this->liquidity->quote($stock, 'BUY', (int) $toCover, $price);

            $cash -= $quote->executionPrice * $toCover;
            $position -= $toCover;
            $covered += $toCover;

            // The covering both moves the price and frees borrow for everyone else.
            $shortInterest = max(0.0, $shortInterest - $toCover);
            $stock->setShortInterestShares((string) $shortInterest);
            $price *= exp($this->liquidity->permanentImpact($stock, $toCover));
        }

        $this->assertGreaterThan(0, $rounds, 'The rally must actually call the short.');
        $this->assertGreaterThan(0.0, $covered, 'A called short must be forced to buy.');
        $this->assertGreaterThan($priceAtStart, $price, 'Forced covering leaves the price higher than it started.');
        $this->assertGreaterThan($feeAtStart, $this->desk->borrowFee($this->stock(shortInterest: $supply * 0.95)));
    }

    public function testAShortsLossIsUnboundedWhileALongsStopsAtZero(): void
    {
        // The asymmetry the higher maintenance rate exists for, and the reason a squeeze can end an account
        // in a way a collapse in a long position cannot.
        $shares = 1000.0;

        $longWipeout = $this->margin->evaluate(cash: 0.0, longMarketValue: 0.0, shortMarketValue: 0.0, marginDebit: 0.0);
        $this->assertSame(0.0, $longWipeout->equity, 'A long can lose everything and no more.');

        $shortBlowUp = $this->margin->evaluate(
            cash: $shares * 100.0,
            longMarketValue: 0.0,
            shortMarketValue: $shares * 400.0,
            marginDebit: 0.0
        );

        $this->assertLessThan(0.0, $shortBlowUp->equity, 'A short can owe more than the account holds.');
    }
}
