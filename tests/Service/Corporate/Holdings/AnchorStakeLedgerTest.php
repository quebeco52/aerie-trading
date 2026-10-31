<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate\Holdings;

use App\Data\Company\AnchorHoldings;
use App\Entity\Stock;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The mark on a sphere's listed anchor stakes: when the companies it holds fall, its net asset value falls
 * with them — the one behaviour the class could not previously express.
 */
#[Group('unit')]
class AnchorStakeLedgerTest extends TestCase
{
    private AnchorStakeLedger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new AnchorStakeLedger();
    }

    /**
     * @param array<string, float> $caps ticker => market capitalisation
     * @return list<Stock>
     */
    private function board(array $caps): array
    {
        $stocks = [];

        foreach ($caps as $ticker => $cap) {
            $stocks[] = StockBuilder::create($ticker)
                ->withPrice($cap / 1_000_000_000.0)
                ->withSharesOutstanding(1_000_000_000)
                ->build();
        }

        return $stocks;
    }

    /**
     * Every holding at one capitalisation, so the stake value is checkable by hand.
     *
     * @return list<Stock>
     */
    private function flatBoard(float $capEach): array
    {
        $caps = [];

        foreach (array_keys(AnchorHoldings::forHolder('BRKW')) as $ticker) {
            $caps[$ticker] = $capEach;
        }

        return $this->board($caps);
    }

    private function sphere(float $equity = 1_880_000_000_000.0): Stock
    {
        return StockBuilder::create('BRKW')
            ->withTotalEquity($equity)
            ->withSharesOutstanding(1_000_000_000)
            ->build();
    }

    public function testTheStakeIsThatFractionOfTheHeldCompanysMarketCapitalisation(): void
    {
        $capEach = 200_000_000_000.0;
        $this->ledger->beginTick($this->flatBoard($capEach));

        $expected = array_sum(AnchorHoldings::forHolder('BRKW')) * $capEach;

        $this->assertEqualsWithDelta($expected, $this->ledger->resolveStakeValue($this->sphere()), 1.0);
    }

    public function testAFirmHoldingNothingIsNeverMarked(): void
    {
        $this->ledger->beginTick($this->flatBoard(200_000_000_000.0));

        $ordinary = StockBuilder::create('CBIL')->build();

        $this->assertNull($this->ledger->resolveStakeValue($ordinary));
        $this->assertSame(0.0, $this->ledger->markToMarket($ordinary));
        $this->assertNull($ordinary->getListedStakesCarrying());
    }

    /** A partial sum would be short by whatever was absent, and the book would record that as a loss. */
    public function testAnIncompleteBoardValuesNothingRatherThanPartOfThePortfolio(): void
    {
        $board = $this->flatBoard(200_000_000_000.0);
        array_pop($board);

        $this->ledger->beginTick($board);

        $this->assertNull($this->ledger->resolveStakeValue($this->sphere()));
    }

    public function testAnUnprimedLedgerMarksNothing(): void
    {
        $sphere = $this->sphere();

        $this->assertNull($this->ledger->resolveStakeValue($sphere));
        $this->assertSame(0.0, $this->ledger->markToMarket($sphere));
        $this->assertSame('1880000000000.0000', $sphere->getTotalEquity());
    }

    /** The stakes are already inside the seeded book, so opening the position credits no gain. */
    public function testTheFirstMarkOpensThePositionAndMovesNoEquity(): void
    {
        $this->ledger->beginTick($this->flatBoard(200_000_000_000.0));
        $sphere = $this->sphere();

        $gain = $this->ledger->markToMarket($sphere);

        $this->assertSame(0.0, $gain);
        $this->assertSame('1880000000000.0000', $sphere->getTotalEquity());
        $this->assertNotNull($sphere->getListedStakesCarrying());
    }

    public function testTheSecondMarkMovesEquityByWhatThePortfolioDidBetweenThem(): void
    {
        $this->ledger->beginTick($this->flatBoard(200_000_000_000.0));
        $sphere = $this->sphere();
        $this->ledger->markToMarket($sphere);
        $opening = (float) $sphere->getListedStakesCarrying();

        // Every holding doubles.
        $this->ledger->beginTick($this->flatBoard(400_000_000_000.0));
        $gain = $this->ledger->markToMarket($sphere);

        $this->assertEqualsWithDelta($opening, $gain, 1.0);
        $this->assertEqualsWithDelta(1_880_000_000_000.0 + $opening, (float) $sphere->getTotalEquity(), 1.0);
        $this->assertEqualsWithDelta($opening * 2.0, (float) $sphere->getListedStakesCarrying(), 1.0);
    }

    /** The headline behaviour: before the mark, this book could only ever go up. */
    public function testTheSpheresBookFallsWithTheMarketItHolds(): void
    {
        $this->ledger->beginTick($this->flatBoard(200_000_000_000.0));
        $sphere = $this->sphere();
        $this->ledger->markToMarket($sphere);
        $carried = (float) $sphere->getListedStakesCarrying();

        // A 40% bear market across the whole portfolio.
        $this->ledger->beginTick($this->flatBoard(120_000_000_000.0));
        $loss = $this->ledger->markToMarket($sphere);

        $this->assertEqualsWithDelta(-0.40 * $carried, $loss, 1.0);
        $this->assertLessThan(1_880_000_000_000.0, (float) $sphere->getTotalEquity());
    }

    /** A gain nobody has realised has not been taxed: the overhang accrues and the rest reaches holders. */
    public function testTheTaxOnAnUnrealisedGainAccruesAsDeferredTax(): void
    {
        $this->ledger->beginTick($this->flatBoard(200_000_000_000.0));
        $sphere = $this->sphere();
        $this->ledger->markToMarket($sphere, 0.25);
        $carried = (float) $sphere->getListedStakesCarrying();
        $equity = (float) $sphere->getTotalEquity();

        $this->ledger->beginTick($this->flatBoard(400_000_000_000.0));
        $gain = $this->ledger->markToMarket($sphere, 0.25);

        $this->assertEqualsWithDelta($carried, $gain, 1.0);
        $this->assertEqualsWithDelta($gain * 0.25, $sphere->getListedStakesDeferredTax(), 1.0);
        $this->assertSame('0.0000', $sphere->getDeferredTaxLiability(), 'the plant\'s timing difference is its own balance');
        $this->assertEqualsWithDelta($equity + ($gain * 0.75), (float) $sphere->getTotalEquity(), 1.0);
    }

    /**
     * Below cost the overhang turns into an asset, and a fall and the recovery from it leave the book where it began:
     * before, a floor at zero kept the tax on the recovery and none on the fall.
     */
    public function testAFallBelowCostAndTheRecoveryLeaveTheBookWhereItBegan(): void
    {
        $this->ledger->beginTick($this->flatBoard(200_000_000_000.0));
        $sphere = $this->sphere();
        $sphere->setDeferredTaxLiability('30000000000.0000');
        $this->ledger->markToMarket($sphere, 0.25);
        $equity = (float) $sphere->getTotalEquity();

        $this->ledger->beginTick($this->flatBoard(150_000_000_000.0));
        $this->ledger->markToMarket($sphere, 0.25);
        $this->assertLessThan(0.0, $sphere->getListedStakesDeferredTax(), 'a loss below cost is an asset');
        $this->assertSame('30000000000.0000', $sphere->getDeferredTaxLiability(), 'the plant\'s deferred tax is untouched');

        $this->ledger->beginTick($this->flatBoard(200_000_000_000.0));
        $this->ledger->markToMarket($sphere, 0.25);
        $this->assertEqualsWithDelta(0.0, $sphere->getListedStakesDeferredTax(), 1.0);
        $this->assertEqualsWithDelta($equity, (float) $sphere->getTotalEquity(), 1.0);
    }

    /** A tender realises its share of the portfolio's gain, and the tax deferred on it is paid. */
    public function testATenderPaysTheTaxOnTheGainItRealises(): void
    {
        $sphere = $this->sphere();
        $sphere->setCorporateTreasury('100000000000.0000');
        $held = $this->flatBoard(200_000_000_000.0);
        $albt = $this->holding($held, 'ALBT');
        $this->ledger->beginTick([...$held, $sphere]);
        $this->ledger->markToMarket($sphere, 0.25);
        foreach ($held as $stock) {
            $stock->setPrice('400');
        }
        $this->ledger->beginTick([...$held, $sphere]);
        $this->ledger->markToMarket($sphere, 0.25);
        $deferred = $sphere->getListedStakesDeferredTax();
        $carrying = (float) $sphere->getListedStakesCarrying();
        $equity = (float) $sphere->getTotalEquity();

        // Float at the minimum, so a buyback forces the anchor to tender.
        $albt->setPublicFloatPercentage((string) AnchorHoldings::MIN_TRADABLE_FLOAT);
        $albt->setSharesOutstanding('900000000');
        $this->ledger->settleShareChange($albt, 0.0, 0.0, 100_000_000.0, 400.0);

        // No float to spare, so it tenders exactly its pro-rata part.
        $proceeds = AnchorHoldings::forHolder('BRKW')['ALBT'] * 100_000_000.0 * 400.0;
        $taxDue = ($proceeds / $carrying) * $deferred;
        $this->assertGreaterThan(0.0, $taxDue);
        $this->assertEqualsWithDelta($deferred - $taxDue, $sphere->getListedStakesDeferredTax(), 1.0);
        $this->assertEqualsWithDelta(100_000_000_000.0 + $proceeds - $taxDue, (float) $sphere->getCorporateTreasury(), 1.0);
        $this->assertEqualsWithDelta($equity, (float) $sphere->getTotalEquity(), 1.0, 'tax that was already deferred is no new loss');
    }

    /**
     * A mandatory redemption pays every holder alike: the holder's fraction and the float hold, the cash is a return
     * of the stake, and the part of the stake it redeems pays the tax deferred on it. Its value does not move, within
     * the tick or after the price has fallen ex-date by what was paid.
     */
    public function testARedemptionReturnsPartOfTheStakeAndMovesNoFraction(): void
    {
        $sphere = $this->sphere();
        $sphere->setCorporateTreasury('100000000000.0000');
        $held = $this->flatBoard(200_000_000_000.0);
        $albt = $this->holding($held, 'ALBT');
        $this->ledger->beginTick([...$held, $sphere]);
        $this->ledger->markToMarket($sphere, 0.25);
        foreach ($held as $stock) {
            $stock->setPrice('400');
        }
        $this->ledger->beginTick([...$held, $sphere]);
        $this->ledger->markToMarket($sphere, 0.25);
        $deferred = $sphere->getListedStakesDeferredTax();
        $carrying = (float) $sphere->getListedStakesCarrying();
        $equity = (float) $sphere->getTotalEquity();
        $float = (float) $albt->getPublicFloatPercentage();
        $fraction = AnchorStakeLedger::fraction($albt, 'BRKW');
        $value = (float) $this->ledger->resolveMarkedBookValuePerShare($sphere);

        $this->ledger->settleRedemption($albt, 4.0, 1_000_000_000.0);

        $proceeds = $fraction * 4.0 * 1_000_000_000.0;
        $taxDue = ($proceeds / $carrying) * $deferred;
        $this->assertGreaterThan(0.0, $taxDue);
        $this->assertSame($fraction, AnchorStakeLedger::fraction($albt, 'BRKW'), 'redeemed alike, its percentage holds');
        $this->assertSame($float, (float) $albt->getPublicFloatPercentage());
        $this->assertEqualsWithDelta($carrying - $proceeds, (float) $sphere->getListedStakesCarrying(), 1.0);
        $this->assertEqualsWithDelta($deferred - $taxDue, $sphere->getListedStakesDeferredTax(), 1.0);
        $this->assertEqualsWithDelta(100_000_000_000.0 + $proceeds - $taxDue, (float) $sphere->getCorporateTreasury(), 1.0);
        $this->assertEqualsWithDelta($equity, (float) $sphere->getTotalEquity(), 1.0, 'a return of the stake, not income');
        $this->assertEqualsWithDelta($value, (float) $this->ledger->resolveMarkedBookValuePerShare($sphere), 1e-6, 'the cash left the company it came from');

        $albt->setPrice('396');
        $this->ledger->beginTick([...$held, $sphere]);
        $this->assertEqualsWithDelta($value, (float) $this->ledger->resolveMarkedBookValuePerShare($sphere), 1e-6, 'ex-date, the stake fell by what was paid');
        $this->assertSame(0.0, AnchorStakeLedger::dividendsReceivable($sphere), 'no income was paid');
    }

    /** Assets moved by the whole mark, so liabilities and equity together must move by the same amount. */
    public function testTheBalanceSheetStaysBalancedAcrossAMark(): void
    {
        $this->ledger->beginTick($this->flatBoard(200_000_000_000.0));
        $sphere = $this->sphere();
        $this->ledger->markToMarket($sphere, 0.25);
        $gap = $sphere->getTotalAssets() - ($sphere->getTotalLiabilities() + (float) $sphere->getTotalEquity());

        foreach ([400_000_000_000.0, 120_000_000_000.0, 260_000_000_000.0] as $cap) {
            $this->ledger->beginTick($this->flatBoard($cap));
            $this->ledger->markToMarket($sphere, 0.25);

            $this->assertEqualsWithDelta(
                $gap,
                $sphere->getTotalAssets() - ($sphere->getTotalLiabilities() + (float) $sphere->getTotalEquity()),
                1.0,
                'A mark moved the asset side without an equal claim against it.',
            );
        }
    }

    /** An unrealised mark is not distributable, so it must never reach the earnings record. */
    public function testTheMarkNeverTouchesRetainedEarnings(): void
    {
        $this->ledger->beginTick($this->flatBoard(200_000_000_000.0));
        $sphere = $this->sphere();
        $sphere->setRetainedEarnings('1200000000000.0000');
        $this->ledger->markToMarket($sphere);

        $this->ledger->beginTick($this->flatBoard(400_000_000_000.0));
        $this->ledger->markToMarket($sphere);

        $this->assertSame('1200000000000.0000', $sphere->getRetainedEarnings());
    }

    /** The valuation sees the move between two filings; the books wait for the next report. */
    public function testBookValuePerShareCarriesThePortfolioMoveSinceTheReport(): void
    {
        $this->ledger->beginTick($this->flatBoard(200_000_000_000.0));
        $sphere = $this->sphere();
        $this->ledger->markToMarket($sphere);
        $carried = (float) $sphere->getListedStakesCarrying();
        $filedEquity = (float) $sphere->getTotalEquity();

        $this->ledger->beginTick($this->flatBoard(300_000_000_000.0));

        $marked = $this->ledger->resolveMarkedBookValuePerShare($sphere);

        $this->assertNotNull($marked);
        $this->assertEqualsWithDelta(($filedEquity + ($carried * 0.50)) / 1_000_000_000.0, $marked, 0.01);
        // The filed book has not moved: only the report moves it.
        $this->assertSame($filedEquity, (float) $sphere->getTotalEquity());

        // Net of the tax the report books on it, so the report moves nothing the valuation had not seen.
        $this->ledger->beginTick($this->flatBoard(200_000_000_000.0));
        $this->ledger->markToMarket($sphere, 0.25);
        $this->ledger->beginTick($this->flatBoard(300_000_000_000.0));
        $beforeReport = (float) $this->ledger->resolveMarkedBookValuePerShare($sphere);
        $this->ledger->markToMarket($sphere, 0.25);
        $this->assertEqualsWithDelta((float) $sphere->getTotalEquity() / 1_000_000_000.0, $beforeReport, 1e-6);
    }

    /** The stakes' share of the marked book is the part of the sphere's value the board's prices carry. */
    public function testTheMarkedStakeShareIsTheStakesOverTheMarkedBook(): void
    {
        $this->ledger->beginTick($this->flatBoard(200_000_000_000.0));
        $sphere = $this->sphere();
        $this->ledger->markToMarket($sphere);
        $this->ledger->beginTick($this->flatBoard(300_000_000_000.0));

        $stakes = (float) $this->ledger->resolveStakeValue($sphere);
        $book = (float) $this->ledger->resolveMarkedBookValuePerShare($sphere) * 1_000_000_000.0;
        $this->assertEqualsWithDelta($stakes / $book, $this->ledger->resolveMarkedStakeShare($sphere), 1e-12);
        $this->assertGreaterThan(0.0, $this->ledger->resolveMarkedStakeShare($sphere));
        $this->assertSame(0.0, $this->ledger->resolveMarkedStakeShare(StockBuilder::create('CBIL')->build()), 'A firm holding none carries nothing at the board\'s prices.');
    }

    public function testAnUnmarkedFirmFallsBackToItsFiledBook(): void
    {
        $this->ledger->beginTick($this->flatBoard(200_000_000_000.0));

        $this->assertNull($this->ledger->resolveMarkedBookValuePerShare($this->sphere()));
        $this->assertNull($this->ledger->resolveMarkedBookValuePerShare(StockBuilder::create('CBIL')->build()));
    }

    /**
     * A sphere keeps its percentage by subscribing for its fraction of an issue, in cash. Without the cash the
     * stake rose with the raise, and the holder booked as a gain the money it never put up.
     */
    public function testAnIssueIsSubscribedForInCashAndMarksNoGain(): void
    {
        $sphere = $this->sphere();
        $sphere->setCorporateTreasury('100000000000.0000');
        $held = $this->flatBoard(200_000_000_000.0);
        $this->ledger->beginTick([...$held, $sphere]);
        $this->ledger->markToMarket($sphere);
        $carrying = (float) $sphere->getListedStakesCarrying();
        $fraction = AnchorHoldings::forHolder('BRKW')['ALBT'];

        // ALBT sells 100M new shares at $200, a $20B raise; the sphere's part is its fraction of it.
        $albt = $this->holding($held, 'ALBT');
        $albt->setSharesOutstanding('1100000000');
        $this->ledger->settleShareChange($albt, 100_000_000.0, 200.0, 0.0, 0.0);
        $paid = $fraction * 20_000_000_000.0;

        $this->assertEqualsWithDelta(100_000_000_000.0 - $paid, (float) $sphere->getCorporateTreasury(), 1.0);
        $this->assertEqualsWithDelta($carrying + $paid, (float) $sphere->getListedStakesCarrying(), 1.0);
        $this->assertEqualsWithDelta($fraction, AnchorStakeLedger::fraction($albt, 'BRKW'), 1e-12, 'an issue it subscribes to keeps its percentage');
        $this->assertEqualsWithDelta(0.0, $this->ledger->markToMarket($sphere), 1.0, 'a mark later in the tick sees the shares it paid for');

        // The next tick's board carries the new shares at the same price: still nothing to book.
        $albt->setPrice('200');
        $this->ledger->beginTick([...$held, $sphere]);
        $this->assertEqualsWithDelta(0.0, $this->ledger->markToMarket($sphere), 1.0);
        $this->assertEqualsWithDelta(1_880_000_000_000.0, (float) $sphere->getTotalEquity(), 1.0);
    }

    /** A buyback it sits out: no cash moves, and its fraction rises by the shares the float gave up. */
    public function testABuybackIsSatOutAndRaisesTheHoldersPercentage(): void
    {
        $sphere = $this->sphere();
        $sphere->setCorporateTreasury('100000000000.0000');
        $held = $this->flatBoard(200_000_000_000.0);
        $albt = $this->holding($held, 'ALBT');
        $albt->setPublicFloatPercentage('0.6000');
        $this->ledger->beginTick([...$held, $sphere]);
        $this->ledger->markToMarket($sphere);
        $carrying = (float) $sphere->getListedStakesCarrying();
        $fraction = AnchorHoldings::forHolder('BRKW')['ALBT'];

        $albt->setSharesOutstanding('950000000');
        $this->ledger->settleShareChange($albt, 0.0, 0.0, 50_000_000.0, 200.0);

        $this->assertEqualsWithDelta(100_000_000_000.0, (float) $sphere->getCorporateTreasury(), 1.0, 'nothing tendered, nothing paid');
        $this->assertEqualsWithDelta($carrying, (float) $sphere->getListedStakesCarrying(), 1.0);
        $this->assertEqualsWithDelta($fraction / 0.95, AnchorStakeLedger::fraction($albt, 'BRKW'), 1e-9);
        $this->assertEqualsWithDelta(0.6 + $fraction - ($fraction / 0.95), (float) $albt->getPublicFloatPercentage(), 1e-4, 'the float gave up what the holder kept');
        $this->assertEqualsWithDelta(0.0, $this->ledger->markToMarket($sphere), 1.0, 'bought back at its price, the stake is worth what it was');
    }

    /** It tenders only what keeps the company trading, and is paid for that. */
    public function testTheHolderTendersOnlyWhatKeepsTheMinimumFloatTrading(): void
    {
        $sphere = $this->sphere();
        $sphere->setCorporateTreasury('100000000000.0000');
        $held = $this->flatBoard(200_000_000_000.0);
        $albt = $this->holding($held, 'ALBT');
        $fraction = AnchorHoldings::forHolder('BRKW')['ALBT'];
        $albt->setPublicFloatPercentage((string) (AnchorHoldings::MIN_TRADABLE_FLOAT + 0.05));
        $this->ledger->beginTick([...$held, $sphere]);
        $this->ledger->markToMarket($sphere);

        // A fifth of the company is retired: sitting it out would leave less than the minimum trading.
        $albt->setSharesOutstanding('800000000');
        $this->ledger->settleShareChange($albt, 0.0, 0.0, 200_000_000.0, 200.0);

        $ceiling = $fraction + 0.05;
        $tendered = ($fraction * 1_000_000_000.0) - ($ceiling * 800_000_000.0);
        $this->assertEqualsWithDelta(AnchorHoldings::MIN_TRADABLE_FLOAT, (float) $albt->getPublicFloatPercentage(), 1e-4);
        $this->assertEqualsWithDelta($ceiling, AnchorStakeLedger::fraction($albt, 'BRKW'), 1e-9);
        $this->assertEqualsWithDelta(100_000_000_000.0 + ($tendered * 200.0), (float) $sphere->getCorporateTreasury(), 1.0);
        $this->assertEqualsWithDelta(0.0, $this->ledger->markToMarket($sphere), 1.0, 'what it tendered was paid for, not lost');
    }

    /** The block holders settle off the market, so a buyback buys from the market only the float's part and theirs. */
    public function testOnlyTheFloatsPartOfAShareChangeGoesThroughTheMarket(): void
    {
        $free = StockBuilder::create('FREE')->withSharesOutstanding(1_000_000_000)->build();
        $free->setPublicFloatPercentage('0.7000');
        $this->assertEqualsWithDelta(70_000_000.0, AnchorStakeLedger::marketSharesOfBuyback($free, 100_000_000.0), 1.0);
        $this->assertEqualsWithDelta(70_000_000.0, AnchorStakeLedger::marketSharesOfIssue($free, 100_000_000.0), 1.0);

        $albt = StockBuilder::create('ALBT')->withSharesOutstanding(1_000_000_000)->build();
        $albt->setPublicFloatPercentage('0.4000');
        $fraction = AnchorHoldings::forHolder('BRKW')['ALBT'];
        // The anchor sits the buyback out, so the float sells its share too; an issue it buys its part of.
        $this->assertEqualsWithDelta((0.4 + $fraction) * 100_000_000.0, AnchorStakeLedger::marketSharesOfBuyback($albt, 100_000_000.0), 1.0);
        $this->assertEqualsWithDelta(0.4 * 100_000_000.0, AnchorStakeLedger::marketSharesOfIssue($albt, 100_000_000.0), 1.0);
    }

    /**
     * The holder is paid its fraction of a held company's dividend, which its book carries until its report books it.
     */
    public function testAHeldCompanysDividendIsPaidToTheHolderAndMovesNoValue(): void
    {
        $sphere = $this->sphere();
        $held = $this->flatBoard(200_000_000_000.0);
        $this->ledger->beginTick([...$held, $sphere]);
        $this->ledger->markToMarket($sphere);
        $before = $this->ledger->resolveMarkedBookValuePerShare($sphere);
        $fraction = AnchorHoldings::forHolder('BRKW')['ALBT'];

        $albt = $this->holding($held, 'ALBT');
        $this->ledger->accrueDividends($albt, 2.0, 1_000_000_000.0);
        $albt->setPrice('198');
        $this->ledger->beginTick([...$held, $sphere]);

        $paid = $fraction * 2.0 * 1_000_000_000.0;
        $this->assertEqualsWithDelta($paid, AnchorStakeLedger::dividendsReceivable($sphere), 1.0);
        $this->assertEqualsWithDelta((float) $before, (float) $this->ledger->resolveMarkedBookValuePerShare($sphere), 1e-6, 'ex-dividend, the stake fell by what was paid');

        $this->assertEqualsWithDelta($paid, $this->ledger->collectDividends($sphere), 1.0);
        $this->assertEqualsWithDelta(0.0, AnchorStakeLedger::dividendsReceivable($sphere), 1.0, 'booked once');
        $this->assertEqualsWithDelta($paid * 4.0, AnchorStakeLedger::trailingDividends($sphere), 1.0, 'the first quarter opens the trailing rate');
        $this->assertEqualsWithDelta(0.0, $this->ledger->collectDividends($sphere), 1.0);
    }

    /**
     * A report that pays a dividend and buys back shares pays the dividend on the shares of record: the holder that sat
     * the buyback out is paid on every share it holds, which its fraction of the smaller count shows once the buyback
     * settles.
     */
    public function testADividendBesideABuybackIsPaidOnTheSharesOfRecord(): void
    {
        $sphere = $this->sphere();
        $held = $this->flatBoard(200_000_000_000.0);
        $this->ledger->beginTick([...$held, $sphere]);
        $this->ledger->markToMarket($sphere);
        $fraction = AnchorHoldings::forHolder('BRKW')['ALBT'];

        $albt = $this->holding($held, 'ALBT');
        $albt->setSharesOutstanding('900000000');
        $this->ledger->accrueDividends($albt, 2.0, 1_000_000_000.0);
        $this->ledger->settleShareChange($albt, 0.0, 0.0, 100_000_000.0, 200.0);

        $this->assertEqualsWithDelta($fraction * 1_000_000_000.0 * 2.0, AnchorStakeLedger::dividendsReceivable($sphere), 1.0);
        $this->assertEqualsWithDelta(AnchorStakeLedger::dividendsReceivable($sphere), AnchorStakeLedger::fraction($albt, 'BRKW') * 900_000_000.0 * 2.0, 1.0);
    }

    /** @param list<Stock> $board */
    private function holding(array $board, string $ticker): Stock
    {
        foreach ($board as $stock) {
            if ($stock->getTicker() === $ticker) {
                return $stock;
            }
        }

        $this->fail("{$ticker} is not on the board");
    }

    /** Stakes are fractions of capitalisation precisely so a split cannot move them. */
    public function testASplitInAHoldingLeavesTheSphereUntouched(): void
    {
        $this->ledger->beginTick($this->flatBoard(200_000_000_000.0));
        $sphere = $this->sphere();
        $this->ledger->markToMarket($sphere);
        $before = (float) $sphere->getListedStakesCarrying();

        $split = [];
        foreach (array_keys(AnchorHoldings::forHolder('BRKW')) as $ticker) {
            $split[] = StockBuilder::create($ticker)
                ->withPrice(100.0)
                ->withSharesOutstanding(2_000_000_000)
                ->build();
        }
        $this->ledger->beginTick($split);

        $this->assertEqualsWithDelta($before, $this->ledger->resolveStakeValue($sphere), 1.0);
        $this->assertSame(0.0, $this->ledger->markToMarket($sphere));
    }

    /**
     * A holding that goes under takes its whole stake with it. Read from isBankrupt(), not from the zeroed price: this
     * is the largest single move a sphere's book can make.
     */
    public function testAHoldingsBankruptcyIsBookedAsTheLossItIs(): void
    {
        $capEach = 200_000_000_000.0;
        $this->ledger->beginTick($this->flatBoard($capEach));

        $sphere = $this->sphere();
        $this->ledger->markToMarket($sphere);
        $before = (float) $sphere->getListedStakesCarrying();
        $equityBefore = (float) $sphere->getTotalEquity();

        $failed = array_key_first(AnchorHoldings::forHolder('BRKW'));
        $this->assertNotNull($failed, 'BRKW declares its holdings');
        $board = [];

        foreach (array_keys(AnchorHoldings::forHolder('BRKW')) as $ticker) {
            $builder = StockBuilder::create($ticker)
                ->withPrice($capEach / 1_000_000_000.0)
                ->withSharesOutstanding(1_000_000_000);
            $stock = $builder->build();

            // Bankrupt but still quoted: the ledger must not need the price to have been zeroed.
            if ($ticker === $failed) {
                $stock->setIsBankrupt(true);
            }

            $board[] = $stock;
        }

        $this->ledger->beginTick($board);
        $moved = $this->ledger->markToMarket($sphere, 0.21);

        $lost = AnchorHoldings::forHolder('BRKW')[$failed] * $capEach;
        $this->assertEqualsWithDelta(-$lost, $moved, 1.0, 'The sphere must lose the whole stake, not the quoted price.');
        $this->assertEqualsWithDelta($before - $lost, (float) $sphere->getListedStakesCarrying(), 1.0);
        $this->assertLessThan($equityBefore, (float) $sphere->getTotalEquity());
    }

    /** Every other holding is untouched by one of them failing: the loss is the stake, not the portfolio. */
    public function testTheRestOfThePortfolioSurvivesAHoldingGoingUnder(): void
    {
        $capEach = 200_000_000_000.0;
        $failed = array_key_first(AnchorHoldings::forHolder('BRKW'));
        $this->assertNotNull($failed, 'BRKW declares its holdings');
        $board = [];

        foreach (array_keys(AnchorHoldings::forHolder('BRKW')) as $ticker) {
            $stock = StockBuilder::create($ticker)
                ->withPrice($capEach / 1_000_000_000.0)
                ->withSharesOutstanding(1_000_000_000)
                ->build();

            if ($ticker === $failed) {
                $stock->setIsBankrupt(true);
            }

            $board[] = $stock;
        }

        $this->ledger->beginTick($board);

        $surviving = array_sum(AnchorHoldings::forHolder('BRKW')) - AnchorHoldings::forHolder('BRKW')[$failed];
        $this->assertEqualsWithDelta($surviving * $capEach, $this->ledger->resolveStakeValue($this->sphere()), 1.0);
    }
}
