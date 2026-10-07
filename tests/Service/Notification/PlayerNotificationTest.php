<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Entity\Notification;
use App\Entity\PriceAlert;
use App\Service\Notification\PlayerNotifier;
use App\Service\Notification\PriceAlertService;
use App\Twig\DurationExtension;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Account messages: queued until the transaction that raised them commits, written in one statement, pushed to
 * the account alone, and watched-name news fanned out to watchers at publish. Plus the alert rules and the copy
 * helpers the messages are written with.
 */
final class PlayerNotificationTest extends TestCase
{
    /** @return array{0: PlayerNotifier, 1: Connection&\PHPUnit\Framework\MockObject\MockObject, 2: \Redis&\PHPUnit\Framework\MockObject\MockObject} */
    private function notifier(): array
    {
        $connection = $this->createMock(Connection::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $redis = $this->getMockBuilder(\Redis::class)->onlyMethods(['multi', 'exec', 'publish'])->getMock();
        $redis->method('multi')->willReturnSelf();

        $notifier = new PlayerNotifier($entityManager, $redis, new NullLogger());
        $notifier->stampSimTime(12.25);

        return [$notifier, $connection, $redis];
    }

    public function testNothingIsWrittenUntilPublish(): void
    {
        [$notifier, $connection, $redis] = $this->notifier();
        $connection->expects($this->never())->method('executeStatement');
        $redis->expects($this->never())->method('publish');

        $notifier->queue(7, Notification::KIND_FILL, 'Bought 10 GULL at $12.00');

        $this->assertSame(1, $notifier->pendingCount());
    }

    public function testDiscardDropsAMessageFromARolledBackTick(): void
    {
        [$notifier, $connection, $redis] = $this->notifier();
        $connection->expects($this->never())->method('executeStatement');
        $redis->expects($this->never())->method('publish');

        $notifier->queue(7, Notification::KIND_OPTION_EXPIRY, '1 GULL 25.00 call exercised at expiry');
        $notifier->discard();

        $this->assertSame(0, $notifier->publish());
    }

    public function testPublishWritesOneStatementAndPushesEachMessageToItsAccount(): void
    {
        [$notifier, $connection, $redis] = $this->notifier();

        $connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('VALUES (?, ?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?, ?)'),
                $this->callback(static fn (array $params): bool => count($params) === 16 && $params[0] === 7 && $params[8] === 9 && $params[6] === 12.25)
            );

        $pushed = [];
        $redis->expects($this->exactly(2))->method('publish')
            ->willReturnCallback(function (string $channel, string $message) use (&$pushed): int {
                $this->assertSame(PlayerNotifier::CHANNEL, $channel);
                $pushed[] = json_decode($message, true);

                return 1;
            });

        $notifier->queue(7, Notification::KIND_FILL, 'Bought 10 GULL at $12.00', null, 'GULL', '/stock/GULL');
        $notifier->queue(9, Notification::KIND_MARGIN_CALL, 'Margin call');

        $this->assertSame(2, $notifier->publish());
        $this->assertSame(['7', '9'], array_column($pushed, 'uid'));
        $this->assertSame('Order filled', $pushed[0]['label']);
        $this->assertSame('badge-warn', $pushed[1]['tone']);
        $this->assertSame('1 Apr, Year 13', $pushed[0]['dateline']);
        $this->assertSame(0, $notifier->pendingCount());
    }

    public function testWatchedNewsGoesToEveryWatcherOfThatNameOnly(): void
    {
        [$notifier, $connection, $redis] = $this->notifier();

        $connection->expects($this->once())->method('fetchAllAssociative')->willReturn([
            ['user_id' => 3, 'ticker' => 'GULL'],
            ['user_id' => 4, 'ticker' => 'GULL'],
            ['user_id' => 5, 'ticker' => 'HERN'],
        ]);
        $redis->expects($this->exactly(2))->method('publish')->willReturn(1);

        $notifier->queueForWatchers('GULL', 'GULL: Earnings beat', 'Quarterly earnings beat consensus.', '/stock/GULL');

        $this->assertSame(2, $notifier->publish());
    }

    public function testAFailedWriteIsLoggedNotThrown(): void
    {
        [$notifier, $connection, $redis] = $this->notifier();
        $connection->expects($this->once())->method('executeStatement')->willThrowException(new \RuntimeException('database away'));
        $redis->expects($this->never())->method('publish');

        $notifier->queue(7, Notification::KIND_FILL, 'Bought 10 GULL at $12.00');

        $this->assertSame(0, $notifier->publish());
    }

    public function testFillLinesReadAsADeskWouldWriteThem(): void
    {
        $this->assertSame('Bought 1,500 GULL at $12.35', PlayerNotifier::fillLine('BUY', 1500, 'GULL', 12.345));
        $this->assertSame('Sold short 20 HERN at $4.00', PlayerNotifier::fillLine('SHORT', '20', 'HERN', 4.0));
        $this->assertSame('/bond/G02-001', PlayerNotifier::assetLink('BOND', 'G02-001'));
        $this->assertSame('/stock/GULL', PlayerNotifier::assetLink('STOCK', 'GULL'));
    }

    public function testAnAlertWaitsOnTheSideOfThePriceItWasSetOn(): void
    {
        $this->assertSame(PriceAlert::ABOVE, PriceAlert::directionFor(110.0, 100.0));
        $this->assertSame(PriceAlert::BELOW, PriceAlert::directionFor(90.0, 100.0));

        $this->assertTrue(PriceAlert::isReached(PriceAlert::ABOVE, 110.0, 110.0));
        $this->assertFalse(PriceAlert::isReached(PriceAlert::ABOVE, 110.0, 109.99));
        $this->assertTrue(PriceAlert::isReached(PriceAlert::BELOW, 90.0, 89.5));
        $this->assertFalse(PriceAlert::isReached(PriceAlert::BELOW, 90.0, 90.01));
    }

    public function testTheTickerDispatchesOnlyWhenABoundIsReached(): void
    {
        $bounds = ['above' => 110.0, 'below' => 90.0];

        $this->assertFalse(PriceAlertService::crossed($bounds, 100.0));
        $this->assertTrue(PriceAlertService::crossed($bounds, 110.0));
        $this->assertTrue(PriceAlertService::crossed($bounds, 85.0));
        $this->assertTrue(PriceAlertService::crossed(['above' => null, 'below' => 90.0], 90.0));
        $this->assertFalse(PriceAlertService::crossed(['above' => null, 'below' => 90.0], 1e9));
    }

    public function testDurationsAreSaidInTheirLargestWholeUnit(): void
    {
        $this->assertSame('3 days', DurationExtension::words(3 * 86400 + 4000));
        $this->assertSame('1 day', DurationExtension::words(86400));
        $this->assertSame('5 hours', DurationExtension::words(5 * 3600));
        $this->assertSame('20 minutes', DurationExtension::words(1200));
        $this->assertSame('a minute', DurationExtension::words(12));
    }
}
