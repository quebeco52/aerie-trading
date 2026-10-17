<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Ticker;

use App\Service\Market\Chart\ChartRange;
use App\Service\Market\Ticker\ChartBufferWriter;
use App\Service\Market\Ticker\TickCadence;
use PHPUnit\Framework\TestCase;

/**
 * Pins the past-month change the ticker puts on the wire: read back in the buffer pipeline from each list's oldest
 * entry after this tick's push and trim, which is the entry PriceChangeFeed reads for the server-rendered figure.
 */
class ChartBufferWriterTest extends TestCase
{
    private const TICKS_PER_YEAR = 3600;

    private static function entry(float $price): string
    {
        return (string) json_encode(['price' => $price, 'recorded_at' => '2026-10-13 13:00:00']);
    }

    /** A tick that writes and trims the equity buffers at the test's tick rate. */
    private static function trimTick(): int
    {
        for ($tick = 1; !TickCadence::isHistoryTick($tick, self::TICKS_PER_YEAR); $tick++);

        return $tick;
    }

    public function testAFrameReadsTheChangeFromTheOldestEntryAfterTheTrim(): void
    {
        $redis = new PipelineRedisFake();
        $length = ChartRange::bufferLength(TickCadence::equityBufferEntriesPerYear(self::TICKS_PER_YEAR));
        // Newest first: 90, 91, ... so after this tick's push and the trim the oldest kept is entry $length - 2.
        $redis->lists['chart_buffer:LAKE'] = array_map(static fn(int $i) => self::entry(90.0 + $i), range(0, $length + 4));

        $changes = (new ChartBufferWriter($redis))->write([['ticker' => 'LAKE', 'price' => 110.0]], [], self::trimTick(), self::TICKS_PER_YEAR, true);

        $this->assertCount($length, $redis->lists['chart_buffer:LAKE']);
        $this->assertEqualsWithDelta(110.0 / (90.0 + $length - 2) - 1.0, $changes['LAKE'], 1.0e-12);
    }

    public function testOnlyAFrameTickReadsTheBuffersBack(): void
    {
        $redis = new PipelineRedisFake();
        $redis->lists['chart_buffer:LAKE'] = [self::entry(100.0)];

        $changes = (new ChartBufferWriter($redis))->write([['ticker' => 'LAKE', 'price' => 101.0]], [], self::trimTick(), self::TICKS_PER_YEAR);

        $this->assertSame([], $changes);
        $this->assertSame(0, $redis->reads);
    }

    public function testEachQuoteReadsItsOwnBufferAndAFailedHouseNone(): void
    {
        $redis = new PipelineRedisFake();
        $redis->lists['chart_buffer:LAKE'] = [self::entry(100.0)];
        $redis->lists['chart_buffer:RIVR'] = [self::entry(50.0)];
        $redis->lists['chart_buffer:POOL'] = [self::entry(20.0)];
        $quotes = [
            ['ticker' => 'LAKE', 'price' => 120.0],
            ['ticker' => 'POOL', 'price' => 0.0, 'is_bankrupt' => true],
            ['ticker' => 'RIVR', 'price' => 45.0],
        ];

        $changes = (new ChartBufferWriter($redis))->write($quotes, [], self::trimTick(), self::TICKS_PER_YEAR, true);

        $this->assertEqualsWithDelta(0.20, $changes['LAKE'], 1.0e-12);
        $this->assertEqualsWithDelta(-0.10, $changes['RIVR'], 1.0e-12);
        $this->assertArrayNotHasKey('POOL', $changes, 'A failed house\'s chart is frozen; it carries no change');
    }

    public function testChangesRideTheirOwnQuotesRoundedAndUnknownStaysUnknown(): void
    {
        $quotes = [
            ['ticker' => 'LAKE', 'price' => 120.0],
            ['ticker' => 'RIVR', 'price' => 45.0],
            ['ticker' => 'AERIE-5Y', 'clean_price' => 99.1, 'price' => 99.4],
        ];

        $published = ChartBufferWriter::withChanges($quotes, ['LAKE' => 0.123456, 'RIVR' => null]);

        $this->assertSame(0.1235, $published[0]['change_percent']);
        $this->assertArrayHasKey('change_percent', $published[1]);
        $this->assertNull($published[1]['change_percent'], 'No buffered history is unknown, not flat');
        $this->assertArrayNotHasKey('change_percent', $published[2]);
    }
}

/**
 * A Redis whose pipeline runs each list command on in-memory lists and hands back the replies in order, as exec()
 * does. Parameters are untyped-compatible so the class extends both the extension and the test bootstrap's stub.
 */
final class PipelineRedisFake extends \Redis
{
    /** @var array<string, list<string>> newest first */
    public array $lists = [];
    public int $reads = 0;
    /** @var list<mixed> */
    private array $replies = [];

    public function multi(mixed $mode = \Redis::MULTI): \Redis
    {
        return $this;
    }

    public function lPush(mixed $key, mixed ...$elements): \Redis
    {
        foreach ($elements as $element) {
            array_unshift($this->lists[$key], (string) $element);
        }
        $this->replies[] = count($this->lists[$key]);

        return $this;
    }

    public function lTrim(mixed $key, mixed $start, mixed $end): \Redis
    {
        $this->lists[$key] = array_slice($this->lists[$key] ?? [], (int) $start, (int) $end - (int) $start + 1);
        $this->replies[] = true;

        return $this;
    }

    public function lIndex(mixed $key, mixed $index): mixed
    {
        $this->reads++;
        $list = $this->lists[$key] ?? [];
        $this->replies[] = $list === [] ? false : $list[$index < 0 ? count($list) + $index : $index];

        return $this;
    }

    public function exec(): array
    {
        $replies = $this->replies;
        $this->replies = [];

        return $replies;
    }
}
