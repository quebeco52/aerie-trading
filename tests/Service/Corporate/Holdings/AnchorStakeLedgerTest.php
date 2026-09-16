<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate\Holdings;

use App\Data\AnchorHoldings;
use App\Entity\Stock;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The mark on a sphere's listed anchor stakes: when the companies it holds fall, its net asset value falls
 * with them — the one behaviour the class could not previously express.
 */
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

    /** Every holding at one capitalisation, so the stake value is checkable by hand. */
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
        $this->assertEqualsWithDelta($gain * 0.25, (float) $sphere->getDeferredTaxLiability(), 1.0);
        $this->assertEqualsWithDelta($equity + ($gain * 0.75), (float) $sphere->getTotalEquity(), 1.0);
    }

    /** The overhang unwinds when the portfolio falls, and can never release tax that was never postponed. */
    public function testTheOverhangUnwindsAndNeverGoesNegative(): void
    {
        $this->ledger->beginTick($this->flatBoard(200_000_000_000.0));
        $sphere = $this->sphere();
        $this->ledger->markToMarket($sphere, 0.25);

        $this->ledger->beginTick($this->flatBoard(400_000_000_000.0));
        $this->ledger->markToMarket($sphere, 0.25);
        $peakDeferred = (float) $sphere->getDeferredTaxLiability();
        $this->assertGreaterThan(0.0, $peakDeferred);

        // Straight back down, and further than it came up.
        $this->ledger->beginTick($this->flatBoard(50_000_000_000.0));
        $this->ledger->markToMarket($sphere, 0.25);

        $this->assertSame(0.0, (float) $sphere->getDeferredTaxLiability());
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
                'A mark moved the asset side without an equal claim against it.'
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
    }

    public function testAnUnmarkedFirmFallsBackToItsFiledBook(): void
    {
        $this->ledger->beginTick($this->flatBoard(200_000_000_000.0));

        $this->assertNull($this->ledger->resolveMarkedBookValuePerShare($this->sphere()));
        $this->assertNull($this->ledger->resolveMarkedBookValuePerShare(StockBuilder::create('CBIL')->build()));
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

    /** A holding that goes under takes its whole stake with it. Read from isBankrupt(), not from the zeroed price: this is the largest single move a sphere's book can make. */
    public function testAHoldingsBankruptcyIsBookedAsTheLossItIs(): void
    {
        $capEach = 200_000_000_000.0;
        $this->ledger->beginTick($this->flatBoard($capEach));

        $sphere = $this->sphere();
        $this->ledger->markToMarket($sphere);
        $before = (float) $sphere->getListedStakesCarrying();
        $equityBefore = (float) $sphere->getTotalEquity();

        $failed = array_key_first(AnchorHoldings::forHolder('BRKW'));
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
