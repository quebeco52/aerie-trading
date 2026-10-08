<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PruneHistoryCommand;
use App\Service\Market\Ticker\HistoryPruner;
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
    private const BATCH = HistoryPruner::DELETE_BATCH_IDS;

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
        $connection->method('transactional')->willReturnCallback(
            static function (\Closure $work) use ($connection, &$sent): mixed {
                $sent[] = ['BEGIN', []];

                return $work($connection);
            }
        );

        return $connection;
    }

    private function runPrune(Connection $connection): CommandTester
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $tester = new CommandTester(new PruneHistoryCommand(new HistoryPruner($em)));
        $this->assertSame(Command::SUCCESS, $tester->execute(['--years' => '5', '--per-year' => '52']));

        return $tester;
    }

    public function testExecutePrunesHistoryTablesInBatches(): void
    {
        $sent = [];
        // One batch per table, every row in it older than the cutoff.
        $tester = $this->runPrune($this->connection(1, static fn (int $lo): array => [self::BATCH, self::BATCH], $sent));

        $downsampled = $this->deletes($sent);
        $this->assertCount(3, $downsampled);

        // Retention is measured on the SIMULATION clock, not the wall clock: keeping five simulated years of
        // a market that stands at year twelve cuts at year seven, whatever the container's uptime has been.
        // Each statement is bounded by a primary-key range so it is never one transaction the size of the table.
        // Each series keeps its last row in every slice of its own time, so no series can be thinned away.
        foreach (array_map(null, array_keys(HistoryPruner::HISTORY_TABLES), $downsampled) as [$table, [$sql, $params]]) {
            $this->assertStringContainsString("DELETE h FROM $table h", $sql);
            $this->assertStringContainsString('h.sim_time IS NULL OR (h.sim_time < :cutoff AND h.id <> slices.kept_id)', $sql);
            $this->assertStringContainsString('h.id >= :lo AND h.id < :hi', $sql);
            $this->assertStringContainsString('GROUP BY ' . HistoryPruner::HISTORY_TABLES[$table] . ', FLOOR(sim_time * :perYear)', $sql);
            $this->assertStringContainsString('MAX(id) AS kept_id', $sql);
            $this->assertEqualsWithDelta(7.0, $params['cutoff'], 1e-9);
            $this->assertSame(1, $params['lo']);
            $this->assertSame(1 + self::BATCH, $params['hi']);
            $this->assertSame(52, $params['perYear']);
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
        $this->assertEqualsWithDelta(12.0 - HistoryPruner::EXPIRED_OPTION_YEARS_KEPT, $options[0][1]['cutoff'], 1e-9);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Downsampling Market History', $output);
        $this->assertStringContainsString('Cleared 150 redundant rows from stock_history', $output);
        $this->assertStringContainsString('Cleared 150 redundant rows from etf_history', $output);
        $this->assertStringContainsString('Cleared 150 redundant rows from bond_history', $output);
        $this->assertStringContainsString('Cleared 150 settled option contracts', $output);
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

        $deletes = $this->deletes($sent);

        // Three tables × three old batches; the empty region costs a probe and no DELETE.
        $this->assertCount(9, $deletes);
        $lows = array_map(static fn (array $call): int => $call[1]['lo'], array_slice($deletes, 0, 3));
        $this->assertSame([1, 1 + self::BATCH, 1 + 3 * self::BATCH], $lows);

        // Ids are assigned in insertion order, so the first populated batch with nothing older than the
        // cutoff ends the old region: the fifth batch is never read. Three tables, five batches probed each.
        $this->assertCount(15, $probed);
        $this->assertSame(1 + 4 * self::BATCH, max($probed));
    }

    /**
     * A bar table folds each thinned slice's bar into the row it keeps, before the delete and in the same
     * transaction: the week keeps its open, its extremes and its whole volume, and a retry cannot sum a volume
     * twice. A fund or bond series is a close alone, so it has nothing to fold.
     */
    public function testABarTableFoldsTheSliceIntoTheKeptRowInTheSameTransaction(): void
    {
        $sent = [];
        $this->runPrune($this->connection(1, static fn (int $lo): array => [self::BATCH, self::BATCH], $sent));

        $history = array_values(array_filter($sent, static fn (array $call): bool => !str_contains($call[0], 'option_contracts')));
        $statements = array_map(static fn (array $call): string => strtok(trim($call[0]), ' ') ?: '', $history);

        // stock_history: BEGIN, UPDATE, DELETE; etf_history and bond_history: BEGIN, DELETE.
        $this->assertSame(['BEGIN', 'UPDATE', 'DELETE', 'BEGIN', 'DELETE', 'BEGIN', 'DELETE'], $statements);

        [$fold, $params] = $history[1];
        $this->assertStringContainsString('UPDATE stock_history kept', $fold);
        $this->assertStringContainsString('HAVING COUNT(*) > 1', $fold);
        $this->assertStringContainsString('SUM(volume) AS volume', $fold);
        $this->assertStringContainsString('MAX(COALESCE(high_price, price))', $fold);
        $this->assertStringContainsString('MIN(COALESCE(low_price, price))', $fold);
        $this->assertStringContainsString('kept.open_price = COALESCE(opener.open_price, opener.price)', $fold);
        $this->assertSame(52, $params['perYear']);
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $sent
     * @return list<array{0: string, 1: array<string, mixed>}> The history DELETEs, in the order sent.
     */
    private function deletes(array $sent): array
    {
        return array_values(array_filter($sent, static fn (array $call): bool => str_starts_with(trim($call[0]), 'DELETE h FROM')));
    }

    /**
     * The chart reads thinned history at a row a week, so it has to know where thinning stops: at the cutoff of
     * the pass the ticker ran on the last year-end the series has crossed, not five years behind its newest row.
     */
    public function testThinnedHistoryEndsAtTheLastPassesCutoff(): void
    {
        $this->assertEqualsWithDelta(17.0, HistoryPruner::thinnedBefore(22.758), 1e-9);
        $this->assertEqualsWithDelta(18.0, HistoryPruner::thinnedBefore(23.0), 1e-9);
        $this->assertLessThan(0.0, HistoryPruner::thinnedBefore(4.9), 'Nothing is thinned before the first pass has a cutoff.');
    }

    public function testEmptyTableIsLeftAlone(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql) => str_contains($sql, 'simulation_clock') ? '12.0' : false
        );
        $connection->method('fetchNumeric')->willReturn([null, null]);
        $connection->expects($this->never())->method('fetchAssociative');
        // Every delete is addressed by a primary-key range, so a table with no rows has no range to walk and
        // nothing reaches the database at all — not even the settled-contract sweep.
        $connection->expects($this->never())->method('executeStatement');

        $this->runPrune($connection);
    }

    /**
     * The settled-contract sweep walks the whole key range and never stops early.
     *
     * A listing pass opens several serials at once and the furthest of them expires half a year beyond the
     * nearest, so expiry is only broadly monotonic in id. The history walk may stop at the first batch with
     * nothing old in it; this one may not, or it would strand rows behind a batch that happened to hold only
     * contracts still live.
     */
    public function testSettledContractsAreDeletedInBatchesAcrossTheWholeKeyRange(): void
    {
        $sent = [];
        // Three batches of ids, and a history probe that ends the history walk on its very first batch so
        // only the option sweep is left to account for.
        $this->runPrune($this->connection(3, static fn (int $lo): array => [self::BATCH, 0], $sent));

        $options = array_values(array_filter($sent, static fn (array $call): bool => str_contains($call[0], 'option_contracts')));
        $this->assertCount(3, $options);

        foreach ($options as $index => [$sql, $params]) {
            $this->assertStringContainsString('id >= :lo AND id < :hi', $sql);
            $this->assertStringContainsString('NOT EXISTS', $sql);
            $this->assertSame('EXPIRED', $params['status']);
            $this->assertSame(1 + $index * self::BATCH, $params['lo']);
            $this->assertSame(1 + ($index + 1) * self::BATCH, $params['hi']);
        }
    }
}
