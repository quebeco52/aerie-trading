<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Chart;

use App\Service\Market\Chart\WireFrame;
use PHPUnit\Framework\TestCase;

class WireFrameTest extends TestCase
{
    public function testAFrameCarriesTheLatestQuoteAndEveryTickPoint(): void
    {
        $frame = new WireFrame();

        $frame->absorb(10, [['ticker' => 'WREN', 'price' => 10.0, 'volume' => 100.0, 'sector' => 'Rail']], [], null, []);
        $frame->absorb(11, [['ticker' => 'WREN', 'price' => 10.5, 'volume' => 50.0, 'sector' => 'Rail']], [], null, []);
        $frame->absorb(12, [['ticker' => 'WREN', 'price' => 10.2, 'volume' => 25.0, 'sector' => 'Rail']], [], null, []);

        $wire = $frame->flush(12, 1_700_000_000);

        $this->assertSame(10, $wire['first_tick']);
        $this->assertSame(12, $wire['tick']);
        $this->assertSame(3, $wire['ticks']);
        $this->assertSame(1_700_000_000, $wire['timestamp']);
        $this->assertCount(1, $wire['stocks']);

        // The page reads the latest state; the chart replays the ticks in order, one point per tick, so
        // its live bar's high (10.5) and low (10.0) come out the same as the server's.
        $wren = $wire['stocks'][0];
        $this->assertSame(10.2, $wren['price']);
        $this->assertSame('Rail', $wren['sector']);
        $this->assertSame([[10, 10.0, 100.0], [11, 10.5, 50.0], [12, 10.2, 25.0]], $wren['points']);
    }

    /**
     * The frame carries only part of the macro state, so a field a live page starts reading must be added to the list
     * or its tile silently stops moving. Every macro field these scripts name, and every field a district stress rule
     * reads, must survive the cut.
     */
    public function testTheFrameKeepsEveryMacroFieldALivePageReads(): void
    {
        $macro = (new \App\DTO\MacroStateDTO())->toArray();
        $live = WireFrame::liveMacro($macro);
        $root = dirname(__DIR__, 4);

        foreach (['assets/js/pages/home.js', 'assets/js/pages/reserve.js', 'assets/js/economy/macro-vitals.js'] as $script) {
            $source = (string) file_get_contents($root . '/' . $script);
            foreach (array_keys($macro) as $field) {
                if (preg_match('/\.' . $field . '\b/', $source) === 1) {
                    $this->assertArrayHasKey($field, $live, "{$script} reads {$field} off the frame.");
                }
            }
        }
        foreach (\App\Data\District\DistrictMap::stressFields() as $field) {
            $this->assertArrayHasKey($field, $macro, "A stress rule reads {$field}, which the macro state does not have.");
            $this->assertArrayHasKey($field, $live, "The district map reads {$field} off the frame.");
        }
        $this->assertLessThan(count($macro) / 4, count($live), 'Most of the state stays off the wire.');
    }

    public function testBondsChartTheCleanPriceAndFundsCarryNoVolume(): void
    {
        $frame = new WireFrame();
        $frame->absorb(1, [
            ['ticker' => 'G10-043', 'price' => 101.25, 'clean_price' => 100.9],
            ['ticker' => 'LBI', 'price' => 250.0],
        ], [], null, []);

        $stocks = $frame->flush(1, 0)['stocks'];

        // Same rule as the Redis chart buffer: the live tail joins the buffered series without an accrual step.
        $this->assertSame([[1, 100.9, null]], $stocks[0]['points']);
        $this->assertSame(101.25, $stocks[0]['price']);
        $this->assertSame([[1, 250.0, null]], $stocks[1]['points']);
    }

    public function testAnInstrumentQuotedOnSomeTicksOnlyKeepsThePointsItHad(): void
    {
        $frame = new WireFrame();
        // Bonds are only published on history ticks; a frame that spans one carries the bond once.
        $frame->absorb(1, [['ticker' => 'WREN', 'price' => 1.0]], [], null, []);
        $frame->absorb(2, [['ticker' => 'WREN', 'price' => 2.0], ['ticker' => 'G10-043', 'price' => 99.0, 'clean_price' => 98.0]], [], null, []);
        $frame->absorb(3, [['ticker' => 'WREN', 'price' => 3.0]], [], null, []);

        $byTicker = [];
        foreach ($frame->flush(3, 0)['stocks'] as $quote) {
            $byTicker[$quote['ticker']] = $quote['points'];
        }

        $this->assertSame([[1, 1.0, null], [2, 2.0, null], [3, 3.0, null]], $byTicker['WREN']);
        $this->assertSame([[2, 98.0, null]], $byTicker['G10-043']);
    }

    public function testEventsAppendWhileDistrictAndScalarsKeepTheLatest(): void
    {
        $frame = new WireFrame();
        $frame->absorb(1, [], [['type' => 'SHOCK']], ['promoted' => ['A'], 'evicted' => []], ['market_vol' => 0.2, 'macro' => ['a' => 1]]);
        $frame->absorb(2, [], [], null, []);
        $frame->absorb(3, [], [['type' => 'SPLIT'], ['type' => 'DISTRICT']], null, ['market_vol' => 0.3]);

        $wire = $frame->flush(3, 0);

        // A split or a shock is a one-off: coalescing "latest" over events would lose it.
        $this->assertSame([['type' => 'SHOCK'], ['type' => 'SPLIT'], ['type' => 'DISTRICT']], $wire['events']);
        // A reconstitution on any tick of the frame survives the ticks after it that had none.
        $this->assertSame(['promoted' => ['A'], 'evicted' => []], $wire['district']);
        $this->assertSame(0.3, $wire['market_vol']);
        $this->assertSame(['a' => 1], $wire['macro']);
    }

    public function testFlushStartsAFreshFrame(): void
    {
        $frame = new WireFrame();
        $frame->absorb(1, [['ticker' => 'WREN', 'price' => 1.0]], [['type' => 'SHOCK']], ['promoted' => [], 'evicted' => []], ['market_vol' => 0.2]);
        $frame->flush(1, 0);
        $this->assertTrue($frame->isEmpty());

        $frame->absorb(2, [['ticker' => 'WEAV', 'price' => 2.0]], [], null, []);
        $wire = $frame->flush(2, 0);

        $this->assertSame(2, $wire['first_tick']);
        $this->assertSame(1, $wire['ticks']);
        $this->assertSame(['WEAV'], array_column($wire['stocks'], 'ticker'));
        $this->assertSame([], $wire['events']);
        $this->assertNull($wire['district']);
        $this->assertArrayNotHasKey('market_vol', $wire);
    }

    public function testFrameCadenceFollowsTheTickInterval(): void
    {
        // 10 ms ticks: a frame every 10 ticks; 100 ms ticks: every tick, nothing changes from before.
        $this->assertSame(10, WireFrame::intervalTicks(10_000));
        $this->assertSame(5, WireFrame::intervalTicks(20_000));
        $this->assertSame(1, WireFrame::intervalTicks(100_000));
        $this->assertSame(1, WireFrame::intervalTicks(500_000));

        $this->assertTrue(WireFrame::isFrameTick(0, 10_000));
        $this->assertFalse(WireFrame::isFrameTick(7, 10_000));
        $this->assertTrue(WireFrame::isFrameTick(30, 10_000));
        $this->assertTrue(WireFrame::isFrameTick(7, 100_000));

        // Frames per second is what the constant promises, at every rate coarser than a frame.
        foreach ([10_000, 20_000, 25_000, 50_000] as $intervalUs) {
            $this->assertEqualsWithDelta(
                WireFrame::FRAMES_PER_SECOND,
                1_000_000 / ($intervalUs * WireFrame::intervalTicks($intervalUs)),
                WireFrame::FRAMES_PER_SECOND * 0.2,
                "interval $intervalUs"
            );
        }
    }
}
