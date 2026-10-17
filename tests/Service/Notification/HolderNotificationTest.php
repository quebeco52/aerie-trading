<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Entity\Bond;
use App\Entity\Notification;
use App\Entity\Stock;
use App\Service\Corporate\CorporateLedgerService;
use App\Service\Market\Bond\BondLedgerService;
use App\Service\Notification\PlayerNotifier;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An issuer's action that changes a held position tells each holder what became of it: the holders are read at the
 * action, before the rows that held them are deleted, and each gets the message for the size of what it held.
 */
final class HolderNotificationTest extends TestCase
{
    /** @return array{0: EntityManagerInterface, 1: Connection&\PHPUnit\Framework\MockObject\Stub} */
    private function database(array $holders): array
    {
        $connection = $this->createStub(Connection::class);
        // Holders for the position queries; nothing for the rest (the split's event-history restatement).
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static fn(string $sql): array => preg_match('/FROM (user_stocks|user_bonds|user_etfs)/', $sql) === 1 ? $holders : [],
        );
        $connection->method('executeStatement')->willReturn(1);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        return [$entityManager, $connection];
    }

    private function recorder(EntityManagerInterface $entityManager): RecordingNotifier
    {
        return new RecordingNotifier($entityManager, $this->createStub(\Redis::class), new NullLogger());
    }

    public function testEachHolderIsToldOnceInTheirOwnTerms(): void
    {
        [$entityManager] = $this->database([]);
        $notifier = $this->recorder($entityManager);

        $notifier->queueForHolders(
            [['user_id' => '7', 'quantity' => '120'], ['user_id' => 9, 'quantity' => -40]],
            'GULL',
            'GULL was liquidated',
            static fn(float $quantity): string => $quantity > 0.0 ? 'long ' . $quantity : 'short ' . abs($quantity),
            '/stock/GULL',
        );

        $this->assertSame([7, 9], array_column($notifier->queued, 'user'));
        $this->assertSame([Notification::KIND_CORPORATE_ACTION, Notification::KIND_CORPORATE_ACTION], array_column($notifier->queued, 'kind'));
        $this->assertSame(['long 120', 'short 40'], array_column($notifier->queued, 'body'));
        $this->assertSame('Your holdings', Notification::presentation(Notification::KIND_CORPORATE_ACTION)['label']);
    }

    public function testABondholderIsToldWhatARedemptionAndADefaultPaid(): void
    {
        [$entityManager] = $this->database([['user_id' => 7, 'quantity' => 30]]);
        $notifier = $this->recorder($entityManager);
        $ledger = new BondLedgerService($entityManager, $notifier);
        $bond = (new Bond())->setTicker('EXQ-30')->setFaceValue('1000.00');

        $ledger->processRedemption($bond, 12.0, new \DateTime('2021-01-01'));
        $ledger->processDefaultSettlement($bond, 420.0, 12.0, new \DateTime('2021-01-01'));

        $this->assertSame(['EXQ-30 matured', 'EXQ-30 defaulted and was settled'], array_column($notifier->queued, 'title'));
        $this->assertSame('Your 30 bonds were repaid at face, $30,000.00 in all.', $notifier->queued[0]['body']);
        $this->assertSame('Your 30 bonds were settled at 42.0% of face, $12,600.00 in all.', $notifier->queued[1]['body']);
        $this->assertSame('/bond/EXQ-30', $notifier->queued[0]['link']);
    }

    /** A closed issue writes each holder's settlement sale at what it paid, before the resting sells it counts are cancelled. */
    public function testAClosedIssueSettlesEachHoldingBeforeItsOrdersAreCancelled(): void
    {
        [$entityManager, $connection] = $this->database([]);
        $statements = [];
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = []) use (&$statements): int {
            $statements[] = [$sql, $params];

            return 1;
        });
        $bond = (new Bond())->setTicker('EXQ-30')->setFaceValue('1000.00');

        (new BondLedgerService($entityManager))->processDefaultSettlement($bond, 420.0, 12.0, new \DateTime('2021-01-01'));

        $settle = array_key_first(array_filter($statements, static fn(array $s): bool => str_contains($s[0], 'INSERT INTO trade_orders')));
        $cancel = array_key_first(array_filter($statements, static fn(array $s): bool => str_contains($s[0], "SET status = 'CANCELLED'")));
        $this->assertNotNull($settle);
        $this->assertLessThan($cancel, $settle);
        $this->assertSame('420.0000', $statements[$settle][1]['price']);
        $this->assertSame(\App\Entity\TradeOrder::ORIGIN_SETTLEMENT, $statements[$settle][1]['origin']);
    }

    public function testASplitTellsEachHolderTheirNewPosition(): void
    {
        [$entityManager] = $this->database([['user_id' => 7, 'quantity' => 400], ['user_id' => 9, 'quantity' => -80]]);
        $notifier = $this->recorder($entityManager);
        $stock = new Stock();
        $stock->setTicker('PEAC');

        (new CorporateLedgerService($entityManager, $notifier))->processStockSplit($stock, 4.0, false);

        $this->assertSame('PEAC split its shares 4-for-1', $notifier->queued[0]['title']);
        $this->assertSame('You now hold 400 shares.', $notifier->queued[0]['body']);
        $this->assertSame('Your short position is now 80 shares.', $notifier->queued[1]['body']);
    }
}

/** A notifier that records what it is asked to queue. */
final class RecordingNotifier extends PlayerNotifier
{
    /** @var list<array{user: int, kind: string, title: string, body: ?string, ticker: ?string, link: ?string}> */
    public array $queued = [];

    public function queue(int $userId, string $kind, string $title, ?string $body = null, ?string $ticker = null, ?string $link = null): void
    {
        $this->queued[] = ['user' => $userId, 'kind' => $kind, 'title' => $title, 'body' => $body, 'ticker' => $ticker, 'link' => $link];
    }
}
