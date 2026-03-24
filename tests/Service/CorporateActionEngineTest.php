<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\Service\CorporateActionEngine;
use App\Service\MarketEvent;
use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;

class CorporateActionEngineTest extends TestCase
{
    private CorporateActionEngine $engine;

    protected function setUp(): void
    {
        // 1. Mock the Database Connection so we don't actually write to MariaDB during tests!
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('executeStatement')->willReturn(1);

        $mockEntityManager = $this->createMock(EntityManagerInterface::class);
        $mockEntityManager->method('getConnection')->willReturn($mockConnection);

        $mockMarketEvent = $this->createMock(MarketEvent::class);

        // 2. Instantiate the Engine with our fake database
        $this->engine = new CorporateActionEngine($mockEntityManager, $mockMarketEvent);
    }

    public function testRecursiveForwardSplitProtectsNetWorth()
    {
        // Arrange: Create a dummy stock
        $stock = new Stock();
        $stock->setTicker('HYPR');
        $stock->setName('HyperCorp');

        $startingPrice = 1000.0; // Way over the $250 limit!
        $startingEps = 40.0;
        $startingShares = 1000;
        $startingNetWorth = $startingPrice * $startingShares; // $1,000,000

        // Act: Run it through the engine
        $result = $this->engine->processSplits($stock, $startingPrice, $startingEps, $startingShares);

        // Assert: The math must hold up!
        // 1000 -> 500 -> 250 (It should take exactly 2 splits to stabilize)
        $this->assertLessThanOrEqual(250.0, $result['price'], 'Price did not split below 250!');
        $this->assertEquals(250.0, $result['price']);
        
        // EPS should also be cut in half twice (40 -> 20 -> 10)
        $this->assertEquals(10.0, $result['eps']);
        
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

        $startingPrice = 0.10; // 10 cents! Way below the $5.00 limit.
        $startingEps = 0.01;
        $startingShares = 100000;
        $startingNetWorth = $startingPrice * $startingShares; // $10,000

        // Act: Run it through the engine
        $result = $this->engine->processSplits($stock, $startingPrice, $startingEps, $startingShares);

        // Assert: The math must hold up!
        // 0.10 -> 1.00 -> 10.00 (It should take 2 reverse splits of 1-for-10)
        $this->assertGreaterThanOrEqual(2.0, $result['price'], 'Price did not reverse split above $2!');
        $this->assertEquals(10.00, $result['price']);

        // Shares should be divided by 10, two times (100,000 -> 10,000 -> 1,000)
        $this->assertEquals(1000, $result['shares']);

        // CRITICAL: Net worth must remain exactly $10,000
        $newNetWorth = $result['price'] * $result['shares'];
        $this->assertEquals($startingNetWorth, $newNetWorth, 'The reverse split destroyed player wealth!');
    }
}