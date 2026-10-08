<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Ticker;

use App\Service\Market\Ticker\StockBarBook;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * A history row carries the bar its close belongs to: the extremes and volume of every tick inside it, then a
 * fresh bar opens.
 */
final class StockBarBookTest extends TestCase
{
    /** @var list<array<int, mixed>> Parameters of each INSERT, one row per eight. */
    private array $written = [];

    private function connection(): Connection
    {
        $conn = $this->createStub(Connection::class);
        $conn->method('executeStatement')->willReturnCallback(function (string $sql, array $params): int {
            self::assertStringStartsWith('INSERT INTO stock_history', $sql);
            $this->written[] = $params;

            return 1;
        });

        return $conn;
    }

    public function testTheRowCarriesTheExtremesAndVolumeOfEveryTickInTheBar(): void
    {
        $book = new StockBarBook();
        foreach ([[100.0, 10.0], [92.0, 20.0], [105.0, 30.0], [101.0, 40.0]] as [$price, $volume]) {
            $book->accumulate([['ticker' => 'AAA', 'price' => $price, 'volume' => $volume]]);
        }

        $book->write($this->connection(), [['stock_id' => 7, 'ticker' => 'AAA', 'price' => 101.0]], 3.25);

        // stock_id, close, open, high, low, volume, recorded_at, sim_time
        [$id, $close, $open, $high, $low, $volume, , $simTime] = $this->written[0];
        self::assertSame([7, 101.0, 100.0, 105.0, 92.0, 100, 3.25], [$id, $close, $open, $high, $low, $volume, $simTime]);
    }

    public function testTheNextBarOpensAtTheNextTicksPrice(): void
    {
        $book = new StockBarBook();
        $conn = $this->connection();

        $book->accumulate([['ticker' => 'AAA', 'price' => 80.0, 'volume' => 5.0]]);
        $book->write($conn, [['stock_id' => 7, 'ticker' => 'AAA', 'price' => 80.0]], 1.0);

        $book->accumulate([['ticker' => 'AAA', 'price' => 90.0, 'volume' => 2.0]]);
        $book->write($conn, [['stock_id' => 7, 'ticker' => 'AAA', 'price' => 90.0]], 2.0);

        self::assertSame(90.0, $this->written[1][2], 'open');
        self::assertSame(90.0, $this->written[1][4], 'low');
        self::assertSame(2, $this->written[1][5], 'volume');
    }

    public function testAFailedNameAndANameFirstSeenAtTheCloseGetAOneTickBar(): void
    {
        $book = new StockBarBook();
        $book->accumulate([['ticker' => 'DEAD', 'price' => 0.01, 'volume' => 9.0, 'is_bankrupt' => true]]);

        $book->write($this->connection(), [['stock_id' => 3, 'ticker' => 'DEAD', 'price' => 0.01]], 1.0);

        self::assertSame([0.01, 0.01, 0.01, 0], array_slice($this->written[0], 2, 4));
    }

    public function testNoClosingRowsWriteNothingButStillOpenANewBar(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects(self::never())->method('executeStatement');

        $book = new StockBarBook();
        $book->accumulate([['ticker' => 'AAA', 'price' => 50.0, 'volume' => 1.0]]);
        $book->write($conn, [], 1.0);
        $book->accumulate([['ticker' => 'AAA', 'price' => 60.0, 'volume' => 1.0]]);
        $book->write($this->connection(), [['stock_id' => 1, 'ticker' => 'AAA', 'price' => 60.0]], 2.0);

        self::assertSame(60.0, $this->written[0][2], 'open');
    }
}
