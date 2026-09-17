<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\TradeOrder;
use App\Service\Market\TradeExecutionService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Which way a stop points, and what it will accept once it fires.
 *
 * A stop is armed on the OPPOSITE side of the market from a limit with the same action: a sell limit sits
 * above the price and a sell stop sits below it. Getting that backwards turns every stop-loss into a
 * take-profit, silently, on the one order type people place specifically to protect themselves — so the
 * direction is pinned here rather than trusted to the reading of a comparison operator.
 */
final class StopOrderTriggerTest extends TestCase
{
    private ReflectionMethod $triggered;
    private ReflectionMethod $allows;

    protected function setUp(): void
    {
        $this->triggered = new ReflectionMethod(TradeExecutionService::class, 'stopIsTriggered');
        $this->allows = new ReflectionMethod(TradeExecutionService::class, 'limitAllowsFill');
    }

    private function fires(string $action, float $stop, float $price): bool
    {
        return (bool) $this->triggered->invoke(null, $action, $stop, $price);
    }

    public function testASellStopFiresWhenThePriceFallsToIt(): void
    {
        self::assertFalse($this->fires('SELL', 90.0, 100.0), 'Above the trigger, it waits.');
        self::assertTrue($this->fires('SELL', 90.0, 90.0), 'At the trigger, it fires.');
        self::assertTrue($this->fires('SELL', 90.0, 85.0), 'Through the trigger, it fires.');
    }

    public function testABuyStopFiresWhenThePriceRisesToIt(): void
    {
        self::assertFalse($this->fires('BUY', 110.0, 100.0));
        self::assertTrue($this->fires('BUY', 110.0, 110.0));
        self::assertTrue($this->fires('BUY', 110.0, 115.0));
    }

    /** A stop is the mirror image of a limit on the same side, which is the whole reason it exists. */
    public function testAStopPointsTheOppositeWayToALimitOnTheSameSide(): void
    {
        $price = 100.0;

        foreach (['SELL', 'SHORT'] as $sell) {
            self::assertTrue($this->fires($sell, 105.0, $price), 'A sell stop at 105 has already been passed.');
            self::assertFalse((bool) $this->allows->invoke(null, $sell, 105.0, $price), 'A sell limit at 105 has not.');
        }

        foreach (['BUY', 'COVER'] as $buy) {
            self::assertTrue($this->fires($buy, 95.0, $price), 'A buy stop at 95 has already been passed.');
            self::assertFalse((bool) $this->allows->invoke(null, $buy, 95.0, $price), 'A buy limit at 95 has not.');
        }
    }

    /** The covering leg of a short is a buy, and a stop on it has to point the same way a buy stop does. */
    public function testCoverIsTreatedAsABuyAndShortAsASell(): void
    {
        self::assertSame($this->fires('BUY', 110.0, 115.0), $this->fires('COVER', 110.0, 115.0));
        self::assertSame($this->fires('SELL', 90.0, 85.0), $this->fires('SHORT', 90.0, 85.0));
    }

    /**
     * The difference between the two stop types, and the reason a cascade hurts. A plain stop has no cap,
     * so it fills at whatever the book quotes once triggered; a stop-limit keeps the cap and will decline.
     */
    public function testOnlyTheStopLimitCapsItsFill(): void
    {
        $plain = (new TradeOrder())->setOrderType(TradeOrder::TYPE_STOP);
        $capped = (new TradeOrder())->setOrderType(TradeOrder::TYPE_STOP_LIMIT);
        $limit = (new TradeOrder())->setOrderType(TradeOrder::TYPE_LIMIT);
        $market = (new TradeOrder())->setOrderType(TradeOrder::TYPE_MARKET);

        self::assertTrue($plain->isStop());
        self::assertFalse($plain->hasLimitCap(), 'A plain stop takes what it gets. This is the point of it.');

        self::assertTrue($capped->isStop());
        self::assertTrue($capped->hasLimitCap());

        self::assertFalse($limit->isStop());
        self::assertTrue($limit->hasLimitCap());

        self::assertFalse($market->isStop());
        self::assertFalse($market->hasLimitCap());
    }

    public function testLimitAllowsFillIsTheOrdinaryPromiseAboutPrice(): void
    {
        self::assertTrue((bool) $this->allows->invoke(null, 'BUY', 100.0, 99.0), 'A buy fills below its limit.');
        self::assertTrue((bool) $this->allows->invoke(null, 'BUY', 100.0, 100.0));
        self::assertFalse((bool) $this->allows->invoke(null, 'BUY', 100.0, 100.01), 'And never through it.');

        self::assertTrue((bool) $this->allows->invoke(null, 'SELL', 100.0, 101.0));
        self::assertTrue((bool) $this->allows->invoke(null, 'SELL', 100.0, 100.0));
        self::assertFalse((bool) $this->allows->invoke(null, 'SELL', 100.0, 99.99));
    }
}
