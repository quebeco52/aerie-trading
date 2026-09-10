<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Bond;
use App\Entity\Etf;
use App\Entity\Stock;
use App\Entity\TradeOrder;
use App\Entity\User;
use App\Entity\UserBond;
use App\Entity\UserEtf;
use App\Entity\UserStock;
use App\Service\Market\AssetResolver;
use App\Service\Market\Flow\InMemoryOrderFlowStore;
use App\Service\Market\LiquidityEngine;
use App\Service\Math\MathUtility;
use App\Service\Market\TradeExecutionService;
use App\Service\User\Portfolio;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class TradeExecutionServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $emMock;
    private Connection&Stub $connectionMock;
    private Portfolio&Stub $portfolioStub;
    private \Redis&Stub $redisStub;
    /** @var EntityRepository<Stock>&Stub */
    private EntityRepository&Stub $stockRepoStub;
    /** @var EntityRepository<Etf>&Stub */
    private EntityRepository&Stub $etfRepoStub;
    /** @var EntityRepository<UserStock>&Stub */
    private EntityRepository&Stub $userStockRepoStub;
    /** @var EntityRepository<UserEtf>&Stub */
    private EntityRepository&Stub $userEtfRepoStub;
    /** @var EntityRepository<TradeOrder>&Stub */
    private EntityRepository&Stub $tradeOrderRepoStub;
    /** @var EntityRepository<Bond>&Stub */
    private EntityRepository&Stub $bondRepoStub;
    /** @var EntityRepository<UserBond>&Stub */
    private EntityRepository&Stub $userBondRepoStub;
    private InMemoryOrderFlowStore $orderFlow;
    private TradeExecutionService $service;

    protected function setUp(): void
    {
        $this->emMock = $this->createMock(EntityManagerInterface::class);
        $this->connectionMock = $this->createStub(Connection::class);
        $this->connectionMock->method('isTransactionActive')->willReturn(true);
        $this->emMock->method('getConnection')->willReturn($this->connectionMock);

        $this->orderFlow = new InMemoryOrderFlowStore();
        $this->portfolioStub = $this->createStub(Portfolio::class);
        $this->redisStub = $this->createStub(\Redis::class);

        $this->stockRepoStub = $this->createStub(EntityRepository::class);
        $this->etfRepoStub = $this->createStub(EntityRepository::class);
        $this->userStockRepoStub = $this->createStub(EntityRepository::class);
        $this->userEtfRepoStub = $this->createStub(EntityRepository::class);
        $this->tradeOrderRepoStub = $this->createStub(EntityRepository::class);
        $this->bondRepoStub = $this->createStub(EntityRepository::class);
        $this->userBondRepoStub = $this->createStub(EntityRepository::class);

        $this->emMock->method('getRepository')->willReturnCallback(function (string $entityClass) {
            return match ($entityClass) {
                Stock::class => $this->stockRepoStub,
                Etf::class => $this->etfRepoStub,
                UserStock::class => $this->userStockRepoStub,
                UserEtf::class => $this->userEtfRepoStub,
                UserBond::class => $this->userBondRepoStub,
                Bond::class => $this->bondRepoStub,
                TradeOrder::class => $this->tradeOrderRepoStub,
                default => $this->createStub(EntityRepository::class),
            };
        });

        // The real resolver on the same EntityManager: ticker-to-instrument mapping is the behaviour under
        // test here as much as the cash arithmetic is, so stubbing it out would hide a resolution bug.
        $this->service = new TradeExecutionService(
            $this->emMock,
            $this->portfolioStub,
            $this->redisStub,
            new NullLogger(),
            new AssetResolver($this->emMock),
            new LiquidityEngine(new MathUtility()),
            $this->orderFlow
        );
    }

    public function testExecuteMarketBuyFillsImmediatelyAndDeductsCash(): void
    {
        $user = new User();
        $user->setCashBalance('1000.00');

        $stock = new Stock();
        $stock->setTicker('APEX');
        $stock->setPrice('50.00');

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn(null); // First time buying

        $this->emMock->expects($this->atLeastOnce())->method('persist');
        $this->emMock->expects($this->atLeastOnce())->method('flush');

        // A buy no longer fills at mid: the taker crosses the spread and pays half of its own impact, so
        // $500 of stock costs slightly more than $500. The exact figure is asserted in
        // App\Tests\Service\Market\LiquidityEngineTest; what matters here is the direction and that the
        // cash actually left the account.
        $this->service->executeOrder($user, 'APEX', 'BUY', 'MARKET', 10);

        $spent = 1000.0 - (float) $user->getCashBalance();

        $this->assertGreaterThan(500.0, $spent, 'A market buy must fill above mid.');
        $this->assertLessThan(505.0, $spent, 'Ten shares of a liquid name should not cost one percent to cross.');
    }

    public function testAMarketSellFillsBelowMidAndABuySellRoundTripLosesMoney(): void
    {
        // The property that makes size matter: crossing twice costs twice, so a position opened and closed
        // at an unchanged price comes back smaller. Without execution costs this round trip was free, and
        // any strategy that traded often was strictly better than one that did not.
        $user = new User();
        $user->setCashBalance('1000.00');

        $stock = new Stock();
        $stock->setTicker('APEX');
        $stock->setPrice('50.00');

        $holding = new UserStock();
        $holding->setUser($user);
        $holding->setStock($stock);
        $holding->setQuantity(10);

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn($holding);

        $this->service->executeOrder($user, 'APEX', 'BUY', 'MARKET', 10);
        $afterBuy = (float) $user->getCashBalance();

        $this->service->executeOrder($user, 'APEX', 'SELL', 'MARKET', 10);
        $afterSell = (float) $user->getCashBalance();

        $proceeds = $afterSell - $afterBuy;

        $this->assertLessThan(500.0, $proceeds, 'A market sell must fill below mid.');
        $this->assertLessThan(1000.0, $afterSell, 'A round trip at an unchanged price must lose money.');
    }

    public function testAFillRecordsWhatTheExecutionCostWasMadeOf(): void
    {
        $user = new User();
        $user->setCashBalance('100000.00');

        $stock = new Stock();
        $stock->setTicker('APEX');
        $stock->setPrice('50.00');

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn(null);

        $persisted = [];
        $this->emMock->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $this->service->executeOrder($user, 'APEX', 'BUY', 'MARKET', 100);

        $orders = array_values(array_filter($persisted, static fn (object $e): bool => $e instanceof TradeOrder));
        $this->assertCount(1, $orders);

        $order = $orders[0];
        $this->assertGreaterThan(0.0, (float) $order->getSpreadCost(), 'Crossing the spread is never free.');
        $this->assertGreaterThan(0.0, (float) $order->getImpactCost(), 'An order pays for its own impact.');
        $this->assertGreaterThan(50.0, (float) $order->getExecutionPrice());
    }

    public function testAnOrderLargerThanTheDeskCanAbsorbIsRefusedRatherThanExtrapolated(): void
    {
        // Past a couple of days' volume the square-root law is extrapolation. Capping the impact instead
        // would make size free again above the cap, which is the hole this engine exists to close.
        $user = new User();
        $user->setCashBalance('100000000.00');

        $stock = new Stock();
        $stock->setTicker('APEX');
        $stock->setPrice('50.00');
        $stock->setSharesOutstanding('1000000');

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/exceeds available liquidity/');

        $this->service->executeOrder($user, 'APEX', 'BUY', 'MARKET', 500000);
    }

    public function testFillsAreReportedToTheOrderFlowStore(): void
    {
        // The tick applies impact against the net of everything that traded, so a fill that is not reported
        // is a fill that moves nothing and a trader who splits an order pays nothing.
        $user = new User();
        $user->setCashBalance('100000.00');

        $stock = new Stock();
        $stock->setTicker('APEX');
        $stock->setPrice('50.00');

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn(null);

        $this->service->executeOrder($user, 'APEX', 'BUY', 'MARKET', 40);
        $this->service->executeOrder($user, 'APEX', 'BUY', 'MARKET', 60);

        $this->assertSame(['APEX' => 100.0], $this->orderFlow->drain(), 'Flow accumulates until the tick drains it.');
        $this->assertSame([], $this->orderFlow->drain(), 'A drained quantity must not move the price twice.');
    }

    public function testExecuteMarketBuyThrowsOnInsufficientFunds(): void
    {
        $user = new User();
        $user->setCashBalance('100.00'); // only $100

        $stock = new Stock();
        $stock->setTicker('APEX');
        $stock->setPrice('50.00'); // 10 * 50 = $500 > $100

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient funds.');

        $this->service->executeOrder($user, 'APEX', 'BUY', 'MARKET', 10);
    }

    public function testExecuteMarketSellDeductsSharesAndAddsCash(): void
    {
        $user = new User();
        $user->setCashBalance('200.00');

        $stock = new Stock();
        $stock->setTicker('APEX');
        $stock->setPrice('50.00');

        $userStock = new UserStock();
        $userStock->setUser($user);
        $userStock->setStock($stock);
        $userStock->setQuantity(20);

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn($userStock);

        // A sell hits the bid, so ten shares raise slightly under $500 and the position halves.
        $this->service->executeOrder($user, 'APEX', 'SELL', 'MARKET', 10);

        $proceeds = (float) $user->getCashBalance() - 200.0;

        $this->assertLessThan(500.0, $proceeds, 'A market sell must fill below mid.');
        $this->assertGreaterThan(495.0, $proceeds, 'Ten shares of a liquid name should not cost one percent to cross.');
        $this->assertSame(10, $userStock->getQuantity());
    }

    public function testExecuteLimitBuyEscrowsCashWhenNotImmediatelyCrossing(): void
    {
        $user = new User();
        $user->setCashBalance('1000.00');

        $stock = new Stock();
        $stock->setTicker('APEX');
        $stock->setPrice('100.00'); // Live price is $100

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);

        // Limit BUY at $80.00 for 5 shares ($400.00 escrowed) -> order stays OPEN
        $this->service->executeOrder($user, 'APEX', 'BUY', 'LIMIT', 5, '80.00');

        $this->assertEquals('600.0000', $user->getCashBalance());
    }

    public function testCancelOpenBuyOrderRefundsEscrowCash(): void
    {
        $user = new User();
        $user->setCashBalance('500.00');

        $order = new TradeOrder();
        $order->setUser($user);
        $order->setTicker('APEX');
        $order->setAction('BUY');
        $order->setOrderType('LIMIT');
        $order->setQuantity(10);
        $order->setLimitPrice('25.00'); // $250.00 escrowed
        $order->setStatus('OPEN');

        $this->tradeOrderRepoStub->method('findOneBy')->willReturn($order);

        $this->service->cancelOrder($user, 1);

        $this->assertSame('CANCELLED', $order->getStatus());
        $this->assertEquals('750.0000', $user->getCashBalance());
    }

    /**
     * A negative limit price used to invert the escrow arithmetic and mint cash.
     *
     * bcmul(-5, 10) is -50, which passes the funds check because the balance exceeds it, and the debit
     * bcsub(cash, -50) then CREDITS the account. The order could afterwards be spent against and cancelled.
     */
    public function testExecuteLimitBuyRejectsNegativeLimitPriceRatherThanMintingCash(): void
    {
        $user = new User();
        $user->setCashBalance('1000.00');

        $stock = new Stock();
        $stock->setTicker('APEX');
        $stock->setPrice('100.00');
        $this->stockRepoStub->method('findOneBy')->willReturn($stock);

        try {
            $this->service->executeOrder($user, 'APEX', 'BUY', 'LIMIT', 10, '-5.00');
            $this->fail('A negative limit price must be rejected.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Limit price must be at least', $e->getMessage());
        }

        $this->assertEquals('1000.00', $user->getCashBalance(), 'A rejected order must not move cash.');
    }

    /** Zero is below the floor for the same reason: it escrows nothing and can never be a real bid. */
    public function testExecuteLimitBuyRejectsZeroLimitPrice(): void
    {
        $user = new User();
        $user->setCashBalance('1000.00');

        $stock = new Stock();
        $stock->setTicker('APEX');
        $stock->setPrice('100.00');
        $this->stockRepoStub->method('findOneBy')->willReturn($stock);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Limit price must be at least');

        $this->service->executeOrder($user, 'APEX', 'BUY', 'LIMIT', 10, '0');
    }

    /**
     * A non-numeric limit price reached bcmath, which throws a ValueError - an Error, not an Exception, so
     * neither the service nor the controller caught it and the open transaction was left dangling.
     */
    public function testExecuteLimitOrderRejectsNonNumericLimitPrice(): void
    {
        $user = new User();
        $user->setCashBalance('1000.00');

        $stock = new Stock();
        $stock->setTicker('APEX');
        $stock->setPrice('100.00');
        $this->stockRepoStub->method('findOneBy')->willReturn($stock);

        foreach (['abc', '', '  '] as $bad) {
            try {
                $this->service->executeOrder($user, 'APEX', 'BUY', 'LIMIT', 10, $bad);
                $this->fail(sprintf('Limit price "%s" must be rejected.', $bad));
            } catch (\Exception $e) {
                $this->assertStringContainsString('numeric limit price', $e->getMessage());
            }
        }
    }

    /** A LIMIT order with no price at all is not an order; it used to reach bcmath as an empty string. */
    public function testExecuteLimitOrderRejectsMissingLimitPrice(): void
    {
        $user = new User();
        $user->setCashBalance('1000.00');

        $stock = new Stock();
        $stock->setTicker('APEX');
        $stock->setPrice('100.00');
        $this->stockRepoStub->method('findOneBy')->willReturn($stock);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('numeric limit price');

        $this->service->executeOrder($user, 'APEX', 'BUY', 'LIMIT', 10, null);
    }

    /**
     * Exponent notation is numeric to PHP and not well-formed to bcmath, so it has to be rendered before it
     * reaches the escrow arithmetic rather than passed through.
     */
    public function testExecuteLimitBuyNormalizesExponentNotation(): void
    {
        $user = new User();
        $user->setCashBalance('10000.00');

        $stock = new Stock();
        $stock->setTicker('APEX');
        $stock->setPrice('100.00');
        $this->stockRepoStub->method('findOneBy')->willReturn($stock);

        // 8e1 is $80: below the $100 live price, so the order rests and escrows 5 * $80 = $400.
        $this->service->executeOrder($user, 'APEX', 'BUY', 'LIMIT', 5, '8e1');

        $this->assertEquals('9600.0000', $user->getCashBalance());
    }

    /** Any side but BUY or SELL used to fall through to the SELL branch and escrow shares against it. */
    public function testExecuteOrderRejectsUnknownAction(): void
    {
        $user = new User();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid order action.');

        $this->service->executeOrder($user, 'APEX', 'HOLD', 'MARKET', 10);
    }

    public function testExecuteOrderThrowsOnInvalidQuantity(): void
    {
        $user = new User();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid quantity.');

        $this->service->executeOrder($user, 'APEX', 'BUY', 'MARKET', 0);
    }

    public function testExecuteOrderRejectsBankruptStock(): void
    {
        $user = new User();
        $user->setCashBalance('1000.00');

        $stock = new Stock();
        $stock->setTicker('DEAD');
        $stock->setIsBankrupt(true); // Marked as bankrupt

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Trading is halted for DEAD. The company is bankrupt.');

        $this->service->executeOrder($user, 'DEAD', 'BUY', 'MARKET', 10);
    }

    public function testExecuteMarketBuyOnEtf(): void
    {
        $user = new User();
        $user->setCashBalance('1000.00');

        $etf = new Etf();
        $etf->setTicker('LBI');
        $etf->setPrice('100.00');

        $this->stockRepoStub->method('findOneBy')->willReturn(null);
        $this->etfRepoStub->method('findOneBy')->willReturn($etf);
        $this->userEtfRepoStub->method('findOneBy')->willReturn(null);

        // An ETF quotes at a flat half-spread rather than a modelled depth: creation and redemption keep it
        // pinned to its basket. Cheap, but not free — otherwise the ETF would be a way to buy the whole
        // index without paying the equity book's execution costs.
        $this->service->executeOrder($user, 'LBI', 'BUY', 'MARKET', 5);

        $spent = 1000.0 - (float) $user->getCashBalance();

        $this->assertGreaterThan(500.0, $spent);
        $this->assertEqualsWithDelta(
            500.0 * (1.0 + \App\Service\Math\FinancialConstants::ETF_HALF_SPREAD),
            $spent,
            0.0001
        );
    }

    public function testExecuteLimitSellEscrowsShares(): void
    {
        $user = new User();
        $user->setCashBalance('100.00');

        $stock = new Stock();
        $stock->setTicker('APEX');
        $stock->setPrice('50.00');

        $userStock = new UserStock();
        $userStock->setUser($user);
        $userStock->setStock($stock);
        $userStock->setQuantity(20);

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn($userStock);

        // Limit SELL at $60.00 for 10 shares (price > $50 live price -> remains OPEN)
        $this->service->executeOrder($user, 'APEX', 'SELL', 'LIMIT', 10, '60.00');

        // Shares should be immediately deducted (escrowed)
        $this->assertSame(10, $userStock->getQuantity());
    }

    public function testCancelOpenSellOrderRefundsSharesToUser(): void
    {
        $user = new User();
        $stock = new Stock();
        $stock->setTicker('APEX');

        $userStock = new UserStock();
        $userStock->setUser($user);
        $userStock->setStock($stock);
        $userStock->setQuantity(10); // currently has 10 unescrowed

        $order = new TradeOrder();
        $order->setUser($user);
        $order->setTicker('APEX');
        $order->setAction('SELL');
        $order->setOrderType('LIMIT');
        $order->setQuantity(10);
        $order->setStatus('OPEN');

        $this->tradeOrderRepoStub->method('findOneBy')->willReturn($order);
        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn($userStock);

        $this->service->cancelOrder($user, 1);

        $this->assertSame('CANCELLED', $order->getStatus());
        $this->assertSame(20, $userStock->getQuantity(), 'Cancelling sell order must refund escrowed shares.');
    }

    public function testProcessLimitOrdersFillsBuyAndSellOrdersWithPriceImprovement(): void
    {
        $buyer = new User();
        $buyer->setCashBalance('0.00'); // Escrowed $1000 already at limit price $100

        $stock = new Stock();
        $stock->setTicker('APEX');
        $stock->setPrice('90.00');

        $buyOrder = new TradeOrder();
        $buyOrder->setUser($buyer);
        $buyOrder->setTicker('APEX');
        $buyOrder->setAction('BUY');
        $buyOrder->setOrderType('LIMIT');
        $buyOrder->setQuantity(10);
        $buyOrder->setLimitPrice('100.00'); // Limit $100
        $buyOrder->setStatus('OPEN');

        $resultStatementMock = $this->createStub(\Doctrine\DBAL\Result::class);
        $resultStatementMock->method('fetchOne')->willReturn(null);
        $this->connectionMock->method('executeQuery')->willReturn($resultStatementMock);

        $this->tradeOrderRepoStub->method('findBy')->willReturn([$buyOrder]);
        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn(null);

        // Process limit orders at $90.00 (well below the $100 limit, so it fills and most of the escrow
        // comes back). The fill is a touch above $90 because the buyer still crosses the spread and pays
        // for its own impact — price improvement against the limit, not a fill at mid.
        $this->service->processLimitOrders('APEX', 90.0);

        $this->assertSame('FILLED', $buyOrder->getStatus());
        $this->assertSame(10, $buyOrder->getFilledQuantity());

        $fill = (float) $buyOrder->getExecutionPrice();
        $this->assertGreaterThan(90.0, $fill, 'A resting buy still crosses the spread when it fills.');
        $this->assertLessThanOrEqual(100.0, $fill, 'A limit order must never fill above its own limit.');

        // Refund is the unspent escrow: ($100 - fill) * 10.
        $this->assertEqualsWithDelta((100.0 - $fill) * 10.0, (float) $buyer->getCashBalance(), 0.01);
    }

    public function testARestingLimitDoesNotFillWhenExecutionCostsWouldBreachItsLimit(): void
    {
        // The touch is the mid, and the spread and impact sit on top of it. A limit is a promise about the
        // worst price the trader will accept, so a fill that breaches it is not price improvement — it is
        // the order doing the opposite of what it was placed to do.
        $buyer = new User();
        $buyer->setCashBalance('0.00');

        $stock = new Stock();
        $stock->setTicker('APEX');
        $stock->setPrice('100.00');
        // Thin enough that a sizeable order's own impact pushes the fill clear of the limit.
        $stock->setSharesOutstanding('300000');
        $stock->setVolatility('0.60');
        $stock->setCurrentVolatility('0.60');

        $buyOrder = new TradeOrder();
        $buyOrder->setUser($buyer);
        $buyOrder->setTicker('APEX');
        $buyOrder->setAction('BUY');
        $buyOrder->setOrderType('LIMIT');
        $buyOrder->setQuantity(600);
        $buyOrder->setLimitPrice('100.00');
        $buyOrder->setStatus('OPEN');

        $resultStatementMock = $this->createStub(\Doctrine\DBAL\Result::class);
        $resultStatementMock->method('fetchOne')->willReturn(null);
        $this->connectionMock->method('executeQuery')->willReturn($resultStatementMock);

        $this->tradeOrderRepoStub->method('findBy')->willReturn([$buyOrder]);
        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn(null);

        $this->service->processLimitOrders('APEX', 100.0);

        $this->assertSame('OPEN', $buyOrder->getStatus(), 'The order stays working rather than filling through its limit.');
        $this->assertSame('0.00', $buyer->getCashBalance(), 'Escrow is untouched when nothing fills.');
    }
}
