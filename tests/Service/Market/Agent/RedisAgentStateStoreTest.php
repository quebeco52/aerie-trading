<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Agent;

use App\Service\Market\Agent\RedisAgentStateStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The agent book's round-trip budget.
 *
 * A tick touches every name once. Served per name, that was one HGET and one HSET per stock per tick,
 * and at sixty-odd names the round trips alone outran the tick budget. Inside a batch the store has to
 * make exactly one bulk read at the open and one pipelined write at the close, and nothing in between.
 */
#[AllowMockObjectsWithoutExpectations]
class RedisAgentStateStoreTest extends TestCase
{
    /** @var array{positions: array<string, float>, fitness: array<string, float>} */
    private const BOOK_A = ['positions' => ['momentum' => 10.0], 'fitness' => ['momentum' => 0.5]];

    /** @var array{positions: array<string, float>, fitness: array<string, float>} */
    private const BOOK_B = ['positions' => ['momentum' => -4.0], 'fitness' => ['momentum' => -0.2]];

    private \Redis&MockObject $redis;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(\Redis::class);
    }

    public function testABatchReadsTheWholeHashOnceAndNeverPerName(): void
    {
        $this->redis->expects($this->once())
            ->method('hGetAll')
            ->with('agent_state')
            ->willReturn(['AAA' => json_encode(self::BOOK_A), 'BBB' => json_encode(self::BOOK_B)]);
        $this->redis->expects($this->never())->method('hGet');

        $store = new RedisAgentStateStore($this->redis);
        $store->beginBatch();

        $this->assertSame(self::BOOK_A, $store->read('AAA'));
        $this->assertSame(self::BOOK_B, $store->read('BBB'));
        $this->assertNull($store->read('CCC'), 'A name absent from the hash has no book yet.');
    }

    public function testWritesInsideABatchAreHeldAndSentInOnePipelineAtCommit(): void
    {
        $this->redis->method('hGetAll')->willReturn([]);
        $this->redis->expects($this->once())->method('multi')->with(\Redis::PIPELINE)->willReturnSelf();

        $sent = [];
        $this->redis->expects($this->exactly(2))
            ->method('hSet')
            ->willReturnCallback(function (string $key, string $field, string $value) use (&$sent): int {
                $this->assertSame('agent_state', $key);
                $sent[$field] = json_decode($value, true);

                return 1;
            });
        $this->redis->expects($this->once())->method('exec')->willReturn([]);

        $store = new RedisAgentStateStore($this->redis);
        $store->beginBatch();
        $store->write('AAA', self::BOOK_A);
        $store->write('BBB', self::BOOK_B);

        $this->assertSame([], $sent, 'Nothing reaches Redis before the batch is committed.');

        $store->commitBatch();

        // Loose on purpose: json_encode writes 10.0 as 10, and the store's own decoder is what restores floats.
        $this->assertEquals(['AAA' => self::BOOK_A, 'BBB' => self::BOOK_B], $sent);
    }

    public function testTheStyleRecordRidesInTheSameBatchWithNoExtraRoundTrips(): void
    {
        // The market-wide score is one more field in the hash, so a tick still costs one HGETALL and one
        // pipeline. A key of its own would be a third round trip on every tick.
        $this->redis->expects($this->once())
            ->method('hGetAll')
            ->willReturn(['AAA' => json_encode(self::BOOK_A), '__style__' => json_encode(['momentum' => 0.25, 'fundamentalist' => -0.1])]);
        $this->redis->expects($this->never())->method('hGet');
        $this->redis->expects($this->once())->method('multi')->willReturnSelf();

        $sent = [];
        $this->redis->expects($this->once())
            ->method('hSet')
            ->willReturnCallback(function (string $key, string $field, string $value) use (&$sent): int {
                $sent[$field] = json_decode($value, true);

                return 1;
            });
        $this->redis->expects($this->once())->method('exec')->willReturn([]);

        $store = new RedisAgentStateStore($this->redis);
        $store->beginBatch();

        $this->assertSame(['momentum' => 0.25, 'fundamentalist' => -0.1], $store->readStyle());
        $this->assertNull($store->read('__style__'), 'The style record is not a book.');

        $store->writeStyle(['momentum' => 0.3, 'fundamentalist' => -0.2]);
        $this->assertSame(['momentum' => 0.3, 'fundamentalist' => -0.2], $store->readStyle(), 'A read after a write in the same batch sees the write.');

        $store->commitBatch();

        $this->assertEquals(['__style__' => ['momentum' => 0.3, 'fundamentalist' => -0.2]], $sent);
    }

    public function testAMarketWithNoStyleHistoryReadsAnEmptyRecord(): void
    {
        $this->redis->method('hGetAll')->willReturn([]);

        $store = new RedisAgentStateStore($this->redis);
        $store->beginBatch();

        $this->assertSame([], $store->readStyle());
        $this->assertSame([], $store->readCrossSection());
    }

    public function testTheCrossSectionRidesInTheSameBatchAsTheBooksAndTheStyle(): void
    {
        $this->redis->expects($this->once())
            ->method('hGetAll')
            ->willReturn(['AAA' => json_encode(self::BOOK_A), '__cross_section__' => json_encode(['log_mispricing' => 0.05])]);
        $this->redis->expects($this->never())->method('hGet');
        $this->redis->expects($this->once())->method('multi')->willReturnSelf();

        $sent = [];
        $this->redis->expects($this->once())
            ->method('hSet')
            ->willReturnCallback(function (string $key, string $field, string $value) use (&$sent): int {
                $sent[$field] = json_decode($value, true);

                return 1;
            });
        $this->redis->expects($this->once())->method('exec')->willReturn([]);

        $store = new RedisAgentStateStore($this->redis);
        $store->beginBatch();

        $this->assertSame(['log_mispricing' => 0.05], $store->readCrossSection());
        $this->assertNull($store->read('__cross_section__'), 'The cross-section is not a book.');
        $this->assertSame([], $store->readStyle(), 'One record does not stand in for the other.');

        $store->writeCrossSection(['log_mispricing' => -0.02]);
        $this->assertSame(['log_mispricing' => -0.02], $store->readCrossSection());

        $store->commitBatch();

        $this->assertEquals(['__cross_section__' => ['log_mispricing' => -0.02]], $sent);
    }

    public function testAReadAfterAWriteInTheSameBatchSeesTheWrite(): void
    {
        $this->redis->method('hGetAll')->willReturn(['AAA' => json_encode(self::BOOK_A)]);

        $store = new RedisAgentStateStore($this->redis);
        $store->beginBatch();
        $store->write('AAA', self::BOOK_B);

        $this->assertSame(self::BOOK_B, $store->read('AAA'));
    }

    public function testOnlyTheLastWritePerNameIsSent(): void
    {
        $this->redis->method('hGetAll')->willReturn([]);
        $this->redis->method('multi')->willReturnSelf();
        $this->redis->expects($this->once())
            ->method('hSet')
            ->with('agent_state', 'AAA', json_encode(self::BOOK_B))
            ->willReturn(1);

        $store = new RedisAgentStateStore($this->redis);
        $store->beginBatch();
        $store->write('AAA', self::BOOK_A);
        $store->write('AAA', self::BOOK_B);
        $store->commitBatch();
    }

    public function testAnEmptyBatchSendsNothing(): void
    {
        $this->redis->method('hGetAll')->willReturn([]);
        $this->redis->expects($this->never())->method('multi');
        $this->redis->expects($this->never())->method('exec');

        $store = new RedisAgentStateStore($this->redis);
        $store->beginBatch();
        $store->commitBatch();
    }

    public function testReopeningABatchDropsWhatTheAbandonedOneHeld(): void
    {
        $this->redis->method('hGetAll')->willReturn([]);
        $this->redis->method('multi')->willReturnSelf();
        $this->redis->expects($this->once())
            ->method('hSet')
            ->with('agent_state', 'BBB', json_encode(self::BOOK_B))
            ->willReturn(1);

        $store = new RedisAgentStateStore($this->redis);
        $store->beginBatch();
        $store->write('AAA', self::BOOK_A); // A tick that aborted before committing.
        $store->beginBatch();
        $store->write('BBB', self::BOOK_B);
        $store->commitBatch();
    }

    public function testOutsideABatchEachCallStandsAlone(): void
    {
        $this->redis->expects($this->never())->method('hGetAll');
        $this->redis->expects($this->once())
            ->method('hGet')
            ->with('agent_state', 'AAA')
            ->willReturn(json_encode(self::BOOK_A));
        $this->redis->expects($this->once())
            ->method('hSet')
            ->with('agent_state', 'BBB', json_encode(self::BOOK_B))
            ->willReturn(1);

        $store = new RedisAgentStateStore($this->redis);

        $this->assertSame(self::BOOK_A, $store->read('AAA'));
        $store->write('BBB', self::BOOK_B);
    }

    public function testAfterCommitTheStoreStopsServingTheOldSnapshot(): void
    {
        $this->redis->method('hGetAll')->willReturn(['AAA' => json_encode(self::BOOK_A)]);
        $this->redis->expects($this->once())
            ->method('hGet')
            ->with('agent_state', 'AAA')
            ->willReturn(json_encode(self::BOOK_B));

        $store = new RedisAgentStateStore($this->redis);
        $store->beginBatch();
        $store->commitBatch();

        $this->assertSame(self::BOOK_B, $store->read('AAA'), 'A read after the batch goes to Redis, not to the closed snapshot.');
    }

    public function testABulkReadFailureDegradesToEmptyBooksRatherThanAbortingTheTick(): void
    {
        $this->redis->method('hGetAll')->willThrowException(new \RuntimeException('gone'));

        $store = new RedisAgentStateStore($this->redis);
        $store->beginBatch();

        $this->assertNull($store->read('AAA'));
    }
    public function testABulkReadFailureCommitsNothingRatherThanOverwritingEveryBookWithAFreshOne(): void
    {
        // Served from nothing, every name opens a fresh book for the tick. Sending those back would replace
        // the market's whole agent memory with empty books because a read failed once.
        $this->redis->method('hGetAll')->willThrowException(new \RuntimeException('gone'));
        $this->redis->expects($this->never())->method('multi');
        $this->redis->expects($this->never())->method('hSet');

        $store = new RedisAgentStateStore($this->redis);
        $store->beginBatch();
        $store->write('AAA', self::BOOK_A);
        $store->writeStyle(['momentum' => 0.1]);
        $store->commitBatch();
    }

    public function testTheNextBatchAfterADegradedOneIsServedAndCommittedNormally(): void
    {
        $loads = 0;
        $this->redis->method('hGetAll')->willReturnCallback(static function () use (&$loads): array {
            if ($loads++ === 0) {
                throw new \RuntimeException('gone');
            }

            return ['AAA' => json_encode(self::BOOK_A)];
        });
        $this->redis->method('multi')->willReturnSelf();
        $this->redis->expects($this->once())->method('hSet')->willReturn(1);
        $this->redis->method('exec')->willReturn([]);

        $store = new RedisAgentStateStore($this->redis);
        $store->beginBatch();
        $store->write('AAA', self::BOOK_B);
        $store->commitBatch();

        $store->beginBatch();
        $this->assertSame(self::BOOK_A, $store->read('AAA'), 'The real book survived the degraded tick.');
        $store->write('AAA', self::BOOK_B);
        $store->commitBatch();
    }

    public function testExposuresAndVarianceRideWithTheBookAndAnOlderBookReadsBackAsWritten(): void
    {
        $full = ['positions' => ['momentum' => 10.0], 'fitness' => ['momentum' => 0.5], 'exposures' => ['momentum' => 0.8], 'variance' => 0.0625];

        $this->redis->method('hGetAll')->willReturn([
            'NEW' => json_encode($full),
            'OLD' => json_encode(self::BOOK_A),
        ]);

        $store = new RedisAgentStateStore($this->redis);
        $store->beginBatch();

        $this->assertSame($full, $store->read('NEW'));
        $this->assertSame(self::BOOK_A, $store->read('OLD'), 'No keys invented for a book written before they existed.');
    }
}
