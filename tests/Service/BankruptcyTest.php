<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Entity\TradeOrder;
use App\Repository\StockRepository;
use App\Repository\TradeOrderRepository;
use App\Entity\User;
use App\Entity\UserStock;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\MarketOperator;
use App\Service\Market\StockTracker;
use App\Service\Market\TradeExecutionService;
use App\Service\Math\MathUtility;
use App\Service\User\Portfolio;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class BankruptcyTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManagerMock;
    private LoggerInterface&Stub $loggerMock;
    private MarketEventPublisher&MockObject $marketEventMock;
    private DebtEngine&MockObject $debtEngineMock;
    private MathUtility&Stub $mathUtilityMock;
    private Connection&MockObject $connectionMock;
    private TradeOrderRepository&MockObject $tradeOrderRepoMock;

    protected function setUp(): void
    {
        $this->entityManagerMock = $this->createMock(EntityManagerInterface::class);
        $this->loggerMock = $this->createStub(LoggerInterface::class);
        $this->marketEventMock = $this->createMock(MarketEventPublisher::class);
        $this->debtEngineMock = $this->createMock(DebtEngine::class);
        $this->mathUtilityMock = $this->createStub(MathUtility::class);
        $this->connectionMock = $this->createMock(Connection::class);
        $this->tradeOrderRepoMock = $this->createMock(TradeOrderRepository::class);

        $this->entityManagerMock->method('getConnection')->willReturn($this->connectionMock);
    }

    public function testMarketOperatorKillsInsolventCompanyAndCancelsOrdersWithRefund(): void
    {
        $stock = new Stock();
        $stock->setTicker('DEAD');
        $stock->setName('Dead Corp');
        $stock->setPrice('10.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setOperatingMargin('0.10');
        $stock->setTotalRevenue('1000000');
        $stock->setCreditRating('BBB');

        $this->assertFalse($stock->isBankrupt());

        // Insolvent Altman Z-Score
        $this->debtEngineMock->method('calculateAltmanZScore')->willReturn([
            'z_score' => -1.5,
            'zone' => 'Distress',
            'is_bankrupt' => true,
        ]);

        // Mock open buy order with escrow
        $buyer = new User();
        $buyer->setCashBalance('500.00');

        $buyOrder = new TradeOrder();
        $buyOrder->setUser($buyer);
        $buyOrder->setTicker('DEAD');
        $buyOrder->setAction('BUY');
        $buyOrder->setOrderType('LIMIT');
        $buyOrder->setQuantity(10);
        $buyOrder->setLimitPrice('10.00');
        $buyOrder->setStatus('OPEN');

        $sellOrder = new TradeOrder();
        $sellOrder->setUser($buyer);
        $sellOrder->setTicker('DEAD');
        $sellOrder->setAction('SELL');
        $sellOrder->setOrderType('LIMIT');
        $sellOrder->setQuantity(5);
        $sellOrder->setLimitPrice('12.00');
        $sellOrder->setStatus('OPEN');

        $this->entityManagerMock->expects($this->once())
            ->method('getRepository')
            ->with(TradeOrder::class)
            ->willReturn($this->tradeOrderRepoMock);

        $this->tradeOrderRepoMock->expects($this->once())
            ->method('findOpenByTicker')
            ->with('DEAD')
            ->willReturn([$buyOrder, $sellOrder]);

        // Connection should only execute DELETE on user_stocks, NOT on corporate_report, stock_history, stock_events
        $this->connectionMock->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('DELETE FROM user_stocks'),
                $this->anything()
            );

        $this->marketEventMock->expects($this->once())
            ->method('publish')
            ->with($stock, 'BANKRUPTCY', $this->stringContains('Dead Corp'), -100.00)
            ->willReturn(['type' => 'BANKRUPTCY']);

        $operator = new MarketOperator(
            $this->entityManagerMock,
            $this->loggerMock,
            $this->marketEventMock,
            $this->debtEngineMock,
            $this->mathUtilityMock
        );

        $events = $operator->enforceMarketStability([$stock], new MacroStateDTO());

        $this->assertCount(1, $events);
        $this->assertTrue($stock->isBankrupt());
        $this->assertSame('D', $stock->getCreditRating());
        $this->assertEquals('0.00000000', $stock->getPrice());
        $this->assertEquals('0.0000', $stock->getCurrentVolatility());
        $this->assertEquals('CANCELLED', $buyOrder->getStatus());
        $this->assertEquals('CANCELLED', $sellOrder->getStatus());
        // Buyer got refunded $100 (10 * $10.00) added to $500 = $600
        $this->assertEquals('600.0000', (string) $buyer->getCashBalance());
    }

    public function testMarketOperatorSkipsAlreadyBankruptStock(): void
    {
        $stock = new Stock();
        $stock->setTicker('DEAD');
        $stock->setName('Dead Corp');
        $stock->setIsBankrupt(true);

        $this->debtEngineMock->expects($this->never())->method('calculateAltmanZScore');

        $operator = new MarketOperator(
            $this->entityManagerMock,
            $this->loggerMock,
            $this->marketEventMock,
            $this->debtEngineMock,
            $this->mathUtilityMock
        );

        $events = $operator->enforceMarketStability([$stock], new MacroStateDTO());
        $this->assertEmpty($events);
    }

    /**
     * Altman's X3 is EBIT over the trailing year. Annualizing the latest quarter's margin turned one
     * catastrophic print into a liquidation: a firm that earned $150M in each of three quarters and then
     * lost $80M in a fire-sale quarter must be judged on the year, not on the quarter times four.
     */
    public function testSolvencyTestReadsTheTrailingYearOnceFourQuartersHaveBeenReported(): void
    {
        $stock = new Stock();
        $stock->setTicker('YEAR');
        $stock->setName('Trailing Year Industries');
        $stock->setPrice('10.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalRevenue('1000000000');
        $stock->setOperatingMargin('0.15');
        $stock->setReportedOperatingMargin(-0.08);
        // Three sound quarters and one loss, reported as net income.
        $stock->setQuarterlyNetIncomeHistory([118_500_000.0, 118_500_000.0, 118_500_000.0, -80_000_000.0]);

        $capturedEbit = null;
        $this->debtEngineMock->method('calculateAltmanZScore')
            ->willReturnCallback(function (Stock $s, float $ebit) use (&$capturedEbit): array {
                $capturedEbit = $ebit;

                return ['z_score' => 5.0, 'zone' => 'Safe', 'is_bankrupt' => false];
            });

        $operator = new MarketOperator(
            $this->entityManagerMock,
            $this->loggerMock,
            $this->marketEventMock,
            $this->debtEngineMock,
            $this->mathUtilityMock
        );

        $operator->enforceMarketStability([$stock], new MacroStateDTO(corporateTaxRate: 0.21));

        // Trailing net income $275.5M grossed up for tax at 21% is $348.7M of pre-tax income (no interest
        // on the stub), not the -$80M x 4 the last quarter alone would have said.
        $this->assertNotNull($capturedEbit);
        $this->assertEqualsWithDelta(275_500_000.0 / 0.79, $capturedEbit, 1.0);
    }

    public function testSolvencyTestUsesTheMarginTheFirmActuallyReported(): void
    {
        $stock = new Stock();
        $stock->setTicker('ROTS');
        $stock->setName('Rotting Industries');
        $stock->setPrice('10.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalRevenue('1000000000');

        // The structural margin is the slow parameter only asset reinvestment moves; the firm's realized
        // margin has collapsed to a loss. Solvency must be judged on the loss, not on the plant.
        $stock->setOperatingMargin('0.15');
        $stock->setReportedOperatingMargin(-0.08);

        $capturedEbit = null;
        $this->debtEngineMock->method('calculateAltmanZScore')
            ->willReturnCallback(function (Stock $s, float $ebit) use (&$capturedEbit): array {
                $capturedEbit = $ebit;

                return ['z_score' => 5.0, 'zone' => 'Safe', 'is_bankrupt' => false];
            });

        $operator = new MarketOperator(
            $this->entityManagerMock,
            $this->loggerMock,
            $this->marketEventMock,
            $this->debtEngineMock,
            $this->mathUtilityMock
        );

        $operator->enforceMarketStability([$stock], new MacroStateDTO());

        // Reading the structural margin would have handed the Altman test a healthy +$150M profit on a firm
        // that is losing $80M, keeping a failing company solvent on paper for as long as its plant held up.
        $this->assertNotNull($capturedEbit);
        $this->assertEqualsWithDelta(-80000000.0, $capturedEbit, 1.0);
    }

    /**
     * A payment default liquidates the firm even though the Altman test says it is solvent. These are two
     * genuinely different ways to fail, and the second is the more common one in reality: the assets were
     * fine, the refinancing simply was not there on the day the principal came due.
     */
    public function testPaymentDefaultLiquidatesAnOtherwiseSolventCompany(): void
    {
        $stock = new Stock();
        $stock->setTicker('DFLT');
        $stock->setName('Defaulted Holdings');
        $stock->setPrice('25.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalRevenue('1000000000');
        $stock->setOperatingMargin('0.15');
        $stock->setPaymentDefault(true);

        // Comfortably solvent on the balance sheet: only the missed payment can kill it.
        $this->debtEngineMock->method('calculateAltmanZScore')
            ->willReturn(['z_score' => 8.0, 'zone' => 'Safe', 'is_bankrupt' => false]);

        $this->entityManagerMock->method('getRepository')->willReturn($this->createConfiguredStub(
            TradeOrderRepository::class,
            ['findOpenByTicker' => []]
        ));

        $operator = new MarketOperator(
            $this->entityManagerMock,
            $this->loggerMock,
            $this->marketEventMock,
            $this->debtEngineMock,
            $this->mathUtilityMock
        );

        $operator->enforceMarketStability([$stock], new MacroStateDTO());

        $this->assertTrue($stock->isBankrupt(), 'a missed principal payment must end the company');
        $this->assertEquals('0.00000000', $stock->getPrice());
    }

    /**
     * The flag is what kills, not the mere existence of debt: a firm that met its obligations survives.
     */
    public function testSolventCompanyThatMetItsObligationsSurvives(): void
    {
        $stock = new Stock();
        $stock->setTicker('PAID');
        $stock->setName('Paid Up Industries');
        $stock->setPrice('25.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalRevenue('1000000000');
        $stock->setOperatingMargin('0.15');

        $this->debtEngineMock->method('calculateAltmanZScore')
            ->willReturn(['z_score' => 8.0, 'zone' => 'Safe', 'is_bankrupt' => false]);

        $operator = new MarketOperator(
            $this->entityManagerMock,
            $this->loggerMock,
            $this->marketEventMock,
            $this->debtEngineMock,
            $this->mathUtilityMock
        );

        $operator->enforceMarketStability([$stock], new MacroStateDTO());

        $this->assertFalse($stock->isBankrupt());
    }

    public function testSolvencyTestFallsBackToTheStructuralMarginBeforeTheFirstReport(): void
    {
        $stock = new Stock();
        $stock->setTicker('NEWC');
        $stock->setName('Newly Listed Corp');
        $stock->setPrice('10.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalRevenue('1000000000');
        $stock->setOperatingMargin('0.15');

        $this->assertNull($stock->getReportedOperatingMargin());

        $capturedEbit = null;
        $this->debtEngineMock->method('calculateAltmanZScore')
            ->willReturnCallback(function (Stock $s, float $ebit) use (&$capturedEbit): array {
                $capturedEbit = $ebit;

                return ['z_score' => 5.0, 'zone' => 'Safe', 'is_bankrupt' => false];
            });

        $operator = new MarketOperator(
            $this->entityManagerMock,
            $this->loggerMock,
            $this->marketEventMock,
            $this->debtEngineMock,
            $this->mathUtilityMock
        );

        $operator->enforceMarketStability([$stock], new MacroStateDTO());

        $this->assertEqualsWithDelta(150000000.0, $capturedEbit, 1.0);
    }

    public function testTradeExecutionServiceBlocksTradingOnBankruptStock(): void
    {
        $user = new User();
        $user->setCashBalance('1000.00');

        $stock = new Stock();
        $stock->setTicker('DEAD');
        $stock->setIsBankrupt(true);

        $stockRepo = $this->createStub(StockRepository::class);
        $stockRepo->method('findOneByTicker')->willReturn($stock);

        $this->entityManagerMock->expects($this->once())
            ->method('getRepository')
            ->with(Stock::class)
            ->willReturn($stockRepo);

        $portfolio = $this->createStub(Portfolio::class);
        $redis = $this->createStub(\Redis::class);

        // Ticker resolution now lives in AssetResolver, sharing the same EntityManager, so the single
        // getRepository(Stock::class) lookup asserted above is the resolver's.
        $tradeService = new TradeExecutionService(
            $this->entityManagerMock,
            $portfolio,
            $redis,
            new \Psr\Log\NullLogger(),
            new \App\Service\Market\AssetResolver($this->entityManagerMock),
            new \App\Service\Market\LiquidityEngine(new \App\Service\Math\MathUtility()),
            new \App\Service\Market\Flow\InMemoryOrderFlowStore(),
            new \App\Service\Market\MarginEngine($this->entityManagerMock),
            new \App\Service\Market\SecuritiesLendingDesk()
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Trading is halted for DEAD. The company is bankrupt.');

        $tradeService->executeOrder($user, 'DEAD', 'BUY', 'MARKET', 10);
    }

    public function testStockTrackerBypassesSimulationForBankruptStock(): void
    {
        $stock = new Stock();
        $stock->setTicker('DEAD');
        $stock->setSector('Technology');
        $stock->setIsBankrupt(true);
        $stock->setPrice('0.00');
        $stock->setSharesOutstanding('1000000');

        $marketEngine = $this->createMock(\App\Service\Market\MarketEngine::class);
        $earningsEngine = $this->createMock(EarningsEngine::class);
        $corpActionEngine = $this->createMock(\App\Service\Corporate\CorporateActionEngine::class);
        $maEngine = $this->createStub(\App\Service\Corporate\MergerAndAcquisitionEngine::class);
        $corpMetrics = $this->createStub(\App\Service\Math\CorporateMetrics::class);

        $marketEngine->expects($this->never())->method('calculateNextPrice');
        $earningsEngine->expects($this->never())->method('calculate');
        $corpActionEngine->expects($this->never())->method('processSplits');

        $tracker = new StockTracker(
            $this->entityManagerMock,
            $marketEngine,
            $earningsEngine,
            $corpActionEngine,
            $maEngine,
            $this->marketEventMock,
            $this->debtEngineMock,
            $this->mathUtilityMock,
            $corpMetrics,
            new \App\Service\Market\LiquidityEngine(new \App\Service\Math\MathUtility()),
            new \App\Service\Market\Flow\InMemoryOrderFlowStore(),
            new \App\Service\Market\Agent\AgentFlowEngine(
                new \App\Service\Market\Agent\AgentPopulation(),
                new \App\Service\Market\Agent\InMemoryAgentStateStore(),
                new \App\Service\Market\Flow\InMemoryOrderFlowStore(),
                []
            ),
            // Stubbed maths, so checkProbability() is false and no succession fires in tests that
            // are about something else.
            new \App\Service\Corporate\ManagementSuccessionEngine(
                $this->createStub(\App\Service\Event\MarketEventPublisher::class),
                $this->createStub(\App\Service\Math\MathUtility::class)
            )
        );

        $result = $tracker->updateStocks([$stock], 0.01, false);

        $this->assertCount(1, $result['updates']);
        $update = $result['updates'][0];
        $this->assertTrue($update['is_bankrupt']);
        $this->assertEquals(0.0, $update['price']);
        $this->assertEquals(0.0, $update['market_cap']);
        $this->assertEquals(0.0, $result['total_cap']);
    }

    public function testCorporateActionEngineNeverSplitsBankruptOrZeroPriceStock(): void
    {
        $stock = new Stock();
        $stock->setTicker('DEAD');
        $stock->setIsBankrupt(true);
        $stock->setSharesOutstanding('1000000');

        $ledger = $this->createStub(\App\Service\Corporate\CorporateLedgerService::class);
        $redis = $this->createStub(\Redis::class);

        $actionEngine = new \App\Service\Corporate\CorporateActionEngine(
            $ledger,
            $this->marketEventMock,
            $redis
        );

        $result = $actionEngine->processSplits($stock, 0.0, 1000000.0);
        $this->assertEquals(0.0, $result['price']);
        $this->assertEquals(1000000.0, $result['shares']);
        $this->assertNull($result['event']);

        // Test non-bankrupt stock with $0.00 price also does not trigger reverse split
        $aliveStock = new Stock();
        $aliveStock->setTicker('ALIVE');
        $aliveStock->setIsBankrupt(false);
        $aliveStock->setSharesOutstanding('1000000');

        $resultZero = $actionEngine->processSplits($aliveStock, 0.0, 1000000.0);
        $this->assertEquals(0.0, $resultZero['price']);
        $this->assertEquals(1000000.0, $resultZero['shares']);
        $this->assertNull($resultZero['event']);
    }
    /**
     * Consolidation is the capacity balance's job now: the failed firm's plant leaves the industry ledger
     * the moment it fails (its demand stays), so survivors sell into a tighter market at their next report. The old rule
     * handed peers a slice of the failed firm's addressable market instead; nothing touches samRatio.
     */
    public function testAFailedFirmIsRetiredFromTheIndustryLedgerAndPeersKeepTheirOwnMarket(): void
    {
        $build = function (string $ticker, string $industry = 'Airlines'): Stock {
            $stock = new Stock();
            $stock->setTicker($ticker);
            $stock->setName($ticker . ' Corp');
            $stock->setIndustry($industry);
            $stock->setPrice('10.00');
            $stock->setSharesOutstanding('1000000');
            $stock->setOperatingMargin('0.10');
            $stock->setTotalRevenue('1000000');
            $stock->setSamRatio('1.00');
            return $stock;
        };
        $failed = $build('DEAD');
        $survivor = $build('BIG');

        $store = new \App\Service\Corporate\Industry\InMemoryIndustryShareStore();
        $ledger = new \App\Service\Corporate\Industry\IndustryShareLedger($store);
        $ledger->resolveIndustryCapacityRatio($survivor, 1_000_000.0, 0.5, 1.0, 0.0, 0.0, 1, 252);
        $ledger->resolveIndustryCapacityRatio($failed, 1_000_000.0, 0.5, 1.0, 0.0, 0.0, 2, 252);

        $this->debtEngineMock->method('calculateAltmanZScore')->willReturnCallback(
            static fn (Stock $stock): array => ['z_score' => $stock->getTicker() === 'DEAD' ? -1.5 : 5.0, 'zone' => 'Safe', 'is_bankrupt' => $stock->getTicker() === 'DEAD']
        );
        $this->entityManagerMock->method('getRepository')->willReturn($this->tradeOrderRepoMock);
        $this->tradeOrderRepoMock->method('findOpenByTicker')->willReturn([]);
        $this->marketEventMock->method('publish')->willReturn(['type' => 'BANKRUPTCY']);

        $operator = new MarketOperator($this->entityManagerMock, $this->loggerMock, $this->marketEventMock, $this->debtEngineMock, $this->mathUtilityMock, $ledger);
        $operator->enforceMarketStability([$failed, $survivor], new MacroStateDTO());

        $this->assertTrue($failed->isBankrupt());
        $this->assertSame(0.0, $store->readIndustry('Airlines')['DEAD']['capacity'], 'the failed firm\'s plant is gone');
        $this->assertSame(0.0, $store->readIndustry('Airlines')['DEAD']['revenue'], 'and so are its sales');
        // Half the market's plant is gone against unchanged demand: the survivor's next report clears tight.
        $this->assertEqualsWithDelta(0.5, $ledger->resolveIndustryCapacityRatio($survivor, 1_000_000.0, 0.5, 1.0, 0.0, 0.0, 70, 252), 1e-9);
        $this->assertEqualsWithDelta(1.00, (float) $survivor->getSamRatio(), 1e-9, 'the addressable market is no longer transferred');
    }

}
