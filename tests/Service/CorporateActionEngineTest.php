<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\Service\Corporate\CorporateActionEngine;
use App\Service\Event\MarketEventPublisher;
use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use App\Service\Corporate\DebtEngine;
use App\Service\Math\MathUtility;

class CorporateActionEngineTest extends TestCase
{
    private CorporateActionEngine $engine;

    protected function setUp(): void
    {
        // 1. Mock the Database Connection so we don't actually write to MariaDB during tests!
        $mockConnection = $this->createStub(Connection::class);
        $mockConnection->method('executeStatement')->willReturn(1);

        $mockEntityManager = $this->createStub(EntityManagerInterface::class);
        $mockEntityManager->method('getConnection')->willReturn($mockConnection);

        $mockMarketEvent = $this->createStub(MarketEventPublisher::class);
        $mockDebtEngine = $this->createStub(DebtEngine::class);
        $mockRedis = $this->createStub(\Redis::class);
        $mockMathUtility = $this->createStub(MathUtility::class);

        // 2. Instantiate the Engine with our fake database
        $this->engine = new CorporateActionEngine($mockEntityManager, $mockMarketEvent, $mockRedis);
    }

    public function testRecursiveForwardSplitProtectsNetWorth()
    {
        // Arrange: Create a dummy stock
        $stock = new Stock();
        $stock->setTicker('HYPR');
        $stock->setName('HyperCorp');
        $stock->setSharesOutstanding('1000');
        $stock->setEarningsPerShare('40.0'); // Internally calculates and locks TotalNetIncome

        $startingPrice = 1000.0; // Way over the $250 limit!
        $startingShares = 1000;
        $startingNetWorth = $startingPrice * $startingShares; // $1,000,000

        // Act: Run it through the engine
        $result = $this->engine->processSplits($stock, $startingPrice, $startingShares);
        $stock->setSharesOutstanding((string)$result['shares']); // Bridge logic test

        // Assert: The math must hold up!
        // 1000 -> 500 -> 250 (It should take exactly 2 splits to stabilize)
        $this->assertLessThanOrEqual(250.0, $result['price'], 'Price did not split below 250!');
        $this->assertEquals(250.0, $result['price']);
        
        // EPS should automatically drop from 40 to 10 because shares quadrupled while Net Income stayed constant
        $this->assertEquals('10', $stock->getEarningsPerShare());
        
        // Shares should double twice (1000 -> 2000 -> 4000)
        $this->assertEquals(4000, $result['shares']);

        // CRITICAL: Total company value (and player net worth) must remain exactly $1,000,000
        $newNetWorth = $result['price'] * $result['shares'];
        $this->assertEquals($startingNetWorth, $newNetWorth, 'The split destroyed player wealth!');
    }

    public function testRecursiveReverseSplitRescuesPennyStocks()
    {
        // Arrange: Create a dying penny stock
        $stock = new Stock();
        $stock->setTicker('DEAD');
        $stock->setName('DeadCorp');
        $stock->setSharesOutstanding('100000');
        $stock->setEarningsPerShare('0.01'); // Locks Net Income

        $startingPrice = 0.10; // 10 cents! Way below the $5.00 limit.
        $startingShares = 100000;
        $startingNetWorth = $startingPrice * $startingShares; // $10,000

        // Act: Run it through the engine
        $result = $this->engine->processSplits($stock, $startingPrice, $startingShares);
        $stock->setSharesOutstanding((string)$result['shares']); // Bridge logic test

        // Assert: The math must hold up!
        // 0.10 -> 1.00 -> 10.00 (It should take 2 reverse splits of 1-for-10)
        $this->assertGreaterThanOrEqual(2.0, $result['price'], 'Price did not reverse split above $2!');
        $this->assertEquals(10.00, $result['price']);

        // EPS should automatically multiply by 100
        $this->assertEquals('1', $stock->getEarningsPerShare());

        // Shares should be divided by 10, two times (100,000 -> 10,000 -> 1,000)
        $this->assertEquals(1000, $result['shares']);

        // CRITICAL: Net worth must remain exactly $10,000
        $newNetWorth = $result['price'] * $result['shares'];
        $this->assertEquals($startingNetWorth, $newNetWorth, 'The reverse split destroyed player wealth!');
    }

    public function testForwardSplitMutatesTradeOrders()
    {
        $mockConnection = $this->createMock(Connection::class);
        $executedQueries = [];
        $mockConnection->method('executeStatement')->willReturnCallback(function ($sql, $params = []) use (&$executedQueries) {
            $executedQueries[] = $sql;
            return 1;
        });

        $mockEntityManager = $this->createStub(EntityManagerInterface::class);
        $mockEntityManager->method('getConnection')->willReturn($mockConnection);

        $engine = new CorporateActionEngine($mockEntityManager, $this->createStub(MarketEventPublisher::class), $this->createStub(\Redis::class));

        $stock = new Stock();
        $stock->setTicker('HYPR');
        $stock->setName('HyperCorp');
        $stock->setSharesOutstanding('1000');
        $stock->setEarningsPerShare('40.0');

        $engine->processSplits($stock, 1000.0, 1000);

        $foundTradeOrderUpdate = false;
        foreach ($executedQueries as $sql) {
            if (str_contains($sql, 'UPDATE trade_orders SET quantity = quantity * :factor')) {
                $foundTradeOrderUpdate = true;
                break;
            }
        }
        $this->assertTrue($foundTradeOrderUpdate, 'Forward split did not execute trade_orders update SQL!');
    }

    public function testReverseSplitMutatesTradeOrdersAndRefundsRemainders()
    {
        $mockConnection = $this->createMock(Connection::class);
        $executedQueries = [];
        $mockConnection->method('executeStatement')->willReturnCallback(function ($sql, $params = []) use (&$executedQueries) {
            $executedQueries[] = $sql;
            return 1;
        });

        $mockEntityManager = $this->createStub(EntityManagerInterface::class);
        $mockEntityManager->method('getConnection')->willReturn($mockConnection);

        $engine = new CorporateActionEngine($mockEntityManager, $this->createStub(MarketEventPublisher::class), $this->createStub(\Redis::class));

        $stock = new Stock();
        $stock->setTicker('DEAD');
        $stock->setName('DeadCorp');
        $stock->setSharesOutstanding('100000');
        $stock->setEarningsPerShare('0.01');

        $engine->processSplits($stock, 0.10, 100000);

        $foundSellRemainderRefund = false;
        $foundBuyRemainderRefund = false;
        $foundTradeOrderUpdate = false;
        $foundOrderCancellation = false;

        foreach ($executedQueries as $sql) {
            if (str_contains($sql, "FROM trade_orders WHERE ticker = :ticker AND status = 'OPEN' AND action = 'SELL'")) {
                $foundSellRemainderRefund = true;
            }
            if (str_contains($sql, "FROM trade_orders") && str_contains($sql, "action = 'BUY' AND (quantity % :factor) > 0")) {
                $foundBuyRemainderRefund = true;
            }
            if (str_contains($sql, "UPDATE trade_orders SET quantity = FLOOR(quantity / :factor), limit_price = ROUND(limit_price * :factor, 8)")) {
                $foundTradeOrderUpdate = true;
            }
            if (str_contains($sql, "UPDATE trade_orders SET status = 'CANCELLED'")) {
                $foundOrderCancellation = true;
            }
        }

        $this->assertTrue($foundSellRemainderRefund, 'Reverse split did not refund open SELL order remainders!');
        $this->assertTrue($foundBuyRemainderRefund, 'Reverse split did not refund open BUY order remainders!');
        $this->assertTrue($foundTradeOrderUpdate, 'Reverse split did not update trade_orders quantity and limit_price!');
        $this->assertTrue($foundOrderCancellation, 'Reverse split did not cancel zero quantity trade_orders!');
    }
}