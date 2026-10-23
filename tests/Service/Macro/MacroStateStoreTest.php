<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\Service\Macro\MacroState;
use App\Service\Macro\MacroStateStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The ticker holds the economy in memory and Redis holds the copy everything else reads, so the store is where a lost
 * or misdated copy has to be caught: at start-up against the database clock, and on every save after that.
 */
#[AllowMockObjectsWithoutExpectations]
class MacroStateStoreTest extends TestCase
{
    public function testALostStateIsRefusedOnceTheClockHasRun(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->atLeastOnce())->method('get')->with(MacroStateStore::KEY)->willReturn(false);
        $redis->expects($this->never())->method('set');

        $this->expectException(\RuntimeException::class);
        (new MacroStateStore($redis))->alignClock(205.8678);
    }

    public function testAnUndecodableStateIsRefusedOnceTheClockHasRun(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->atLeastOnce())->method('get')->with(MacroStateStore::KEY)->willReturn('{"total_time": 205.');
        $redis->expects($this->never())->method('set');

        $this->expectException(\RuntimeException::class);
        (new MacroStateStore($redis))->alignClock(205.8678);
    }

    public function testAFreshWorldOpensOnTheOpeningState(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->atLeastOnce())->method('get')->with(MacroStateStore::KEY)->willReturn(false);
        $redis->expects($this->never())->method('set');

        $state = (new MacroStateStore($redis))->alignClock(0.0);

        $this->assertEquals(new MacroState(), $state);
    }

    public function testAKeptStateIsRealignedNotReplaced(): void
    {
        $kept = new MacroState();
        $kept->totalTime = 205.868056;
        $kept->gdpDeflator = 92.57;
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->atLeastOnce())->method('get')->with(MacroStateStore::KEY)
            ->willReturn(json_encode($kept->toArray(), JSON_PRESERVE_ZERO_FRACTION));

        $saved = null;
        $redis->expects($this->once())->method('set')
            ->willReturnCallback(function (string $key, mixed $value) use (&$saved): bool {
                $saved = json_decode((string) $value, true);

                return true;
            });

        $state = (new MacroStateStore($redis))->alignClock(205.867778);

        $this->assertIsArray($saved);
        $this->assertSame(205.867778, $saved['total_time']);
        $this->assertSame(92.57, $saved['gdp_deflator'], 'The price level survives the realignment.');
        $this->assertSame(205.867778, $state->totalTime, 'The ticker steps the state it was handed back.');
        $this->assertSame(92.57, $state->gdpDeflator);
    }

    /**
     * A state lost mid-run (a flush, an eviction) used to be replaced by the opening state on the next tick, and the
     * ticker then wrote that Year-1 clock into the database. A store that saved it refuses to open a fresh one.
     */
    public function testAStateLostAfterTheStoreSavedItIsNotReopened(): void
    {
        $redis = self::inMemoryRedis();
        $store = new MacroStateStore($redis);

        // No state yet is a world not opened: the first save opens it.
        $store->save($store->load());
        $redis->flushAll();

        $this->expectException(\RuntimeException::class);
        $store->load();
    }

    /**
     * The ticker reads the key only at start-up, so a flush under it (a market reset run beside a live ticker) is
     * found on the next save, which must not write the old economy into the reset market.
     */
    public function testASaveOverAFlushedStateStopsTheTicker(): void
    {
        $redis = self::inMemoryRedis();
        $redis->set(MacroStateStore::KEY, (string) json_encode((new MacroState())->toArray(), JSON_PRESERVE_ZERO_FRACTION));
        $store = new MacroStateStore($redis);
        $state = $store->alignClock(0.0);

        $store->save($state);
        $redis->flushAll();

        try {
            $store->save($state);
            $this->fail('A save over a flushed state went through.');
        } catch (\RuntimeException) {
            $this->assertFalse($redis->get(MacroStateStore::KEY), 'Nothing is written back into the flushed store.');
        }
    }

    public function testASavedStateLoadsBackAsTheSameState(): void
    {
        $state = new MacroState();
        $state->totalTime = 12.25;
        $state->policyRate = 0.0475;
        $state->sectorDemandZ = ['energy' => -0.4, 'tech' => 1.25];
        $state->eventType = 'BANK_RUN';
        $store = new MacroStateStore(self::inMemoryRedis());

        $store->save($state);

        $this->assertEquals($state, $store->load());
    }

    public function testAPayloadThatIsNotAStateReadsAsNone(): void
    {
        foreach (['not json at all', '"a bare string"', '12345', '  '] as $corrupt) {
            $redis = $this->createMock(\Redis::class);
            $redis->method('get')->willReturn($corrupt);

            $this->assertSame([], (new MacroStateStore($redis))->payload());
            $this->assertNull((new MacroStateStore($redis))->saved());
        }
    }

    /** Redis as far as the store uses it: get, and set with its only-if-present option; flushAll for the reset. */
    private static function inMemoryRedis(): \Redis
    {
        return new class extends \Redis {
            /** @var array<string, string> */
            private array $held = [];

            public function get(mixed $key): mixed
            {
                return $this->held[(string) $key] ?? false;
            }

            public function set(string $key, mixed $value, mixed $options = null): bool
            {
                if (is_array($options) && in_array('xx', $options, true) && !isset($this->held[$key])) {
                    return false;
                }
                $this->held[$key] = (string) $value;

                return true;
            }

            public function flushAll(?bool $sync = null): bool
            {
                $this->held = [];

                return true;
            }
        };
    }
}
