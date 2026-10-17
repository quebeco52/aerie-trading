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
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;

/**
 * An expiry moves cash on accounts the ticker has held for up to a day, so each one is read as the database holds it
 * and locked before the first move, and only before the first: a second read in the same pass would discard the first
 * move, which is still waiting to be written.
 */
final class OptionSettlementFreshReadTest extends TestCase
{
    public function testEachAccountIsReadFreshAndLockedOncePerPass(): void
    {
        $user = (new User())->setCashBalance('100000.00')->setMarginDebit('0.00');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 5);
        $stock = (new Stock())->setTicker('TST');

        $positions = [];
        foreach ([1, 2] as $id) {
            $contract = (new OptionContract())->setTicker('TST-' . $id)->setOptionType(OptionContract::TYPE_CALL)->setStrike('50')->setStock($stock);
            (new \ReflectionProperty(OptionContract::class, 'id'))->setValue($contract, $id);
            $positions[] = (new UserOption())->setUser($user)->setContract($contract)->setQuantity(1);
        }

        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('executeStatement')->willReturn(1);
        $connection->method('fetchAllAssociative')->willReturn(array_map(static fn (int $id): array => [
            'id' => $id, 'ticker' => 'TST-' . $id, 'option_type' => OptionContract::TYPE_CALL,
            'strike' => '50.00000000', 'underlying' => 'TST', 'settlement' => '60.00000000',
        ], [1, 2]));

        $query = $this->createStub(Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('getResult')->willReturn($positions);
        $holdings = $this->createStub(EntityRepository::class);
        $holdings->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('createQuery')->willReturn($query);
        $em->method('getRepository')->willReturn($holdings);
        $em->expects($this->once())->method('lock')->with($user, LockMode::PESSIMISTIC_WRITE);
        $em->expects($this->once())->method('refresh')->with($user);

        (new OptionSettlementEngine($em, new CashLedger()))->settle(1.0);

        $this->assertSame('90000.0000', (string) $user->getCashBalance(), 'Both exercises paid for: 2 x 100 shares at 50.');
    }
}
