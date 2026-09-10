<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Service\Market\Flow\InMemoryOrderFlowStore;
use App\Service\Market\Flow\RedisOrderFlowStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The handoff between the process that takes trades and the one that moves prices.
 *
 * Two properties carry the weight. Flow must accumulate, or a trader splitting an order into a hundred
 * pieces pays a hundred small square roots instead of the one large one they owe. And a drain must be
 * final, or the same shares move the price again on the next tick and keep moving it forever.
 */
#[AllowMockObjectsWithoutExpectations]
class OrderFlowStoreTest extends TestCase
{
    public function testFlowAccumulatesUntilItIsDrained(): void
    {
        $store = new InMemoryOrderFlowStore();

        $store->record('APEX', 100.0);
        $store->record('APEX', 250.0);
        $store->record('BRIK', -75.0);

        $this->assertSame(['APEX' => 350.0, 'BRIK' => -75.0], $store->drain());
    }

    public function testBuysAndSellsNetAgainstEachOther(): void
    {
        // Two traders crossing in the same window move the price by nothing, which is the correct answer:
        // the shares changed hands without any net demand appearing.
        $store = new InMemoryOrderFlowStore();

        $store->record('APEX', 500.0);
        $store->record('APEX', -500.0);

        $this->assertSame(['APEX' => 0.0], $store->drain());
    }

    public function testADrainedQuantityIsNotReturnedAgain(): void
    {
        $store = new InMemoryOrderFlowStore();
        $store->record('APEX', 100.0);

        $this->assertSame(['APEX' => 100.0], $store->drain());
        $this->assertSame([], $store->drain());
    }

    public function testZeroQuantitiesAreIgnored(): void
    {
        $store = new InMemoryOrderFlowStore();
        $store->record('APEX', 0.0);

        $this->assertSame([], $store->drain());
    }

    public function testTheRedisStoreDegradesToNoFlowWhenRedisIsUnavailable(): void
    {
        // An order-flow outage must never abort a tick. The honest fallback is a price that moves on its
        // diffusion alone; holding the tick would stop the whole market over an auxiliary counter.
        //
        // A plain RuntimeException stands in for RedisException, which the phpredis extension defines and
        // the analysis environment does not load. The store catches Throwable, so the distinction does not
        // change what is being tested.
        $redis = $this->createMock(\Redis::class);
        $redis->method('multi')->willThrowException(new \RuntimeException('connection refused'));

        $store = new RedisOrderFlowStore($redis, new NullLogger());

        $this->assertSame([], $store->drain());
    }

    // The record path catches Throwable the same way, but cannot be exercised here: phpredis is not loaded
    // in the analysis environment, so hIncrByFloat is absent from the Redis class the mock is built from.
}
