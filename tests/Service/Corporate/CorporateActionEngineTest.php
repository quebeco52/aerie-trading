<?php

namespace App\Tests\Service\Corporate;

use PHPUnit\Framework\TestCase;
use App\Service\Corporate\CorporateActionEngine;
use App\Service\Event\MarketEventPublisher;
use App\Entity\Stock;

class CorporateActionEngineTest extends TestCase
{
    private CorporateActionEngine $engine;

    protected function setUp(): void
    {
        $mockLedgerService = $this->createStub(\App\Service\Corporate\CorporateLedgerService::class);
        $mockMarketEvent = $this->createStub(MarketEventPublisher::class);
        $mockRedis = $this->createStub(\Redis::class);

        // 2. Instantiate the Engine with our fake database
        $this->engine = new CorporateActionEngine($mockLedgerService, $mockMarketEvent, $mockRedis);
    }

    private function stock(string $ticker, string $shares, string $eps = '1.0'): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName($ticker . ' Corp');
        $stock->setSharesOutstanding($shares);
        $stock->setEarningsPerShare($eps);

        return $stock;
    }

    public function testRecursiveForwardSplitProtectsNetWorth(): void
    {
        // Arrange: Create a dummy stock
        $stock = $this->stock('HYPR', '1000', '40.0'); // Internally calculates and locks TotalNetIncome

        $startingPrice = 1000.0; // Way over the $250 limit!
        $startingShares = 1000;
        $startingNetWorth = $startingPrice * $startingShares; // $1,000,000

        // Act: Run it through the engine
        $result = $this->engine->processSplits($stock, $startingPrice, $startingShares, 5.0);
        $stock->setSharesOutstanding((string)$result['shares']); // Bridge logic test

        // Assert: The math must hold up!
        // 1000 -> 500 -> 250 (It should take exactly 2 splits to stabilize)
        $this->assertLessThanOrEqual(250.0, $result['price'], 'Price did not split below 250!');
        $this->assertEquals(250.0, $result['price']);

        // EPS should automatically drop from 40 to 10 because shares quadrupled while Net Income stayed constant
        $this->assertEquals(10.0, (float) $stock->getEarningsPerShare());

        // Shares should double twice (1000 -> 2000 -> 4000)
        $this->assertEquals(4000, $result['shares']);

        // CRITICAL: Total company value (and player net worth) must remain exactly $1,000,000
        $newNetWorth = $result['price'] * $result['shares'];
        $this->assertEquals($startingNetWorth, $newNetWorth, 'The split destroyed player wealth!');
    }

    public function testRecursiveReverseSplitRescuesPennyStocks(): void
    {
        // Arrange: Create a dying penny stock
        $stock = $this->stock('DEAD', '100000000', '0.01'); // Locks Net Income

        $startingPrice = 0.10; // 10 cents! Way below the $2.00 limit.
        $startingShares = 100000000;
        $startingNetWorth = $startingPrice * $startingShares; // $10,000,000

        // Act: Run it through the engine
        $result = $this->engine->processSplits($stock, $startingPrice, $startingShares, 5.0);
        $stock->setSharesOutstanding((string)$result['shares']); // Bridge logic test

        // Assert: The math must hold up!
        // 0.10 -> 1.00 -> 10.00 (It should take 2 reverse splits of 1-for-10)
        $this->assertGreaterThanOrEqual(2.0, $result['price'], 'Price did not reverse split above $2!');
        $this->assertEquals(10.00, $result['price']);

        // EPS should automatically multiply by 100
        $this->assertEquals(1.0, (float) $stock->getEarningsPerShare());

        // Shares should be divided by 10, two times (100,000,000 -> 10,000,000 -> 1,000,000)
        $this->assertEquals(1000000, $result['shares']);

        // CRITICAL: Net worth must remain exactly $10,000,000
        $newNetWorth = $result['price'] * $result['shares'];
        $this->assertEquals($startingNetWorth, $newNetWorth, 'The reverse split destroyed player wealth!');
    }

    public function testForwardSplitDelegatesToCorporateLedgerService(): void
    {
        $mockLedger = $this->createMock(\App\Service\Corporate\CorporateLedgerService::class);
        $mockLedger->expects($this->once())
            ->method('processStockSplit')
            ->with($this->isInstanceOf(Stock::class), 4.0, false);

        $engine = new CorporateActionEngine($mockLedger, $this->createStub(MarketEventPublisher::class), $this->createStub(\Redis::class));

        $engine->processSplits($this->stock('HYPR', '1000', '40.0'), 1000.0, 1000, 5.0);
    }

    public function testReverseSplitDelegatesToCorporateLedgerService(): void
    {
        $mockLedger = $this->createMock(\App\Service\Corporate\CorporateLedgerService::class);
        $mockLedger->expects($this->once())
            ->method('processStockSplit')
            ->with($this->isInstanceOf(Stock::class), 100.0, true, 0.10);

        $engine = new CorporateActionEngine($mockLedger, $this->createStub(MarketEventPublisher::class), $this->createStub(\Redis::class));

        $engine->processSplits($this->stock('DEAD', '100000000', '0.01'), 0.10, 100000000, 5.0);
    }

    /**
     * A split is a board action, not a tick reflex. With none, a name whose value had collapsed split every
     * few ticks and the share count compounded through each cycle (TIER: 93 splits in eight minutes).
     */
    public function testAStockSplitsAtMostOnceAYear(): void
    {
        $stock = $this->stock('HYPR', '1000000');

        $first = $this->engine->processSplits($stock, 1000.0, 1000000, 5.0);
        $this->assertNotNull($first['event']);
        $this->assertSame(5.0, $stock->getLastSplitAt());

        foreach ([5.0, 5.5, 5.99] as $simTime) {
            $blocked = $this->engine->processSplits($stock, 900.0, $first['shares'], $simTime);
            $this->assertNull($blocked['event'], "Split again {$simTime} years in");
            $this->assertSame(900.0, $blocked['price']);
            $this->assertSame($first['shares'], $blocked['shares']);
        }

        $reverseBlocked = $this->engine->processSplits($stock, 0.5, $first['shares'], 5.5);
        $this->assertNull($reverseBlocked['event'], 'A reverse split inside the year is held back too.');

        $later = $this->engine->processSplits($stock, 900.0, $first['shares'], 5.0 + CorporateActionEngine::SPLIT_COOLDOWN_YEARS);
        $this->assertNotNull($later['event']);
    }

    /** A clock rewound behind the last split (an unclean Redis stop) leaves that split in a timeline that no longer exists. */
    public function testARewoundClockDoesNotHoldASplitBack(): void
    {
        $stock = $this->stock('HYPR', '1000000');
        $stock->setLastSplitAt(10.0);

        $result = $this->engine->processSplits($stock, 1000.0, 1000000, 3.0);

        $this->assertNotNull($result['event']);
        $this->assertSame(3.0, $stock->getLastSplitAt());
    }

    /**
     * The consolidation stops at the listing's share minimum even with the price still under $2. Flooring the
     * count at one share while multiplying the price by the full factor minted value out of nothing.
     */
    public function testAReverseSplitNeverTakesTheShareCountBelowTheListingMinimum(): void
    {
        // 60M shares at a tenth of a cent: 1-for-100 leaves 600k; 1-for-1000 would leave 60k.
        $stock = $this->stock('PENY', '60000000');
        $result = $this->engine->processSplits($stock, 0.001, 60000000, 5.0);

        $this->assertNotNull($result['event']);
        $this->assertEqualsWithDelta(0.1, $result['price'], 1e-12);
        $this->assertSame(600000.0, $result['shares']);
        $this->assertGreaterThanOrEqual(CorporateActionEngine::MIN_SHARES_AFTER_REVERSE_SPLIT, $result['shares']);

        // Fewer than ten times the minimum: no consolidation at all.
        $small = $this->stock('TINY', '4000000');
        $none = $this->engine->processSplits($small, 0.5, 4000000, 5.0);

        $this->assertNull($none['event']);
        $this->assertSame(0.5, $none['price']);
        $this->assertSame(4000000.0, $none['shares']);
        $this->assertNull($small->getLastSplitAt());
    }

    /** A reverse split moves value only by the fraction of a new share each holder is paid in cash. */
    public function testAReverseSplitConservesValueUpToTheCashInLieu(): void
    {
        foreach ([[12345678, 0.37], [5000000, 1.99], [987654321, 0.0042]] as [$shares, $price]) {
            $stock = $this->stock('CONS', (string) $shares);
            $result = $this->engine->processSplits($stock, $price, $shares, 5.0);

            $this->assertNotNull($result['event']);
            $before = $price * $shares;
            $after = $result['price'] * $result['shares'];
            $this->assertLessThanOrEqual($before + 1e-6, $after, 'A reverse split created value.');
            $this->assertGreaterThan($before - $result['price'], $after, 'A reverse split lost more than one new share.');
        }
    }

    /** A split that would overflow the share count is not done; clamping the count while dividing the price destroyed value. */
    public function testAForwardSplitThatWouldOverflowTheShareCountIsNotDone(): void
    {
        $shares = 4.0e18;
        $stock = $this->stock('HUGE', '4000000000000000000');

        $result = $this->engine->processSplits($stock, 1000.0, $shares, 5.0);

        $this->assertNull($result['event']);
        $this->assertSame(1000.0, $result['price']);
        $this->assertSame($shares, $result['shares']);
    }
}
