<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Index;

use App\Service\Market\Index\MarketIndex;
use App\Service\Market\Index\RedisIndexMembershipStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A roster changes four times a year and is read many times a tick, so the store reads it from Redis once and holds
 * it: until this process stores a new one, or the service is reset between a web worker's requests.
 */
#[AllowMockObjectsWithoutExpectations]
class RedisIndexMembershipStoreTest extends TestCase
{
    private \Redis&MockObject $redis;

    /** @var array<string, string> */
    private array $data = [];

    protected function setUp(): void
    {
        $this->redis = $this->createMock(\Redis::class);
        $this->redis->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
            $this->data[$key] = $value;

            return true;
        });
    }

    public function testARosterIsReadOnceAndHeld(): void
    {
        $this->redis->expects($this->once())->method('get')->willReturnCallback(fn(string $key): string|false => $this->data[$key] ?? false);
        $this->data[MarketIndex::market()->membershipKey()] = json_encode(['tick' => 7, 'tickers' => ['AAA', 'BBB'], 'factors' => ['AAA' => 0.5]], JSON_THROW_ON_ERROR);
        $store = new RedisIndexMembershipStore($this->redis);

        $first = $store->current(MarketIndex::market());
        $this->assertSame(['AAA', 'BBB'], $first['tickers'] ?? null);
        $this->assertSame($first, $store->current(MarketIndex::market()));
    }

    public function testARosterStoredIsTheOneReadBackAsAnyReaderDecodesIt(): void
    {
        $this->redis->method('get')->willReturnCallback(fn(string $key): string|false => $this->data[$key] ?? false);
        $store = new RedisIndexMembershipStore($this->redis);
        $this->assertNull($store->current(MarketIndex::market()), 'no membership taken yet');

        $store->store(MarketIndex::market(), 12, ['AAA', 'CCC'], ['CCC'], ['BBB'], ['AAA' => 0.6, 'CCC' => 0.4], ['AAA' => 1.0, 'CCC' => 0.75]);

        $this->assertSame((new RedisIndexMembershipStore($this->redis))->current(MarketIndex::market()), $store->current(MarketIndex::market()));
        $this->assertSame(12, $store->current(MarketIndex::market())['tick'] ?? null);
    }

    public function testAResetOrAFailedReadReadsAgain(): void
    {
        $key = MarketIndex::market()->membershipKey();
        $this->data[$key] = json_encode(['tick' => 1, 'tickers' => ['AAA']], JSON_THROW_ON_ERROR);
        $reads = 0;
        $this->redis->method('get')->willReturnCallback(function (string $key) use (&$reads): string|false {
            if (++$reads === 1) {
                throw new \RedisException('connection lost');
            }

            return $this->data[$key] ?? false;
        });
        $store = new RedisIndexMembershipStore($this->redis);

        $failed = $store->current(MarketIndex::market());
        $retried = $store->current(MarketIndex::market());
        $this->assertNull($failed, 'a failed read is no roster');
        $this->assertSame(['AAA'], $retried['tickers'] ?? null, 'and is not held');

        // Another process reconstitutes; a worker sees it on its next request.
        $this->data[$key] = json_encode(['tick' => 2, 'tickers' => ['BBB']], JSON_THROW_ON_ERROR);
        $this->assertSame(['AAA'], $store->current(MarketIndex::market())['tickers'] ?? null);
        $store->reset();
        $this->assertSame(['BBB'], $store->current(MarketIndex::market())['tickers'] ?? null);
    }
}
