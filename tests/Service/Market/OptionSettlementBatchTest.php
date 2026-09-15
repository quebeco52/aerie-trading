<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\OptionContract;
use App\Service\Market\OptionSettlementEngine;
use App\Service\User\CashLedger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;

/**
 * What an expiry costs the database.
 *
 * Expiries arrive in a herd — one serial rolls off for the whole market at once — so the thing that matters
 * is that the cost of settling does not scale with the size of the herd. A contract each meant a position
 * lookup and an UPDATE per contract, several hundred of each inside one twenty-millisecond tick, and the
 * ticker logged it as a hundred-millisecond spike every time a serial rolled.
 */
class OptionSettlementBatchTest extends TestCase
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    private array $sent = [];

    /** @param array<int, array<string, mixed>> $expiring */
    private function engine(array $expiring): OptionSettlementEngine
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($expiring);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []): int {
                $this->sent[] = [$sql, $params];

                return 1;
            }
        );

        // Nobody holds any of it, which is the ordinary case: a listed chain is mostly contracts no account
        // has ever traded, and settling those must never reach the ORM at all.
        $query = $this->createStub(Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('getResult')->willReturn([]);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('createQuery')->willReturn($query);

        return new OptionSettlementEngine($em, new CashLedger());
    }

    /** @return array<string, mixed> */
    private function row(int $id, string $type, float $strike, float $spot): array
    {
        return [
            'id' => $id,
            'ticker' => 'TST-' . $id,
            'option_type' => $type,
            'strike' => number_format($strike, 8, '.', ''),
            'underlying' => 'TST',
            'settlement' => number_format($spot, 8, '.', ''),
        ];
    }

    public function testAWholeExpiringSerialSettlesInAHandfulOfStatements(): void
    {
        $count = OptionSettlementEngine::EXPIRY_ROWS_PER_STATEMENT * 2 + 1;
        $expiring = [];

        for ($id = 1; $id <= $count; $id++) {
            $expiring[] = $this->row($id, OptionContract::TYPE_CALL, 90.0 + $id, 100.0);
        }

        $engine = $this->engine($expiring);
        $settled = $engine->settle(1.0);

        $this->assertCount($count, $settled);

        // Three statements for five hundred and one contracts, and they are the ONLY thing a settlement
        // with nobody holding anything sends.
        $this->assertCount(3, $this->sent);

        foreach ($this->sent as $statement) {
            $this->assertStringStartsWith('UPDATE option_contracts SET status = ?', $statement[0]);
        }
    }

    public function testTheBatchIsAddressedByKeyAndCarriesTheFlatColumnsOnlyOnce(): void
    {
        $expiring = [];
        for ($id = 1; $id <= 10; $id++) {
            $expiring[] = $this->row($id, OptionContract::TYPE_CALL, 90.0, 100.0);
        }

        $this->engine($expiring)->settle(1.0);

        [$sql, $params] = $this->sent[0];

        // The expired status, the flat greeks and the timestamp are set once for the whole batch; only the
        // settlement price varies, and the rows are sought by primary key rather than joined to.
        $this->assertStringContainsString('price = CASE id WHEN ? THEN ?', $sql);
        $this->assertStringContainsString('WHERE id IN (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $sql);
        $this->assertSame(1, substr_count($sql, 'status = ?'));
        $this->assertCount(6 + (2 * 10) + 10, $params);
        $this->assertSame(OptionContract::STATUS_EXPIRED, $params[0]);
        $this->assertSame(range(1, 10), array_slice($params, -10));
    }

    public function testTheExpiryStampCarriesTheIntrinsicValueAndFlatGreeks(): void
    {
        // In the money by ten: a call struck at 90 against a settlement print of 100.
        $engine = $this->engine([$this->row(7, OptionContract::TYPE_CALL, 90.0, 100.0)]);
        $settled = $engine->settle(1.0);

        $this->assertTrue($settled[0]['exercised']);
        $this->assertSame('TST', $settled[0]['underlying']);
        $this->assertSame(0, $settled[0]['positions']);

        $this->assertCount(1, $this->sent);
        [$status, $delta, $gamma, $vega, $theta, , $id, $price] = $this->sent[0][1];

        $this->assertSame(7, $id);
        $this->assertSame(OptionContract::STATUS_EXPIRED, $status);
        $this->assertStringContainsString('open_interest = 0', $this->sent[0][0]);
        $this->assertEqualsWithDelta(10.0, (float) $price, 1e-8);
        $this->assertSame(0.0, (float) $delta + (float) $gamma + (float) $vega + (float) $theta);
    }

    public function testAPutOutOfTheMoneyExpiresWorthlessAndIsNotExercised(): void
    {
        $engine = $this->engine([$this->row(9, OptionContract::TYPE_PUT, 90.0, 100.0)]);
        $settled = $engine->settle(1.0);

        $this->assertFalse($settled[0]['exercised']);
        $this->assertEqualsWithDelta(0.0, (float) $this->sent[0][1][7], 1e-12);
    }

    public function testNothingExpiringCostsNothing(): void
    {
        $engine = $this->engine([]);

        $this->assertSame([], $engine->settle(1.0));
        $this->assertSame([], $this->sent);
    }
}
