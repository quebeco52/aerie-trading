<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PruneHistoryCommand;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class PruneHistoryCommandTest extends TestCase
{
    private const BATCH = PruneHistoryCommand::DELETE_BATCH_IDS;

    /**
     * A connection whose history tables span ids 1..($batches × batch) and answer the batch probe through
     * $probe(lo) => [total, old]. The clock stands at year 12; the macro-report lookup is answered empty.
     *
     * @param callable(int): array{0: int, 1: int} $probe
     * @param list<array{0: string, 1: array<string, mixed>}> $sent
     */
    private function connection(int $batches, callable $probe, array &$sent): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);

        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql) => str_contains($sql, 'simulation_clock') ? '12.0' : false
        );
        $connection->method('fetchNumeric')->willReturn([1, $batches * self::BATCH]);
        $connection->method('fetchAssociative')->willReturnCallback(
            static function (string $sql, array $params) use ($probe): array {
                [$total, $old] = $probe((int) $params['lo']);

                return ['total' => $total, 'old' => $old];
            }
        );
        $connection->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$sent): int {
                $sent[] = [$sql, $params];

                return 150;
            }
        );

        return $connection;
    }

    private function runPrune(Connection $connection): CommandTester
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $tester = new CommandTester(new PruneHistoryCommand($em));
        $this->assertSame(Command::SUCCESS, $tester->execute(['--years' => '5', '--ratio' => '100']));

        return $tester;
    }

    public function testExecutePrunesHistoryTablesInBatches(): void
    {
        $sent = [];
        // One batch per table, every row in it older than the cutoff.
        $tester = $this->runPrune($this->connection(1, static fn (int $lo): array => [self::BATCH, self::BATCH], $sent));

        $downsampled = array_values(array_filter($sent, static fn (array $call): bool => !str_contains($call[0], 'option_contracts')));
        $this->assertCount(3, $downsampled);

        // Retention is measured on the SIMULATION clock, not the wall clock: keeping five simulated years of
        // a market that stands at year twelve cuts at year seven, whatever the container's uptime has been.
        // Each statement is bounded by a primary-key range so it is never one transaction the size of the table.
        foreach ($downsampled as [$sql, $params]) {
            $this->assertStringContainsString('sim_time IS NULL OR sim_time < :cutoff', $sql);
            $this->assertStringContainsString('id >= :lo AND id < :hi', $sql);
            $this->assertStringContainsString('id % :ratio != 0', $sql);
            $this->assertEqualsWithDelta(7.0, $params['cutoff'], 1e-9);
            $this->assertSame(1, $params['lo']);
            $this->assertSame(1 + self::BATCH, $params['hi']);
            $this->assertSame(100, $params['ratio']);
        }

        // A settled contract is not a chart, so there is nothing to downsample and no ratio to keep: it is
        // deleted, on its own shorter retention, and only when no position still points at it — the row
        // cascades to user_options, so a contract that kept one would take the position with it.
        $options = array_values(array_filter($sent, static fn (array $call): bool => str_contains($call[0], 'option_contracts')));
        $this->assertCount(1, $options);
        $this->assertStringContainsString('DELETE FROM option_contracts', $options[0][0]);
        $this->assertStringContainsString('NOT EXISTS', $options[0][0]);
        $this->assertStringContainsString('user_options.option_contract_id', $options[0][0]);
        $this->assertSame('EXPIRED', $options[0][1]['status']);
        $this->assertEqualsWithDelta(12.0 - PruneHistoryCommand::EXPIRED_OPTION_YEARS_KEPT, $options[0][1]['cutoff'], 1e-9);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Downsampling Market History', $output);
        $this->assertStringContainsString('Cleared 150 redundant rows from stock_history', $output);
        $this->assertStringContainsString('Cleared 150 redundant rows from etf_history', $output);
        $this->assertStringContainsString('Cleared 150 redundant rows from bond_history', $output);
        $this->assertStringContainsString('Cleared 150 settled contracts from option_contracts', $output);
    }

    public function testWalkSkipsThinnedRegionsAndStopsAtTheFirstBatchNewerThanTheCutoff(): void
    {
        $sent = [];
        // Ids 1..5 batches: two old batches, one empty (thinned by an earlier run), one old again, then a
        // batch of rows all newer than the cutoff, and a fifth that must never be probed.
        $probed = [];
        $probe = static function (int $lo) use (&$probed): array {
            $probed[] = $lo;
            $batch = intdiv($lo - 1, self::BATCH);

            return match ($batch) {
                0, 1, 3 => [self::BATCH, self::BATCH],
                2 => [0, 0],
                default => [self::BATCH, 0],
            };
        };

        $this->runPrune($this->connection(5, $probe, $sent));

        $deletes = array_values(array_filter($sent, static fn (array $call): bool => !str_contains($call[0], 'option_contracts')));

        // Three tables × three old batches; the empty region costs a probe and no DELETE.
        $this->assertCount(9, $deletes);
        $lows = array_map(static fn (array $call): int => $call[1]['lo'], array_slice($deletes, 0, 3));
        $this->assertSame([1, 1 + self::BATCH, 1 + 3 * self::BATCH], $lows);

        // Ids are assigned in insertion order, so the first populated batch with nothing older than the
        // cutoff ends the old region: the fifth batch is never read. Three tables, five batches probed each.
        $this->assertCount(15, $probed);
        $this->assertSame(1 + 4 * self::BATCH, max($probed));
    }

    public function testEmptyTableIsLeftAlone(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql) => str_contains($sql, 'simulation_clock') ? '12.0' : false
        );
        $connection->method('fetchNumeric')->willReturn([null, null]);
        $connection->expects($this->never())->method('fetchAssociative');
        // Only the option-contract delete reaches the database.
        $connection->expects($this->once())->method('executeStatement')->willReturn(0);

        $this->runPrune($connection);
    }
}
