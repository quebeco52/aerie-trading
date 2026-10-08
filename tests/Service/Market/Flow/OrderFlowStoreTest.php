<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Flow;

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

    public function testOutsideABatchEveryRecordWritesThrough(): void
    {
        // The web process never opens a batch and never drains, so a fill held in process there would be lost.
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->never())->method('multi');
        $redis->expects($this->exactly(2))->method('hIncrByFloat');

        $store = new RedisOrderFlowStore($redis, new NullLogger());
        $store->record('APEX', 100.0);
        $store->record('APEX', 250.0);
    }

    public function testABatchIsNettedPerTickerAndSentAsOnePipeline(): void
    {
        $sent = new \ArrayObject();
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())->method('multi')->with(\Redis::PIPELINE)->willReturnSelf();
        $redis->method('hIncrByFloat')->willReturnCallback(
            static function (string $key, string $ticker, float $quantity) use ($sent): float {
                $sent->append([$key, $ticker, $quantity]);

                return $quantity;
            }
        );
        $redis->expects($this->once())->method('exec')->willReturn([]);

        $store = new RedisOrderFlowStore($redis, new NullLogger());
        $store->beginBatch();
        $store->record('APEX', 100.0);
        $store->record('BRIK', -75.0);
        $store->record('APEX', 250.0);
        // Crossed to nothing inside the batch: not worth a command, and the drain would skip it anyway.
        $store->record('CRUX', 40.0);
        $store->record('CRUX', -40.0);

        $this->assertCount(0, $sent, 'Nothing reaches Redis before the commit.');

        $store->commitBatch();

        $this->assertSame([['order_flow', 'APEX', 350.0], ['order_flow', 'BRIK', -75.0]], $sent->getArrayCopy());
    }

    public function testAnEmptyBatchSendsNothing(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->never())->method('multi');

        $store = new RedisOrderFlowStore($redis, new NullLogger());
        $store->beginBatch();
        $store->commitBatch();
    }

    public function testTheStoreWritesThroughAgainAfterACommit(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('multi')->willReturnSelf();
        $redis->expects($this->exactly(2))->method('hIncrByFloat');

        $store = new RedisOrderFlowStore($redis, new NullLogger());
        $store->beginBatch();
        $store->record('APEX', 100.0);
        $store->commitBatch();

        $store->record('APEX', 5.0);
    }

    public function testADrainTakesFlowABatchHasNotSentYet(): void
    {
        // A tick that throws between beginBatch() and commitBatch() leaves flow held in process. Written
        // through, it would already be in the hash for the next drain; the drain must see it all the same,
        // added to whatever the web process wrote in the meantime.
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())->method('multi')->with(\Redis::MULTI)->willReturnSelf();
        $redis->method('exec')->willReturn([['APEX' => '10', 'BRIK' => '-5'], 1]);
        $redis->expects($this->never())->method('hIncrByFloat');

        $store = new RedisOrderFlowStore($redis, new NullLogger());
        $store->beginBatch();
        $store->record('APEX', 100.0);
        $store->record('CRUX', 7.0);

        $this->assertEquals(['APEX' => 110.0, 'CRUX' => 7.0, 'BRIK' => -5.0], $store->drain());
    }

    public function testADrainIsFinalForBatchFlowToo(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('multi')->willReturnSelf();
        $redis->method('exec')->willReturn([[], 1]);

        $store = new RedisOrderFlowStore($redis, new NullLogger());
        $store->beginBatch();
        $store->record('APEX', 100.0);

        $this->assertSame(['APEX' => 100.0], $store->drain());
        $this->assertSame([], $store->drain());
    }

    public function testAFailedDrainStillReturnsTheFlowHeldInProcess(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('multi')->willThrowException(new \RuntimeException('connection refused'));

        $store = new RedisOrderFlowStore($redis, new NullLogger());
        $store->beginBatch();
        $store->record('APEX', 100.0);

        $this->assertSame(['APEX' => 100.0], $store->drain());
    }

    public function testAFailedRecordDegradesToNoFlow(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('hIncrByFloat')->willThrowException(new \RuntimeException('connection refused'));

        $store = new RedisOrderFlowStore($redis, new NullLogger());
        $store->record('APEX', 100.0);

        $this->addToAssertionCount(1);
    }
}
