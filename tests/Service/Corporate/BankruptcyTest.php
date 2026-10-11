<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\DTO\GoingConcernDTO;
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
use App\Service\Corporate\FailureSweep;
use App\Service\Market\Pricing\StockTracker;
use App\Service\Market\Trading\TradeExecutionService;
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
    private Connection&MockObject $connectionMock;
    private TradeOrderRepository&MockObject $tradeOrderRepoMock;

    protected function setUp(): void
    {
        $this->entityManagerMock = $this->createMock(EntityManagerInterface::class);
        $this->loggerMock = $this->createStub(LoggerInterface::class);
        $this->marketEventMock = $this->createMock(MarketEventPublisher::class);
        $this->debtEngineMock = $this->createMock(DebtEngine::class);
        $this->connectionMock = $this->createMock(Connection::class);
        $this->tradeOrderRepoMock = $this->createMock(TradeOrderRepository::class);

        $this->entityManagerMock->method('getConnection')->willReturn($this->connectionMock);
    }

    /**
     * A firm whose default has outlasted its cure period and whose business no longer covers its cash
     * operating costs is liquidated under Chapter 7: open orders are cancelled with buy escrow refunded, the
     * positions are wiped and the company leaves the board.
     */
    public function testANonViableFirmPastItsCureIsLiquidatedAndItsOrdersRefunded(): void
    {
        $stock = $this->buildDefaulter('DEAD', 'Dead Corp', quartersInDefault: 2);
        $this->assertFalse($stock->isBankrupt());

        $this->debtEngineMock->expects($this->never())->method('calculateAltmanZScore');
        $this->debtEngineMock->method('assessGoingConcern')->willReturn($this->going(ebit: -120_000_000.0, ebitda: -80_000_000.0, assetValue: 0.0, cash: 0.0, claims: 2_000_000_000.0));

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
            ->with($stock, 'BANKRUPTCY', $this->logicalAnd($this->stringContains('Dead Corp'), $this->stringContains('Chapter 7')), -100.00)
            ->willReturn(['type' => 'BANKRUPTCY']);

        $operator = new FailureSweep(
            $this->entityManagerMock,
            $this->loggerMock,
            $this->marketEventMock,
            $this->debtEngineMock
        );

        $events = $operator->sweep([$stock], new MacroStateDTO());

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

    /**
     * Only a resting limit buy escrowed cash, so only it is refunded, and the refund pays the margin loan down
     * first, as the player's own cancel does. A buy stop-limit carries a limit price but escrowed nothing.
     */
    public function testALiquidationRefundsOnlyEscrowedBuysAndRepaysTheMarginLoanFirst(): void
    {
        $stock = $this->buildDefaulter('DEAD', 'Dead Corp', quartersInDefault: 2);
        $this->debtEngineMock->method('assessGoingConcern')->willReturn($this->going(ebit: -120_000_000.0, ebitda: -80_000_000.0, assetValue: 0.0, cash: 0.0, claims: 2_000_000_000.0));

        $buyer = new User();
        $buyer->setCashBalance('500.00');
        $buyer->setMarginDebit('30.00');

        $limitBuy = (new TradeOrder())->setUser($buyer)->setTicker('DEAD')->setAction('BUY')->setOrderType('LIMIT')
            ->setQuantity(10)->setLimitPrice('10.00')->setStatus('OPEN');
        $stopLimitBuy = (new TradeOrder())->setUser($buyer)->setTicker('DEAD')->setAction('BUY')->setOrderType('STOP_LIMIT')
            ->setQuantity(5)->setLimitPrice('11.00')->setStopPrice('10.50')->setStatus('OPEN');

        $this->entityManagerMock->method('getRepository')->willReturn($this->tradeOrderRepoMock);
        $this->tradeOrderRepoMock->method('findOpenByTicker')->willReturn([$limitBuy, $stopLimitBuy]);
        $this->marketEventMock->method('publish')->willReturn(['type' => 'BANKRUPTCY']);

        (new FailureSweep($this->entityManagerMock, $this->loggerMock, $this->marketEventMock, $this->debtEngineMock))
            ->sweep([$stock], new MacroStateDTO());

        $this->assertSame('CANCELLED', $stopLimitBuy->getStatus());
        // $100 of escrow back: $30 clears the loan, $70 reaches cash. The stop-limit's $55 is not paid out.
        $this->assertEquals('0.00', (string) $buyer->getMarginDebit());
        $this->assertEquals('570.0000', (string) $buyer->getCashBalance());
    }

    /** A liquidation closes every position, long or short, on a settlement order and tells its holder, before the wipe. */
    public function testALiquidationSettlesAndTellsEachHolderBeforeThePositionsAreWiped(): void
    {
        $stock = $this->buildDefaulter('DEAD', 'Dead Corp', quartersInDefault: 2);
        $this->debtEngineMock->method('assessGoingConcern')->willReturn($this->going(ebit: -120_000_000.0, ebitda: -80_000_000.0, assetValue: 0.0, cash: 0.0, claims: 2_000_000_000.0));
        $this->entityManagerMock->method('getRepository')->willReturn($this->tradeOrderRepoMock);
        $this->tradeOrderRepoMock->method('findOpenByTicker')->willReturn([]);
        $this->marketEventMock->method('publish')->willReturn(['type' => 'BANKRUPTCY']);

        $sequence = [];
        $this->connectionMock->method('fetchAllAssociative')->willReturnCallback(function () use (&$sequence): array {
            $sequence[] = 'read';

            return [['user_id' => 7, 'quantity' => 10], ['user_id' => 8, 'quantity' => -5]];
        });
        $this->connectionMock->method('executeStatement')->willReturnCallback(function () use (&$sequence): int {
            $sequence[] = 'delete';

            return 1;
        });

        $notifier = new class($this->entityManagerMock, $this->createStub(\Redis::class), new \Psr\Log\NullLogger()) extends \App\Service\Notification\PlayerNotifier {
            /** @var list<array{0: int, 1: string, 2: ?string}> */
            public array $queued = [];

            public function queue(int $userId, string $kind, string $title, ?string $body = null, ?string $ticker = null, ?string $link = null): void
            {
                $this->queued[] = [$userId, $title, $body];
            }
        };
        $sweep = new FailureSweep($this->entityManagerMock, $this->loggerMock, $this->marketEventMock, $this->debtEngineMock, null, null, new \App\Service\Corporate\ReorganizationEngine(), null, new \App\Service\User\CashLedger(), $notifier);

        $settlements = [];
        $this->entityManagerMock->method('persist')->willReturnCallback(function (object $entity) use (&$settlements, &$sequence): void {
            if ($entity instanceof TradeOrder && $entity->getOrigin() === TradeOrder::ORIGIN_SETTLEMENT) {
                $sequence[] = 'settle';
                $settlements[] = [$entity->getAction(), (int) $entity->getQuantity(), $entity->getExecutionPrice(), $entity->getStatus()];
            }
        });

        $sweep->sweep([$stock], new MacroStateDTO());

        // Read, then a settlement order closing each position at nothing, then the wipe.
        $this->assertSame(['read', 'settle', 'settle', 'delete'], $sequence);
        $this->assertSame([['SELL', 10, '0.0000', 'FILLED'], ['COVER', 5, '0.0000', 'FILLED']], $settlements);
        $this->assertSame([
            [7, 'DEAD was liquidated', 'Your 10 shares were cancelled. Shareholders receive nothing from the estate.'],
            [8, 'DEAD was liquidated', 'Your short position of 5 shares was closed with nothing to return.'],
        ], $notifier->queued);
    }

    public function testTheFailureSweepSkipsAnAlreadyBankruptStock(): void
    {
        $stock = new Stock();
        $stock->setTicker('DEAD');
        $stock->setName('Dead Corp');
        $stock->setIsBankrupt(true);

        $this->debtEngineMock->expects($this->never())->method('calculateAltmanZScore');
        $this->debtEngineMock->expects($this->never())->method('assessGoingConcern');

        $operator = new FailureSweep(
            $this->entityManagerMock,
            $this->loggerMock,
            $this->marketEventMock,
            $this->debtEngineMock
        );

        $events = $operator->sweep([$stock], new MacroStateDTO());
        $this->assertEmpty($events);
    }

    /**
     * Altman's Z'' predicts failure; it does not adjudicate it. An operating company files when it cannot pay,
     * and liquidating on a negative score wound up firms that were current on every obligation — WEAV with
     * $433B of equity, on a trailing EBIT that had counted a goodwill write-off. A firm paying its debts is
     * not touched, whatever its score, its reported loss or its book equity.
     */
    public function testAnOperatingFirmCurrentOnItsDebtsIsNeverLiquidatedWhateverItsAltmanScore(): void
    {
        $stock = new Stock();
        $stock->setTicker('ROTS');
        $stock->setName('Rotting Industries');
        $stock->setPrice('10.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalRevenue('1000000000');
        $stock->setOperatingMargin('0.15');
        $stock->setReportedOperatingMargin(-0.08);
        $stock->setTotalEquity('-400000000');
        $stock->setRetainedEarnings('-900000000');

        $this->debtEngineMock->expects($this->never())->method('calculateAltmanZScore');
        $this->debtEngineMock->expects($this->never())->method('assessGoingConcern');
        $this->marketEventMock->expects($this->never())->method('publish');

        $operator = new FailureSweep($this->entityManagerMock, $this->loggerMock, $this->marketEventMock, $this->debtEngineMock);
        $events = $operator->sweep([$stock], new MacroStateDTO());

        $this->assertSame([], $events);
        $this->assertFalse($stock->isBankrupt());
        $this->assertSame('10.00', $stock->getPrice());
    }

    /**
     * A missed payment inside its grace period is a late payment, not a filing: the firm has the cure period
     * the indenture gives it to find the money.
     */
    public function testADefaultStillInsideItsGracePeriodFilesNothing(): void
    {
        $stock = $this->buildDefaulter('LATE', 'Late Payer Inc', quartersInDefault: \App\Service\Math\FinancialConstants::PAYMENT_DEFAULT_GRACE_QUARTERS);

        $this->debtEngineMock->expects($this->never())->method('assessGoingConcern');

        $operator = new FailureSweep($this->entityManagerMock, $this->loggerMock, $this->marketEventMock, $this->debtEngineMock);
        $operator->sweep([$stock], new MacroStateDTO());

        $this->assertFalse($stock->isBankrupt());
        $this->assertTrue($stock->isPaymentDefault(), 'the default stands; only a plan or the money cures it');
    }

    /**
     * A solvent firm that missed a maturity reaches Chapter 11 with its creditors covered in full, so the plan
     * reinstates their claims and the shareholders keep the company: nothing is cancelled and nothing is
     * exchanged, and the default is cured. Liquidating it for a missed payment destroyed firms carrying
     * hundreds of billions of equity.
     */
    public function testAnUncuredDefaultOnASolventFirmIsReinstatedAndTheEquityKept(): void
    {
        $stock = $this->buildDefaulter('DFLT', 'Defaulted Holdings', quartersInDefault: 8);
        $stock->setPrice('25.00');
        $stock->setWholesaleDebt('500000000');
        $stock->setTotalEquity('900000000');

        $this->debtEngineMock->method('assessGoingConcern')->willReturn($this->going(ebit: 150_000_000.0, ebitda: 200_000_000.0, assetValue: 2_100_000_000.0, cash: 100_000_000.0, claims: 500_000_000.0));
        $this->connectionMock->expects($this->never())->method('executeStatement');
        $this->marketEventMock->expects($this->once())
            ->method('publish')
            ->with($stock, 'REORGANIZATION', $this->stringContains('shareholders keep the company'), $this->anything())
            ->willReturn([]);

        $operator = new FailureSweep($this->entityManagerMock, $this->loggerMock, $this->marketEventMock, $this->debtEngineMock);
        $operator->sweep([$stock], new MacroStateDTO(yield5yEma: 0.04));

        $this->assertFalse($stock->isBankrupt(), 'a solvent firm must not be liquidated for a missed payment');
        $this->assertSame('25.00', $stock->getPrice(), 'the equity is not wiped');
        $this->assertFalse($stock->isPaymentDefault(), 'the plan cures the default');
        $this->assertSame(0, $stock->getQuartersInDefault());
        $this->assertEqualsWithDelta(500_000_000.0, (float) $stock->getWholesaleDebt(), 1.0, 'every claim is reinstated');
        $this->assertEqualsWithDelta(900_000_000.0, (float) $stock->getTotalEquity(), 1.0);
    }

    /**
     * A viable business that owes more than it is worth is reorganized, not wound up. The plan keeps the debt
     * it can carry on its own lender test, swaps the rest for the equity, and the old shares — out of the
     * money under absolute priority — are cancelled. The company keeps trading and keeps its plant.
     */
    public function testAViableInsolventFirmIsReorganizedAndKeepsTrading(): void
    {
        $stock = $this->buildDefaulter('REOR', 'Reorganized Freight', quartersInDefault: 2);
        $stock->setIndustry('Airlines');
        $stock->setPrice('0.50');
        $stock->setWholesaleDebt('2000000000');
        $stock->setTotalEquity('-500000000');
        $stock->setRetainedEarnings('-900000000');

        $store = new \App\Service\Corporate\Industry\InMemoryIndustryShareStore();
        $ledger = new \App\Service\Corporate\Industry\IndustryShareLedger($store);
        $ledger->resolveIndustryCapacityRatio($stock, 1_000_000.0, 0.5, 1.0, 0.0, 0.0, 1, 252);
        $capacityBefore = $store->readIndustry('Airlines')['REOR']['capacity'];

        $this->debtEngineMock->method('assessGoingConcern')->willReturn($this->going(ebit: 50_000_000.0, ebitda: 80_000_000.0, assetValue: 320_000_000.0, cash: 20_000_000.0, claims: 2_000_000_000.0));
        $this->entityManagerMock->method('getRepository')->willReturn($this->tradeOrderRepoMock);
        $this->tradeOrderRepoMock->method('findOpenByTicker')->willReturn([]);
        $this->connectionMock->expects($this->once())
            ->method('executeStatement')
            ->with($this->stringContains('DELETE FROM user_stocks'), $this->anything());
        $this->marketEventMock->expects($this->once())
            ->method('publish')
            ->with($stock, 'REORGANIZATION', $this->logicalAnd($this->stringContains('Chapter 11'), $this->stringContains('creditors now own')), -100.0)
            ->willReturn([]);

        $operator = new FailureSweep($this->entityManagerMock, $this->loggerMock, $this->marketEventMock, $this->debtEngineMock, $ledger);
        $operator->sweep([$stock], new MacroStateDTO(yield5yEma: 0.04, highYieldCreditSpreadEma: 0.01));

        // Exit notes at the 4% five-year plus a 1% high-yield spread. Exit debt is the tightest of: coverage 50M / (5% x (2.0 + 1.5)) = 285.7M, the airline 3.5x covenant
        // 3.5 x 80M + 20M = 300M, and the 320M the assets are worth. The other 1.71B became the equity.
        $exitDebt = 50_000_000.0 / (0.05 * 3.5);
        $this->assertFalse($stock->isBankrupt());
        $this->assertEqualsWithDelta($exitDebt, (float) $stock->getWholesaleDebt(), 1.0);
        $this->assertEqualsWithDelta(-500_000_000.0 + (2_000_000_000.0 - $exitDebt), (float) $stock->getTotalEquity(), 1.0);
        $this->assertEqualsWithDelta(0.0, (float) $stock->getRetainedEarnings(), 1e-6, 'fresh start eliminates the deficit');
        $this->assertFalse($stock->isPaymentDefault());
        $this->assertEqualsWithDelta(0.05, (float) $stock->getHistoricalFixedRate(), 1e-9, 'the exit notes are new paper');
        $this->assertEqualsWithDelta((320_000_000.0 - $exitDebt) / 1_000_000.0, (float) $stock->getPrice(), 1e-6, 'the assets less the exit debt, over a million new shares');
        $this->assertSame($capacityBefore, $store->readIndustry('Airlines')['REOR']['capacity'], 'a reorganized firm keeps its plant');
    }

    /**
     * An uncured default on a business that no longer covers its cash operating costs is a creditor-driven
     * Chapter 7 filing, and the tape says so.
     */
    public function testAnUncuredDefaultOnANonViableBusinessIsNarratedAsACreditorDrivenLiquidation(): void
    {
        $stock = $this->buildDefaulter('GONE', 'Gone Holdings', quartersInDefault: 4);
        $stock->setPrice('25.00');

        $this->debtEngineMock->method('assessGoingConcern')->willReturn($this->going(ebit: -300_000_000.0, ebitda: -250_000_000.0, assetValue: 0.0, cash: 0.0, claims: 1_000_000_000.0));

        $this->entityManagerMock->method('getRepository')->willReturn($this->createConfiguredStub(
            TradeOrderRepository::class,
            ['findOpenByTicker' => []]
        ));

        $captured = null;
        $this->marketEventMock->method('publish')->willReturnCallback(
            function ($s, $type, $desc, $pct) use (&$captured) {
                $captured = $desc;
                return [];
            }
        );

        $operator = new FailureSweep(
            $this->entityManagerMock,
            $this->loggerMock,
            $this->marketEventMock,
            $this->debtEngineMock
        );

        $operator->sweep([$stock], new MacroStateDTO());

        $this->assertTrue($stock->isBankrupt());
        $this->assertEquals('0.00000000', $stock->getPrice());
        $this->assertStringContainsString('failed to cure an event of default', (string) $captured);
        $this->assertStringContainsString('Chapter 7', (string) $captured);
    }

    /**
     * Lenders are closed on their capital ratio, as a regulator closes a bank below its statutory floor; that
     * path is unchanged and never reaches the Chapter 11 assessment.
     */
    public function testALenderBelowItsCapitalFloorIsStillClosed(): void
    {
        $stock = new Stock();
        $stock->setTicker('BANK');
        $stock->setName('Undercapitalized Bank');
        $stock->setIndustry('Banks - Regional');
        $stock->setPrice('5.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalRevenue('1000000000');
        $stock->setOperatingMargin('0.20');

        $this->debtEngineMock->method('calculateAltmanZScore')->willReturn(['z_score' => 1.2, 'zone' => 'Distress', 'is_bankrupt' => true]);
        $this->debtEngineMock->expects($this->never())->method('assessGoingConcern');
        $this->entityManagerMock->method('getRepository')->willReturn($this->tradeOrderRepoMock);
        $this->tradeOrderRepoMock->method('findOpenByTicker')->willReturn([]);
        $this->marketEventMock->method('publish')->willReturn([]);

        $operator = new FailureSweep($this->entityManagerMock, $this->loggerMock, $this->marketEventMock, $this->debtEngineMock);
        $operator->sweep([$stock], new MacroStateDTO());

        $this->assertTrue($stock->isBankrupt());
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

        $operator = new FailureSweep(
            $this->entityManagerMock,
            $this->loggerMock,
            $this->marketEventMock,
            $this->debtEngineMock
        );

        $operator->sweep([$stock], new MacroStateDTO());

        $this->assertFalse($stock->isBankrupt());
    }

    /** An operating firm whose missed maturity has stood for the given number of quarters. */
    private function buildDefaulter(string $ticker, string $name, int $quartersInDefault): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName($name);
        $stock->setPrice('10.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalRevenue('1000000000');
        $stock->setOperatingMargin('0.10');
        $stock->setCreditRating('D');
        $stock->setCreditSpread('0.01');
        $stock->setPaymentDefault(true);
        $stock->setQuartersInDefault($quartersInDefault);

        return $stock;
    }

    private function going(float $ebit, float $ebitda, float $assetValue, float $cash, float $claims): GoingConcernDTO
    {
        return new GoingConcernDTO(trailingEbit: $ebit, trailingEbitda: $ebitda, assetValue: $assetValue, cash: $cash, claims: $claims);
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
            new \App\Service\Market\Trading\AssetResolver($this->entityManagerMock),
            new \App\Service\Market\Pricing\LiquidityEngine(new \App\Service\Math\MathUtility()),
            new \App\Service\Market\Flow\InMemoryOrderFlowStore(),
            new \App\Service\Market\Trading\MarginEngine(
                $this->entityManagerMock,
                new \App\Service\Market\Option\OptionMarginCalculator($this->entityManagerMock)
            ),
            new \App\Service\Market\Trading\SecuritiesLendingDesk(),
            new \App\Service\Market\Option\OptionTradeService(
                $this->entityManagerMock,
                new \App\Service\Market\Option\OptionPricingEngine(new \App\Service\Market\Bond\BondPricingEngine()),
                new \App\Service\Macro\MacroStateProvider($redis),
                new \App\Service\Market\Trading\MarginEngine(
                    $this->entityManagerMock,
                    new \App\Service\Market\Option\OptionMarginCalculator($this->entityManagerMock)
                ),
                new \App\Service\User\CashLedger()
            ),
            new \App\Service\User\CashLedger()
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

        $marketEngine = $this->createMock(\App\Service\Market\Pricing\MarketEngine::class);
        $earningsEngine = $this->createMock(EarningsEngine::class);
        $corpActionEngine = $this->createMock(\App\Service\Corporate\CorporateActionEngine::class);
        $maEngine = $this->createStub(\App\Service\Corporate\MergerAndAcquisitionEngine::class);
        $corpMetrics = $this->createStub(\App\Service\Corporate\CorporateMetrics::class);

        $marketEngine->expects($this->never())->method('calculateNextPrice');
        $earningsEngine->expects($this->never())->method('calculate');
        $corpActionEngine->expects($this->never())->method('processSplits');

        $tracker = new StockTracker(
            $marketEngine,
            $earningsEngine,
            $corpActionEngine,
            $maEngine,
            $this->marketEventMock,
            $this->debtEngineMock,
            $corpMetrics,
            new \App\Service\Market\Pricing\LiquidityEngine(new \App\Service\Math\MathUtility()),
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

        $result = $actionEngine->processSplits($stock, 0.0, 1000000.0, 5.0);
        $this->assertEquals(0.0, $result['price']);
        $this->assertEquals(1000000.0, $result['shares']);
        $this->assertNull($result['event']);

        // Test non-bankrupt stock with $0.00 price also does not trigger reverse split
        $aliveStock = new Stock();
        $aliveStock->setTicker('ALIVE');
        $aliveStock->setIsBankrupt(false);
        $aliveStock->setSharesOutstanding('1000000');

        $resultZero = $actionEngine->processSplits($aliveStock, 0.0, 1000000.0, 5.0);
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
        $failed->setPaymentDefault(true);
        $failed->setQuartersInDefault(2);
        $survivor = $build('BIG');

        $store = new \App\Service\Corporate\Industry\InMemoryIndustryShareStore();
        $ledger = new \App\Service\Corporate\Industry\IndustryShareLedger($store);
        $ledger->resolveIndustryCapacityRatio($survivor, 1_000_000.0, 0.5, 1.0, 0.0, 0.0, 1, 252);
        $ledger->resolveIndustryCapacityRatio($failed, 1_000_000.0, 0.5, 1.0, 0.0, 0.0, 2, 252);

        $this->debtEngineMock->method('assessGoingConcern')->willReturn($this->going(ebit: -50_000.0, ebitda: -20_000.0, assetValue: 0.0, cash: 0.0, claims: 1_000_000.0));
        $this->entityManagerMock->method('getRepository')->willReturn($this->tradeOrderRepoMock);
        $this->tradeOrderRepoMock->method('findOpenByTicker')->willReturn([]);
        $this->marketEventMock->method('publish')->willReturn(['type' => 'BANKRUPTCY']);

        $operator = new FailureSweep($this->entityManagerMock, $this->loggerMock, $this->marketEventMock, $this->debtEngineMock, $ledger);
        $operator->sweep([$failed, $survivor], new MacroStateDTO());

        $this->assertTrue($failed->isBankrupt());
        $this->assertSame(0.0, $store->readIndustry('Airlines')['DEAD']['capacity'], 'the failed firm\'s plant is gone');
        $this->assertSame(0.0, $store->readIndustry('Airlines')['DEAD']['revenue'], 'and so are its sales');
        // Half the market's plant is gone against unchanged demand: the survivor's next report clears tight.
        $this->assertEqualsWithDelta(0.5, $ledger->resolveIndustryCapacityRatio($survivor, 1_000_000.0, 0.5, 1.0, 0.0, 0.0, 70, 252), 1e-9);
        $this->assertEqualsWithDelta(1.00, (float) $survivor->getSamRatio(), 1e-9, 'the addressable market is no longer transferred');
    }

}
