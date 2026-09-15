<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Service\Market\BulkRowUpdate;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * The bulk writer replaces one UPDATE per row with one per chunk. The shape of the statement is the whole
 * point, so it is asserted literally: a derived row set joined on the key, every value a placeholder.
 */
class BulkRowUpdateTest extends TestCase
{
    public function testOneStatementJoinsTheRowsOnTheirKeyAndAssignsEveryColumn(): void
    {
        $statement = BulkRowUpdate::statement('bonds', ['price', 'clean_price'], [
            7 => ['101.5', '100.2'],
            9 => ['99.1', '98.7'],
        ]);

        $this->assertSame(
            'UPDATE bonds t JOIN (SELECT ? AS id, ? AS price, ? AS clean_price UNION ALL SELECT ?, ?, ?) v ON v.id = t.id '
            . 'SET t.price = v.price, t.clean_price = v.clean_price',
            $statement['sql']
        );
        $this->assertSame([7, '101.5', '100.2', 9, '99.1', '98.7'], $statement['params']);
    }

    public function testEveryValueIsAPlaceholderNeverInlined(): void
    {
        $statement = BulkRowUpdate::statement('t', ['a'], [1 => ["x'); DROP TABLE t; --"]]);

        $this->assertStringNotContainsString('DROP', $statement['sql']);
        $this->assertCount(substr_count($statement['sql'], '?'), $statement['params']);
    }

    public function testARowWithTheWrongWidthIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BulkRowUpdate::statement('t', ['a', 'b'], [1 => ['only one']]);
    }

    public function testNothingToWriteIsRefusedRatherThanSentAsAnEmptyStatement(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        BulkRowUpdate::statement('t', ['a'], []);
    }

    public function testALargeSetIsChunkedAndEveryRowIsSentExactlyOnce(): void
    {
        $rows = [];
        for ($id = 1; $id <= (BulkRowUpdate::ROWS_PER_STATEMENT * 2) + 5; $id++) {
            $rows[$id] = [(string) $id];
        }

        $sentIds = [];
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(3))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params) use (&$sentIds): int {
                // Each row contributes [id, value]; the ids sit at the even offsets.
                for ($i = 0; $i < count($params); $i += 2) {
                    $sentIds[] = $params[$i];
                }

                return 1;
            });

        $statements = BulkRowUpdate::apply($connection, 't', ['a'], $rows);

        $this->assertSame(3, $statements);
        $this->assertSame(array_keys($rows), $sentIds);
    }
}
