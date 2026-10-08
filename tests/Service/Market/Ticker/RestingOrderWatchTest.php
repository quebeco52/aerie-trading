<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Ticker;

use App\Service\Market\Ticker\RestingOrderWatch;
use App\Service\Market\Trading\LimitOrderDispatchGate;
use App\Service\Notification\PriceAlertService;
use PHPUnit\Framework\TestCase;

/**
 * Which instruments the tick asks the worker to look at: a quote through a resting order's bound, or one with no
 * cached book at all; never one sitting inside its bounds, and never twice while the worker has not answered.
 */
final class RestingOrderWatchTest extends TestCase
{
    /**
     * @param array<string, string> $limitBounds JSON bounds per ticker; absent means no cached book.
     * @param array<string, string> $alertBounds
     */
    private function watch(array $limitBounds, array $alertBounds = []): RestingOrderWatch
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('mGet')->willReturnCallback(static fn (array $keys): array => array_map(
            static fn (string $key): string|false => $limitBounds[substr($key, strlen('limit_bounds:'))] ?? false,
            $keys
        ));
        $redis->method('hGetAll')->willReturnCallback(
            static fn (string $key): array => $key === PriceAlertService::REDIS_KEY ? $alertBounds : []
        );

        return new RestingOrderWatch($redis, new LimitOrderDispatchGate());
    }

    public function testAQuoteThroughABoundIsCheckedAndOneInsideIsNot(): void
    {
        $watch = $this->watch([
            'BUY' => '{"buy": 95.0, "sell": 120.0}',
            'SELL' => '{"buy": 80.0, "sell": 99.0}',
            'QUIET' => '{"buy": 90.0, "sell": 110.0}',
        ]);

        $pending = $watch->collect([
            ['ticker' => 'BUY', 'price' => 94.0],
            ['ticker' => 'SELL', 'price' => 100.0],
            ['ticker' => 'QUIET', 'price' => 100.0],
        ], [], 1);

        self::assertSame(['BUY' => 94.0, 'SELL' => 100.0], $pending['limit_orders']);
    }

    public function testAMissingBookIsCheckedOnceUntilTheWorkerAnswers(): void
    {
        $watch = $this->watch([]);

        self::assertSame(['NEW' => 10.0], $watch->collect([['ticker' => 'NEW', 'price' => 10.0]], [], 1)['limit_orders']);
        self::assertSame([], $watch->collect([['ticker' => 'NEW', 'price' => 10.0]], [], 2)['limit_orders']);
    }

    public function testAFailedNameIsNeverChecked(): void
    {
        $watch = $this->watch([]);

        $pending = $watch->collect([['ticker' => 'DEAD', 'price' => 0.01, 'is_bankrupt' => true]], [], 1);

        self::assertSame([], $pending['limit_orders']);
    }

    public function testAStruckBondIsWatchedLikeAnyQuote(): void
    {
        $watch = $this->watch(['BND' => '{"buy": 97.0, "sell": 105.0}']);

        $pending = $watch->collect([], [['ticker' => 'BND', 'price' => 96.5]], 1);

        self::assertSame(['BND' => 96.5], $pending['limit_orders']);
    }

    public function testAnAlertFiresWhenItsTargetIsReached(): void
    {
        $watch = $this->watch(
            ['AAA' => '{"buy": 0.0, "sell": 999.0}', 'BBB' => '{"buy": 0.0, "sell": 999.0}'],
            ['AAA' => '{"above": 50.0}', 'BBB' => '{"below": 20.0}']
        );

        $pending = $watch->collect([['ticker' => 'AAA', 'price' => 51.0], ['ticker' => 'BBB', 'price' => 25.0]], [], 1);

        self::assertSame(['AAA' => 51.0], $pending['alerts']);
        self::assertSame([], $pending['limit_orders']);
    }
}
