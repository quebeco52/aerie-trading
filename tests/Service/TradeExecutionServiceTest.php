<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Etf;
use App\Entity\Stock;
use App\Entity\TradeOrder;
use App\Entity\User;
use App\Entity\UserEtf;
use App\Entity\UserStock;
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
    private TradeExecutionService $service;

    protected function setUp(): void
    {
        $this->emMock = $this->createMock(EntityManagerInterface::class);
        $this->connectionMock = $this->createStub(Connection::class);
        $this->connectionMock->method('isTransactionActive')->willReturn(true);
        $this->emMock->method('getConnection')->willReturn($this->connectionMock);

        $this->portfolioStub = $this->createStub(Portfolio::class);
        $this->redisStub = $this->createStub(\Redis::class);

        $this->stockRepoStub = $this->createStub(EntityRepository::class);
        $this->etfRepoStub = $this->createStub(EntityRepository::class);
        $this->userStockRepoStub = $this->createStub(EntityRepository::class);
        $this->userEtfRepoStub = $this->createStub(EntityRepository::class);
        $this->tradeOrderRepoStub = $this->createStub(EntityRepository::class);

        $this->emMock->method('getRepository')->willReturnCallback(function (string $entityClass) {
            return match ($entityClass) {
                Stock::class => $this->stockRepoStub,
                Etf::class => $this->etfRepoStub,
                UserStock::class => $this->userStockRepoStub,
                UserEtf::class => $this->userEtfRepoStub,
                TradeOrder::class => $this->tradeOrderRepoStub,
                default => $this->createStub(EntityRepository::class),
            };
        });

        $this->service = new TradeExecutionService(
            $this->emMock,
            $this->portfolioStub,
            $this->redisStub,
            new NullLogger()
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

        // Buy 10 shares @ $50.00 = $500.00 total cost
        $this->service->executeOrder($user, 'APEX', 'BUY', 'MARKET', 10);

        $this->assertEquals('500.0000', $user->getCashBalance());
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

        // Sell 10 shares @ $50.00 = +$500.00 cash
        $this->service->executeOrder($user, 'APEX', 'SELL', 'MARKET', 10);

        $this->assertEquals('700.0000', $user->getCashBalance());
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

        // Buy 5 ETF shares @ $100 = $500
        $this->service->executeOrder($user, 'LBI', 'BUY', 'MARKET', 5);

        $this->assertEquals('500.0000', $user->getCashBalance());
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

        // Process limit orders at $90.00 (below $100.00 limit -> fills with $100 refund)
        $this->service->processLimitOrders('APEX', 90.0);

        $this->assertSame('FILLED', $buyOrder->getStatus());
        $this->assertSame(10, $buyOrder->getFilledQuantity());
        $this->assertSame('90', $buyOrder->getExecutionPrice());
        // Refund: ($100 - $90) * 10 = $100.0000 returned to buyer
        $this->assertEquals('100.0000', $buyer->getCashBalance());
    }
}
