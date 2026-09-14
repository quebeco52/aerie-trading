<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Entity\Etf;
use App\Entity\Stock;
use App\Entity\User;
use App\Entity\TradeOrder;
use App\Entity\UserStock;
use App\Repository\HoldingRepository;
use App\Repository\TradeOrderRepository;
use App\Service\User\CostBasisCalculator;
use App\Service\User\DividendIncomeCalculator;
use App\Service\View\ViewerPositionBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ViewerPositionBuilderTest extends TestCase
{
    private HoldingRepository&\PHPUnit\Framework\MockObject\MockObject $holdings;
    private TradeOrderRepository&\PHPUnit\Framework\MockObject\MockObject $orders;
    /** Both calculators are final by design, so the real ones run over fixtures. */
    private CostBasisCalculator $costBasis;
    private DividendIncomeCalculator $dividendIncome;

    /** @var list<array{ticker: string, total: float}> */
    private array $dividendRows = [];

    protected function setUp(): void
    {
        $this->holdings = $this->createMock(HoldingRepository::class);
        $this->orders = $this->createMock(TradeOrderRepository::class);
        $this->costBasis = new CostBasisCalculator();

        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(fn (): array => $this->dividendRows);
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $this->dividendIncome = new DividendIncomeCalculator($entityManager);

        $this->orders->method('findOpenForUserAndTicker')->willReturn([]);
        $this->orders->method('findSettledForUserAndTicker')->willReturn([]);
    }

    /** A filled order, as the cost-basis calculator reads one. */
    private function fill(string $action, int $quantity, float $price, string $ticker = 'LAKE'): TradeOrder
    {
        $order = new TradeOrder();
        $order->setTicker($ticker);
        $order->setAction($action);
        $order->setQuantity($quantity);
        $order->setFilledQuantity($quantity);
        $order->setExecutionPrice((string) $price);
        $order->setStatus(TradeOrder::STATUS_FILLED);

        return $order;
    }

    /** @param list<TradeOrder> $fills */
    private function fillsOnFile(array $fills): void
    {
        $this->orders->method('findFilledForUserAndTicker')->willReturn($fills);
    }

    private function builder(): ViewerPositionBuilder
    {
        return new ViewerPositionBuilder($this->holdings, $this->orders, $this->costBasis, $this->dividendIncome);
    }

    private function stock(float $price): Stock
    {
        $stock = new Stock();
        $stock->setTicker('LAKE');
        $stock->setPrice((string) $price);

        return $stock;
    }

    private function holding(int $quantity): UserStock
    {
        $holding = new UserStock();
        $holding->setQuantity($quantity);

        return $holding;
    }

    public function testASignedOutVisitorIsQuotedNoPositionAndNoOrders(): void
    {
        $this->fillsOnFile([]);
        $this->holdings->expects($this->never())->method('findStockHolding');

        $position = $this->builder()->build($this->stock(100.0), 'LAKE', null);

        $this->assertSame(0, $position['userQuantity']);
        $this->assertSame(0.0, $position['userUnrealizedPnL']);
        $this->assertSame(0.0, $position['userDividendIncome']);
        $this->assertSame([], $position['openOrders']);
        $this->assertSame([], $position['userTrades']);
    }

    public function testUnrealisedProfitIsMarkedAgainstTheWeightedAverageCost(): void
    {
        $this->holdings->method('findStockHolding')->willReturn($this->holding(10));
        $this->fillsOnFile([$this->fill('BUY', 10, 80.0)]);

        $position = $this->builder()->build($this->stock(100.0), 'LAKE', new User());

        $this->assertSame(10, $position['userQuantity']);
        $this->assertSame(80.0, $position['userAvgCost']);
        $this->assertEqualsWithDelta(200.0, $position['userUnrealizedPnL'], 1e-9);
        $this->assertEqualsWithDelta(25.0, $position['userUnrealizedPnLPercent'], 1e-9);
    }

    public function testALossIsMarkedTheSameWay(): void
    {
        $this->holdings->method('findStockHolding')->willReturn($this->holding(4));
        $this->fillsOnFile([$this->fill('BUY', 4, 150.0)]);

        $position = $this->builder()->build($this->stock(100.0), 'LAKE', new User());

        $this->assertEqualsWithDelta(-200.0, $position['userUnrealizedPnL'], 1e-9);
        $this->assertEqualsWithDelta(-100.0 / 3.0, $position['userUnrealizedPnLPercent'], 1e-9);
    }

    /**
     * Income already received survives selling out of a position. Reading it only for an open
     * position would hide cash the viewer actually holds.
     */
    public function testDividendIncomeIsReportedForAClosedPositionToo(): void
    {
        $this->holdings->method('findStockHolding')->willReturn(null);
        $this->fillsOnFile([]);
        $this->dividendRows = [['ticker' => 'LAKE', 'total' => 412.50]];

        $position = $this->builder()->build($this->stock(100.0), 'LAKE', new User());

        $this->assertSame(0, $position['userQuantity']);
        $this->assertSame(412.50, $position['userDividendIncome']);
    }

    public function testAFundPositionIsReadFromTheFundHoldings(): void
    {
        $this->fillsOnFile([]);
        $this->holdings->expects($this->once())->method('findEtfHolding')->willReturn(null);
        $this->holdings->expects($this->never())->method('findStockHolding');

        $etf = new Etf();
        $etf->setPrice('50.00');

        $this->builder()->build($etf, 'LBI', new User());
    }

    /**
     * With no fills on file the calculator returns null, and the page falls back to the traded price
     * rather than marking the position against a cost of zero.
     */
    public function testAPositionWithNoFillsOnFileFallsBackToTheTradedPrice(): void
    {
        $this->holdings->method('findStockHolding')->willReturn($this->holding(5));
        $this->fillsOnFile([]);

        $position = $this->builder()->build($this->stock(100.0), 'LAKE', new User());

        $this->assertSame(100.0, $position['userAvgCost']);
        $this->assertSame(0.0, $position['userUnrealizedPnL']);
    }

    public function testAShortPositionIsNotMarkedAsALongOne(): void
    {
        $this->holdings->method('findStockHolding')->willReturn($this->holding(-10));
        $this->fillsOnFile([$this->fill('SHORT', 10, 80.0)]);

        $position = $this->builder()->build($this->stock(100.0), 'LAKE', new User());

        $this->assertSame(-10, $position['userQuantity']);
        $this->assertSame(0.0, $position['userUnrealizedPnL'], 'A short is marked by the margin surfaces, not here.');
    }
}
