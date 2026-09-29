<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Repository\NewestPerStockTrait;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The per-company limit is one windowed SQL statement built from the entity mapping, so what is pinned
 * here is the statement each mapped table gets and the parameters bound to it. The metadata is the real
 * attribute mapping; the connection is a mock, so nothing is opened.
 */
class NewestPerStockTraitTest extends TestCase
{
    /**
     * @template T of object
     * @param  class-string<T>  $class
     * @return ClassMetadata<T>
     */
    private function metadata(string $class): ClassMetadata
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../../src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        // The app's naming strategy (config/packages/doctrine.yaml), which is what maps recordedAt to recorded_at.
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => '127.0.0.1',
            'dbname' => 'unused',
            'user' => 'unused',
            'password' => 'unused',
            'serverVersion' => '8.0.36',
        ], $config);

        return (new EntityManager($connection, $config))->getClassMetadata($class);
    }

    /** @param ClassMetadata<object> $metadata */
    private function repository(ClassMetadata $metadata, Connection $connection): object
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new class ($metadata, $em) {
            use NewestPerStockTrait;

            /** @param ClassMetadata<object> $metadata */
            public function __construct(private readonly ClassMetadata $metadata, private readonly EntityManagerInterface $em) {}

            /** @return ClassMetadata<object> */
            protected function getClassMetadata(): ClassMetadata
            {
                return $this->metadata;
            }

            protected function getEntityManager(): EntityManagerInterface
            {
                return $this->em;
            }

            /**
             * @param  list<Stock> $stocks
             * @return list<int>
             */
            public function ids(array $stocks, int $perStock): array
            {
                return $this->newestIdsPerStock($stocks, $perStock);
            }
        };
    }

    private function stock(int $id): Stock
    {
        $stock = new Stock();
        (new \ReflectionProperty(Stock::class, 'id'))->setValue($stock, $id);

        return $stock;
    }

    /** @return array<string, array{0: string, 1: class-string}> */
    public static function mappedTables(): array
    {
        return [
            'filings' => ['corporate_report', CorporateReport::class],
            'events' => ['stock_events', StockEvent::class],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('mappedTables')]
    public function testRanksEachCompanysRowsNewestFirstAndKeepsTheTopN(string $table, string $class): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchFirstColumn')
            ->with(
                'SELECT id FROM (SELECT id, ROW_NUMBER() OVER (PARTITION BY stock_id ORDER BY recorded_at DESC, id DESC) AS rn'
                . ' FROM ' . $table . ' WHERE stock_id IN (:stocks)) ranked WHERE rn <= :perStock',
                ['stocks' => [3, 9], 'perStock' => 5],
                ['stocks' => ArrayParameterType::INTEGER],
            )
            ->willReturn([]);

        $this->repository($this->metadata($class), $connection)->ids([$this->stock(3), $this->stock(9)], 5);
    }

    public function testReturnsTheIdsAsIntegers(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn(['12', '7']);

        $ids = $this->repository($this->metadata(CorporateReport::class), $connection)->ids([$this->stock(3)], 5);

        $this->assertSame([12, 7], $ids);
    }
}
