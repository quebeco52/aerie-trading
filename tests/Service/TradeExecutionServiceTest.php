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
use App\Service\Market\MarginEngine;
use App\Service\Market\SecuritiesLendingDesk;
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
            $this->orderFlow,
            new MarginEngine($this->emMock),
            new SecuritiesLendingDesk()
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
    // --- Short selling and margin ---

    private function marginUser(string $cash = '100000.00'): User
    {
        $user = new User();
        $user->setCashBalance($cash);
        $user->setMarginEnabled(true);

        return $user;
    }

    private function shortableStock(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('APEX');
        $stock->setPrice('50.00');
        $stock->setSharesOutstanding('50000000');
        $stock->setPublicFloatPercentage('0.90');
        $stock->setVolatility('0.30');
        $stock->setCurrentVolatility('0.30');
        $stock->setShortInterestShares('0.00');

        return $stock;
    }

    /**
     * Makes the stubbed connection report a real book, so the margin gate is measured against something.
     *
     * Without this every account looks unlevered and empty: a bare Connection stub returns null from
     * fetchAssociative and fetchOne, MarginEngine::status() reads that as a flat book, and buying power
     * comes back as twice the cash whatever the account is actually carrying. Every margin assertion in
     * this class that does not call this is passing against a fiction.
     *
     * MarginEngine::status() asks two questions of fetchAssociative — the holdings and the open order book
     * — so the stub answers on the query rather than returning one row to both. Returning the positions row
     * to the escrow query would have read its missing columns as an empty book and passed by luck.
     */
    private function bookIs(float $longValue, float $shortValue, float $escrowCash = 0.0, float $escrowLong = 0.0): void
    {
        $this->connectionMock->method('fetchAssociative')
            ->willReturnCallback(static function (string $sql) use ($longValue, $shortValue, $escrowCash, $escrowLong): array {
                if (str_contains($sql, 'escrow_cash')) {
                    return ['escrow_cash' => $escrowCash, 'escrow_long' => $escrowLong];
                }

                return ['long_value' => $longValue, 'short_value' => $shortValue];
            });
        $this->connectionMock->method('fetchOne')->willReturn(0.0);
    }

    /**
     * Closing a borrow must never be gated on buying power.
     *
     * A short carried at the Reg-T initial requirement has zero buying power by construction — that is what
     * "fully margined" means — so gating COVER on it refused the trade on a perfectly healthy account, and
     * refused it harder the deeper the position went. Every forced buy-in in ForcedLiquidationService runs
     * through this path, so the recall leg of a squeeze could not complete.
     */
    public function testAFullyMarginedShortCanStillCover(): void
    {
        $user = $this->marginUser('75000.00');
        $stock = $this->shortableStock();
        $stock->setShortInterestShares('1000.00');

        $holding = new UserStock();
        $holding->setUser($user);
        $holding->setStock($stock);
        $holding->setQuantity(-1000);

        // $50k short against $75k of cash: exactly the Reg-T initial requirement, so buying power is zero.
        $this->bookIs(longValue: 0.0, shortValue: 50000.0);
        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn($holding);

        $this->service->executeOrder($user, 'APEX', 'COVER', 'MARKET', 400);

        $this->assertSame(-600, (int) $holding->getQuantity());
    }

    /** The same account, now underwater, still has to be able to buy its way out. */
    public function testAShortThatIsAlreadyCalledCanStillCover(): void
    {
        $user = $this->marginUser('75000.00');
        $stock = $this->shortableStock();
        $stock->setShortInterestShares('1000.00');

        $holding = new UserStock();
        $holding->setUser($user);
        $holding->setStock($stock);
        $holding->setQuantity(-1000);

        // The price has run: equity is now well under the 30% maintenance requirement on the short.
        $this->bookIs(longValue: 0.0, shortValue: 70000.0);
        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn($holding);

        $this->service->executeOrder($user, 'APEX', 'COVER', 'MARKET', 1000);

        $this->assertSame(0, (int) $holding->getQuantity());
    }

    /**
     * A resting COVER escrows nothing, so working one cannot call the account it exists to save.
     *
     * Escrow was applied to every buy-side action, and for a COVER that is backwards. The cash leaves while
     * the borrow stays open, so short market value and the requirement do not move and equity falls by the
     * full notional — the opposite of what the fill does. On a short carried near the maintenance rate, a
     * resting cover was therefore enough to trigger the call, and ForcedLiquidationService would then sell
     * the account's longs to clear a shortfall the order itself had manufactured.
     */
    public function testARestingCoverEscrowsNothingRatherThanDrainingTheAccountItIsSaving(): void
    {
        $user = $this->marginUser('75000.00');
        $stock = $this->shortableStock();
        $stock->setShortInterestShares('1000.00');

        $holding = new UserStock();
        $holding->setUser($user);
        $holding->setStock($stock);
        $holding->setQuantity(-1000);

        $this->bookIs(longValue: 0.0, shortValue: 50000.0);
        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn($holding);

        // Bidding $40 for stock trading at $50: nowhere near crossing, so it rests.
        $this->service->executeOrder($user, 'APEX', 'COVER', 'LIMIT', 400, '40.00');

        $this->assertEquals('75000.00', $user->getCashBalance(), 'A resting cover must not take the cash.');
        $this->assertEquals('0.00', $user->getMarginDebit(), 'Nor borrow to escrow what it has not bought.');
        $this->assertSame(-1000, (int) $holding->getQuantity(), 'The borrow is still open until it fills.');
    }

    /**
     * Nothing rests as a cover unless there is a borrow to close.
     *
     * The immediate path checks this in settlePosition, which a resting order never reaches, so with the
     * escrow gone the placement path has to make the same check itself. Without it an account with no
     * short at all could work covers, and each one was a pure withdrawal from equity.
     */
    public function testARestingCoverIsRefusedWhenThereIsNoShortToClose(): void
    {
        $user = $this->marginUser('75000.00');
        $stock = $this->shortableStock();

        $this->bookIs(longValue: 0.0, shortValue: 0.0);
        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/No short position/');

        $this->service->executeOrder($user, 'APEX', 'COVER', 'LIMIT', 400, '40.00');
    }

    /** Nothing was escrowed, so cancelling gives nothing back. Refunding it would have minted cash. */
    public function testCancellingARestingCoverRefundsNothing(): void
    {
        $user = $this->marginUser('75000.00');

        $order = new TradeOrder();
        $order->setUser($user);
        $order->setTicker('APEX');
        $order->setAction('COVER');
        $order->setOrderType('LIMIT');
        $order->setQuantity(400);
        $order->setLimitPrice('40.00');
        $order->setStatus('OPEN');

        $this->tradeOrderRepoStub->method('findOneBy')->willReturn($order);

        $this->service->cancelOrder($user, 1);

        $this->assertSame('CANCELLED', $order->getStatus());
        $this->assertEquals('75000.00', $user->getCashBalance());
    }

    /** The purchase is paid for when it fills, and that is where the borrow is returned. */
    public function testARestingCoverPaysForItselfAndReturnsTheBorrowWhenItFills(): void
    {
        $user = $this->marginUser('75000.00');
        $stock = $this->shortableStock();
        $stock->setPrice('40.00');
        $stock->setShortInterestShares('1000.00');

        $holding = new UserStock();
        $holding->setUser($user);
        $holding->setStock($stock);
        $holding->setQuantity(-1000);

        $order = new TradeOrder();
        $order->setUser($user);
        $order->setTicker('APEX');
        $order->setAction('COVER');
        $order->setOrderType('LIMIT');
        $order->setQuantity(400);
        $order->setLimitPrice('45.00');
        $order->setStatus('OPEN');

        $resultStatementMock = $this->createStub(\Doctrine\DBAL\Result::class);
        $resultStatementMock->method('fetchOne')->willReturn(null);
        $this->connectionMock->method('executeQuery')->willReturn($resultStatementMock);

        $this->bookIs(longValue: 0.0, shortValue: 40000.0);
        $this->tradeOrderRepoStub->method('findBy')->willReturn([$order]);
        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn($holding);

        $this->service->processLimitOrders('APEX', 40.0);

        $this->assertSame('FILLED', $order->getStatus());
        $this->assertSame(-600, (int) $holding->getQuantity());
        $this->assertSame(600.0, (float) $stock->getShortInterestShares());

        $fill = (float) $order->getExecutionPrice();
        $this->assertLessThanOrEqual(45.0, $fill, 'A limit order must never fill above its own limit.');
        $this->assertEqualsWithDelta(75000.0 - 400.0 * $fill, (float) $user->getCashBalance(), 0.01);
        $this->assertEquals('0.00', $user->getMarginDebit());
    }

    /** The gate still has to bite on the side that actually adds exposure. */
    public function testOpeningNewExposureIsStillRefusedWithoutBuyingPower(): void
    {
        $user = $this->marginUser('75000.00');
        $stock = $this->shortableStock();

        $this->bookIs(longValue: 0.0, shortValue: 50000.0);
        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/buying power/');

        $this->service->executeOrder($user, 'APEX', 'BUY', 'MARKET', 500);
    }

    public function testAShortCreditsProceedsAndOpensANegativePosition(): void
    {
        $user = $this->marginUser();
        $stock = $this->shortableStock();

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);

        $holding = null;
        $this->emMock->method('persist')->willReturnCallback(static function (object $entity) use (&$holding): void {
            if ($entity instanceof UserStock) {
                $holding = $entity;
            }
        });

        $this->service->executeOrder($user, 'APEX', 'SHORT', 'MARKET', 100);

        $this->assertNotNull($holding);
        $this->assertSame(-100, (int) $holding->getQuantity());
        $this->assertGreaterThan(100000.0, (float) $user->getCashBalance(), 'Proceeds are credited to cash.');

        // Proceeds are not free money: the borrowed stock is registered against the name's lendable supply.
        $this->assertSame(100.0, (float) $stock->getShortInterestShares());
    }

    public function testACashAccountCannotSellShort(): void
    {
        $user = new User();
        $user->setCashBalance('100000.00');

        $this->stockRepoStub->method('findOneBy')->willReturn($this->shortableStock());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/requires a margin account/');

        $this->service->executeOrder($user, 'APEX', 'SHORT', 'MARKET', 100);
    }

    public function testAShortIsRefusedWhenThereIsNotEnoughStockToBorrow(): void
    {
        $user = $this->marginUser('100000000.00');
        $stock = $this->shortableStock();

        // Lendable supply is 50m x 90% float x 65% lendable = 29.25m shares, nearly all of it already out
        // on loan. 300k is comfortably inside what the desk will trade in one go but well past what is
        // left to borrow, so the borrow check is what has to reject it.
        $stock->setShortInterestShares('29000000.00');

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/available to borrow/');

        $this->service->executeOrder($user, 'APEX', 'SHORT', 'MARKET', 300000);
    }

    public function testABondCannotBeSoldShort(): void
    {
        $user = $this->marginUser();

        $bond = new Bond();
        $bond->setTicker('G10-001')->setName('bond')->setTenorYears('10')->setPrice('1000');

        $this->stockRepoStub->method('findOneBy')->willReturn(null);
        $this->etfRepoStub->method('findOneBy')->willReturn(null);
        $this->bondRepoStub->method('findOneBy')->willReturn($bond);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Only equities can be sold short/');

        $this->service->executeOrder($user, 'G10-001', 'SHORT', 'MARKET', 1);
    }

    public function testCoveringReturnsTheBorrowAndReducesShortInterest(): void
    {
        $user = $this->marginUser();
        $stock = $this->shortableStock();
        $stock->setShortInterestShares('500.00');

        $holding = new UserStock();
        $holding->setUser($user);
        $holding->setStock($stock);
        $holding->setQuantity(-100);

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn($holding);

        $this->service->executeOrder($user, 'APEX', 'COVER', 'MARKET', 40);

        $this->assertSame(-60, (int) $holding->getQuantity());
        $this->assertSame(460.0, (float) $stock->getShortInterestShares());
    }

    public function testCoveringMoreThanIsShortIsRefused(): void
    {
        $user = $this->marginUser();
        $stock = $this->shortableStock();

        $holding = new UserStock();
        $holding->setUser($user);
        $holding->setStock($stock);
        $holding->setQuantity(-50);

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn($holding);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/No short position of that size/');

        $this->service->executeOrder($user, 'APEX', 'COVER', 'MARKET', 200);
    }

    public function testAnOversizedSellCannotQuietlyBecomeAShort(): void
    {
        // Opening a short by overselling would turn every fat-fingered sale into a borrowed position with
        // unbounded downside, and nothing in the order would record that the trader meant it.
        $user = $this->marginUser();
        $stock = $this->shortableStock();

        $holding = new UserStock();
        $holding->setUser($user);
        $holding->setStock($stock);
        $holding->setQuantity(10);

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn($holding);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Insufficient shares/');

        $this->service->executeOrder($user, 'APEX', 'SELL', 'MARKET', 500);
    }

    public function testABuyBeyondSettledCashBorrowsRatherThanFailing(): void
    {
        $user = $this->marginUser('1000.00');
        $stock = $this->shortableStock();

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn(null);

        // $1,000 of equity supports $2,000 of stock at a 50% initial requirement. 30 shares at $50 is
        // $1,500: more than the settled cash, inside the buying power, so the difference is borrowed.
        $this->service->executeOrder($user, 'APEX', 'BUY', 'MARKET', 30);

        $this->assertSame('0.0000', $user->getCashBalance(), 'Cash is drawn to zero before anything is borrowed.');
        $this->assertGreaterThan(400.0, (float) $user->getMarginDebit());
        $this->assertLessThan(600.0, (float) $user->getMarginDebit());
    }

    public function testAMarginAccountIsStillHeldToItsBuyingPower(): void
    {
        // Leverage is bounded, not unlimited: $1,000 of equity supports $2,000 of stock and no more.
        $user = $this->marginUser('1000.00');

        $this->stockRepoStub->method('findOneBy')->willReturn($this->shortableStock());
        $this->userStockRepoStub->method('findOneBy')->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/buying power/');

        $this->service->executeOrder($user, 'APEX', 'BUY', 'MARKET', 100);
    }

    public function testACashAccountStillCannotOverspend(): void
    {
        $user = new User();
        $user->setCashBalance('1000.00');

        $this->stockRepoStub->method('findOneBy')->willReturn($this->shortableStock());
        $this->userStockRepoStub->method('findOneBy')->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Insufficient funds/');

        $this->service->executeOrder($user, 'APEX', 'BUY', 'MARKET', 100);
    }

    public function testProceedsPayDownBorrowingBeforeTheyReachCash(): void
    {
        // Nobody pays margin interest while holding idle cash.
        $user = $this->marginUser('0.00');
        $user->setMarginDebit('3000.00');

        $stock = $this->shortableStock();

        $holding = new UserStock();
        $holding->setUser($user);
        $holding->setStock($stock);
        $holding->setQuantity(100);

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn($holding);

        $this->service->executeOrder($user, 'APEX', 'SELL', 'MARKET', 40);

        // 40 shares sold just under $50 raises a little under $2,000 against a $3,000 debit, so all of it
        // repays borrowing and none of it reaches the settled balance.
        $this->assertGreaterThan(1000.0, (float) $user->getMarginDebit());
        $this->assertLessThan(1100.0, (float) $user->getMarginDebit());
        $this->assertSame('0.0000', $user->getCashBalance());
    }

    public function testACoverIsReportedToOrderFlowAsBuyingAndAShortAsSelling(): void
    {
        // Impact follows the side, not the label: a cover buys stock and pushes the price up.
        $user = $this->marginUser();
        $stock = $this->shortableStock();

        $holding = new UserStock();
        $holding->setUser($user);
        $holding->setStock($stock);
        $holding->setQuantity(-500);

        $this->stockRepoStub->method('findOneBy')->willReturn($stock);
        $this->userStockRepoStub->method('findOneBy')->willReturn($holding);

        $this->service->executeOrder($user, 'APEX', 'COVER', 'MARKET', 100);
        $this->assertSame(['APEX' => 100.0], $this->orderFlow->drain());

        $this->service->executeOrder($user, 'APEX', 'SHORT', 'MARKET', 60);
        $this->assertSame(['APEX' => -60.0], $this->orderFlow->drain());
    }
}