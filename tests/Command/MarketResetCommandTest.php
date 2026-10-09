<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\MarketResetCommand;
use App\Data\Company\InitialMarket;
use App\Entity\Stock;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\OpeningBoardBuilder;
use App\Service\Market\Ticker\StockTickColumns;
use App\Service\Market\Bond\TreasuryAuctionService;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Math\MathUtility;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * What a reset actually sends to the database, as opposed to what it leaves on the entities.
 *
 * MarketResetCompletenessTest proves a reopened firm is, field for field, the firm a seed opens. That is a
 * statement about the entity. A reset reopens rows that already exist, and an UPDATE never carries the columns
 * the entity maps non-updatable (StockTickColumns), the opening price among them: a reset that left them to the
 * flush reopened every firm at the old market's last price on the new market's share count, LAKE at $483 on a
 * billion shares against a $4,942 fair value.
 */
#[AllowMockObjectsWithoutExpectations]
final class MarketResetCommandTest extends TestCase
{
    /** The old market's last price on every row, a figure no opening strikes. */
    private const OLD_MARKET_PRICE = '1.23000000';

    public function testAReopenedFirmReachesTheDatabaseAtItsOpeningPrice(): void
    {
        $existing = [];
        $id = 1;
        foreach (InitialMarket::STOCKS as $row) {
            $stock = (new Stock())->setTicker($row['ticker']);
            (new \ReflectionProperty(Stock::class, 'id'))->setValue($stock, $id++);
            $stock->setPrice(self::OLD_MARKET_PRICE);
            $existing[$row['ticker']] = $stock;
        }

        /** @var list<array{0: string, 1: array<int|string, mixed>}> $sent */
        $sent = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$sent): int {
                $sent[] = [$sql, $params];

                return 1;
            }
        );
        $connection->method('fetchOne')->willReturn(false);

        $stocks = $this->createMock(EntityRepository::class);
        $stocks->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?Stock => $existing[$criteria['ticker'] ?? ''] ?? null
        );
        $users = $this->createMock(EntityRepository::class);
        $users->method('findOneBy')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getRepository')->willReturnCallback(
            static fn (string $class): EntityRepository => $class === Stock::class ? $stocks : $users
        );

        mt_srand(20260929);
        $math = new MathUtility();
        $command = new MarketResetCommand(
            $entityManager,
            new class extends \Redis {
                public function flushAll(?bool $sync = null): bool
                {
                    return true;
                }
            },
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(TreasuryAuctionService::class),
            new OpeningBoardBuilder($math, new DebtEngine($math, CorporateMetrics::getInstance()), new MarketEngine($math), new AnchorStakeLedger())
        );

        $this->assertSame(Command::SUCCESS, (new CommandTester($command))->execute([]));

        // The per-tick columns go out as id-keyed rows, each laid out as the id then COLUMNS in order.
        $pricePosition = array_search('price', StockTickColumns::COLUMNS, true);
        $this->assertIsInt($pricePosition);
        $width = count(StockTickColumns::COLUMNS) + 1;
        $written = [];
        foreach ($sent as [$sql, $params]) {
            if (!str_starts_with($sql, 'UPDATE stocks t JOIN')) {
                continue;
            }
            foreach (array_chunk(array_values($params), $width) as $row) {
                $written[(int) $row[0]] = (string) $row[1 + $pricePosition];
            }
        }

        foreach ($existing as $ticker => $stock) {
            $this->assertArrayHasKey((int) $stock->getId(), $written, "{$ticker}'s opening price never reached the database.");
            $this->assertNotSame(self::OLD_MARKET_PRICE, $stock->getPrice(), "{$ticker} was not reopened.");
            $this->assertSame($stock->getPrice(), $written[(int) $stock->getId()], "{$ticker} reached the database at a price other than its opening.");
        }
    }
}
