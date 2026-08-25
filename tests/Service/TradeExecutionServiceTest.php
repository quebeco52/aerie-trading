<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Stock;
use App\Entity\TradeOrder;
use App\Entity\User;
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

#[AllowMockObjectsWithoutExpectations]
class TradeExecutionServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $emMock;
    private Connection&Stub $connectionMock;
    private Portfolio&Stub $portfolioStub;
    private \Redis&Stub $redisStub;
    private EntityRepository&Stub $stockRepoStub;
    private EntityRepository&Stub $userStockRepoStub;
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
        $this->userStockRepoStub = $this->createStub(EntityRepository::class);
        $this->tradeOrderRepoStub = $this->createStub(EntityRepository::class);

        $this->emMock->method('getRepository')->willReturnCallback(function (string $entityClass) {
            return match ($entityClass) {
                Stock::class => $this->stockRepoStub,
                UserStock::class => $this->userStockRepoStub,
                TradeOrder::class => $this->tradeOrderRepoStub,
                default => $this->createStub(EntityRepository::class),
            };
        });

        $this->service = new TradeExecutionService(
            $this->emMock,
            $this->portfolioStub,
            $this->redisStub
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

    public function testExecuteOrderThrowsOnInvalidQuantity(): void
    {
        $user = new User();
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid quantity.');

        $this->service->executeOrder($user, 'APEX', 'BUY', 'MARKET', 0);
    }
}
