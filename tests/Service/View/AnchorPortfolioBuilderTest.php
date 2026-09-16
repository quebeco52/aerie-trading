<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Data\AnchorHoldings;
use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Service\Market\PriceChangeFeed;
use App\Service\View\AnchorPortfolioBuilder;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The holdings table on a sphere's page: without it the player sees a trust move 4% on a day it never
 * traded, with no way to find out why.
 */
#[AllowMockObjectsWithoutExpectations]
class AnchorPortfolioBuilderTest extends TestCase
{
    /** @param list<Stock> $held */
    private function builder(array $held): AnchorPortfolioBuilder
    {
        $stocks = $this->createMock(StockRepository::class);
        $stocks->method('findBy')->willReturn($held);

        $feed = $this->createMock(PriceChangeFeed::class);
        $feed->method('changeForTicker')->willReturn(1.5);

        return new AnchorPortfolioBuilder($stocks, $feed);
    }

    /** @return list<Stock> */
    private function board(float $capEach = 200_000_000_000.0): array
    {
        $board = [];

        foreach (array_keys(AnchorHoldings::forHolder('BRKW')) as $ticker) {
            $board[] = StockBuilder::create($ticker)
                ->withPrice($capEach / 1_000_000_000.0)
                ->withSharesOutstanding(1_000_000_000)
                ->build();
        }

        return $board;
    }

    private function sphere(): Stock
    {
        return StockBuilder::create('BRKW')->withTotalEquity(1_880_000_000_000.0)->build();
    }

    public function testEveryDeclaredStakeIsShownAndValuedAtTheHoldingsPrice(): void
    {
        $portfolio = $this->builder($this->board())->build($this->sphere());

        $this->assertNotNull($portfolio);
        $this->assertCount(count(AnchorHoldings::forHolder('BRKW')), $portfolio['holdings']);

        foreach ($portfolio['holdings'] as $holding) {
            $expected = 200_000_000_000.0 * AnchorHoldings::forHolder('BRKW')[$holding['ticker']];
            $this->assertEqualsWithDelta($expected, $holding['value'], 1.0);
        }

        $this->assertEqualsWithDelta(
            array_sum(array_column($portfolio['holdings'], 'value')),
            $portfolio['totalValue'],
            1.0
        );
    }

    public function testHoldingsAreOrderedLargestFirstAndSharesSumToOne(): void
    {
        $portfolio = $this->builder($this->board())->build($this->sphere());

        $values = array_column($portfolio['holdings'], 'value');
        $sorted = $values;
        rsort($sorted);
        $this->assertSame($sorted, $values, 'The table answers what the trust is a bet on, so it ranks by size.');

        $this->assertEqualsWithDelta(1.0, array_sum(array_column($portfolio['holdings'], 'shareOfPortfolio')), 1e-9);
    }

    /** A delisted holding stays on the table at zero, so the loss is legible rather than vanishing. */
    public function testADelistedHoldingIsShownAtZero(): void
    {
        $board = $this->board();
        $board[0]->setIsBankrupt(true);

        $portfolio = $this->builder($board)->build($this->sphere());
        $dead = array_values(array_filter($portfolio['holdings'], static fn (array $h): bool => $h['isBankrupt']));

        $this->assertCount(1, $dead);
        $this->assertSame(0.0, $dead[0]['value']);
        $this->assertNull($dead[0]['changePercent']);
    }

    public function testAFirmHoldingNothingHasNoTable(): void
    {
        $this->assertNull($this->builder([])->build(StockBuilder::create('CBIL')->build()));
    }
}
