<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\Stock;
use App\Service\Market\StockTickColumns;
use App\Tests\Support\StockBuilder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The six per-tick stock columns are mapped non-updatable, so the ONLY way they reach the database is this
 * writer. If it drifts from the mapping — a column added to one and not the other — the price simply stops
 * being persisted, silently. Both directions of that contract are pinned here.
 */
class StockTickColumnsTest extends TestCase
{
    private function persisted(Stock $stock, int $id): Stock
    {
        $property = new \ReflectionProperty(Stock::class, 'id');
        $property->setValue($stock, $id);

        return $stock;
    }

    public function testEveryNonUpdatableStockColumnIsWrittenHereAndNothingElseIs(): void
    {
        $nonUpdatable = [];
        $namer = static function (string $property): string {
            return strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $property));
        };

        foreach ((new \ReflectionClass(Stock::class))->getProperties() as $property) {
            foreach ($property->getAttributes(\Doctrine\ORM\Mapping\Column::class) as $attribute) {
                $column = $attribute->newInstance();
                if ($column->updatable === false) {
                    $nonUpdatable[] = $column->name ?? $namer($property->getName());
                }
            }
        }

        sort($nonUpdatable);
        $written = StockTickColumns::COLUMNS;
        sort($written);

        $this->assertSame($nonUpdatable, $written);
    }

    public function testRowsCarryTheLiveValuesKeyedByIdAndSkipUnpersistedNames(): void
    {
        $first = $this->persisted(StockBuilder::create('AAA')->withPrice(12.5)->withCurrentVolatility(0.31)->build(), 7);
        $first->setImpactVarianceEma(0.002)->setPriceMomentumTrend(-0.05)->setDynamicCreditSpread('0.0125')
            ->setCorporateFlowBacklog(1500.0)->setCeoTenureYears(4.25);
        $unpersisted = StockBuilder::create('NEW')->build();

        $rows = StockTickColumns::rows([$first, $unpersisted]);

        $this->assertSame([7], array_keys($rows));
        $this->assertSame(['12.50000000', '0.3100', 0.002, -0.05, '0.0125', 1500.0, 4.25], $rows[7]);
    }

    public function testTheWriteGoesOutAsOneBulkStatementForTheWorkingSet(): void
    {
        $sent = [];
        $connection = $this->createStub(Connection::class);
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$sent): int {
                $sent[] = [$sql, $params];

                return 1;
            }
        );

        $stocks = [];
        for ($id = 1; $id <= 3; $id++) {
            $stocks[] = $this->persisted(StockBuilder::create('S' . $id)->build(), $id);
        }

        $this->assertSame(1, StockTickColumns::write($connection, $stocks));
        $this->assertCount(1, $sent);
        $this->assertStringStartsWith('UPDATE stocks t JOIN (SELECT ? AS id, ? AS price, ? AS current_volatility', $sent[0][0]);
        $this->assertCount(3 * (1 + count(StockTickColumns::COLUMNS)), $sent[0][1]);
    }

    public function testNothingPersistedMeansNothingSent(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('executeStatement');

        $this->assertSame(0, StockTickColumns::write($connection, [StockBuilder::create('NEW')->build()]));
    }
}
