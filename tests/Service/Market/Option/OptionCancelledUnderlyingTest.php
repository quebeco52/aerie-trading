<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Option;

use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Entity\User;
use App\Entity\UserOption;
use App\Service\Market\Option\OptionSettlementEngine;
use App\Service\User\CashLedger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;

/**
 * Options on shares a plan of reorganization cancels are settled in cash at an underlying of zero.
 *
 * The deliverable no longer exists, so the OCC adjusts the contracts to what the shares became: nothing. A
 * call is worthless and a put pays its full strike. Left alone, they would have expired later against the
 * NEW equity's price and physically delivered shares of a company their writers never referenced.
 */
final class OptionCancelledUnderlyingTest extends TestCase
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    private array $sent = [];
    /** @var list<object> */
    private array $removed = [];

    public function testCallsExpireWorthlessAndPutsPayTheirStrikeInCash(): void
    {
        $put = $this->contract(1, OptionContract::TYPE_PUT, 40.0);
        $call = $this->contract(2, OptionContract::TYPE_CALL, 50.0);

        $longPut = $this->holder('900.00');
        $shortPut = $this->holder('10000.00');
        $longCall = $this->holder('500.00');
        $positions = [
            $this->position($longPut, $put, 2),
            $this->position($shortPut, $put, -1),
            $this->position($longCall, $call, 3),
        ];

        $engine = $this->engine(
            [
                ['id' => 1, 'ticker' => 'OLD-P40', 'option_type' => OptionContract::TYPE_PUT, 'strike' => '40.00000000'],
                ['id' => 2, 'ticker' => 'OLD-C50', 'option_type' => OptionContract::TYPE_CALL, 'strike' => '50.00000000'],
            ],
            $positions
        );

        $this->assertSame(2, $engine->settleCancelledUnderlying(new Stock()));

        $this->assertEqualsWithDelta(8900.0, (float) $longPut->getCashBalance(), 1e-9, 'two long puts are paid 2 x 100 x $40');
        $this->assertEqualsWithDelta(6000.0, (float) $shortPut->getCashBalance(), 1e-9, 'the writer pays the strike on the put it sold');
        $this->assertEqualsWithDelta(500.0, (float) $longCall->getCashBalance(), 1e-9, 'a call on nothing is worth nothing');
        $this->assertCount(3, $this->removed, 'every position is closed');

        $this->assertCount(1, $this->sent);
        [$sql, $params] = $this->sent[0];
        $this->assertStringContainsString('UPDATE option_contracts', $sql);
        $this->assertContains('40.00000000', $params, 'the put is stamped at its strike');
        $this->assertContains('0.00000000', $params, 'the call is stamped at zero');
    }

    public function testAnUnderlyingWithNoListedContractsSettlesNothing(): void
    {
        $engine = $this->engine([], []);

        $this->assertSame(0, $engine->settleCancelledUnderlying(new Stock()));
        $this->assertSame([], $this->sent);
    }

    /**
     * @param array<int, array<string, string|int>> $rows
     * @param list<UserOption> $positions
     */
    private function engine(array $rows, array $positions): OptionSettlementEngine
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []): int {
                $this->sent[] = [$sql, $params];

                return 1;
            }
        );

        $query = $this->createStub(Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('getResult')->willReturn($positions);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('createQuery')->willReturn($query);
        $em->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removed[] = $entity;
        });

        return new OptionSettlementEngine($em, new CashLedger());
    }

    private function contract(int $id, string $type, float $strike): OptionContract
    {
        $contract = (new OptionContract())->setOptionType($type)->setStrike((string) $strike);
        (new \ReflectionProperty(OptionContract::class, 'id'))->setValue($contract, $id);

        return $contract;
    }

    private function holder(string $cash): User
    {
        $user = new User();
        $user->setCashBalance($cash);

        return $user;
    }

    private function position(User $user, OptionContract $contract, int $quantity): UserOption
    {
        return (new UserOption())->setUser($user)->setContract($contract)->setQuantity($quantity);
    }
}
