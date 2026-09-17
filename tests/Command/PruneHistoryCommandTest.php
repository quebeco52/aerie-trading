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
    public function testExecutePrunesHistoryTablesSuccessfully(): void
    {
        $emMock = $this->createMock(EntityManagerInterface::class);
        $connectionMock = $this->createMock(Connection::class);
        $emMock->method('getConnection')->willReturn($connectionMock);

        // The clock says the simulation stands at year 12. The macro-report lookup shares this method and
        // is answered empty, so the only statements counted below are the three history tables.
        $connectionMock->method('fetchOne')->willReturnCallback(
            static fn (string $sql) => str_contains($sql, 'simulation_clock') ? '12.0' : false
        );

        /** @var list<array{0: string, 1: array<string, mixed>}> $sent */
        $sent = [];

        // One executeStatement per history table — stock_history, etf_history, bond_history — plus the
        // settled option contracts, which are deleted outright rather than downsampled.
        $connectionMock->expects($this->exactly(4))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$sent): int {
                $sent[] = [$sql, $params];

                return 150;
            });

        $command = new PruneHistoryCommand($emMock);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--years' => '5',
            '--ratio' => '100'
        ]);

        // Retention is measured on the SIMULATION clock, not the wall clock: keeping five simulated years of
        // a market that stands at year twelve cuts at year seven, whatever the container's uptime has been.
        $downsampled = array_values(array_filter(
            $sent,
            static fn (array $call): bool => !str_contains($call[0], 'option_contracts')
        ));

        $this->assertCount(3, $downsampled);

        foreach ($downsampled as [$sql, $params]) {
            $this->assertStringContainsString('sim_time IS NULL OR sim_time < :cutoff', $sql);
            $this->assertEqualsWithDelta(7.0, $params['cutoff'], 1e-9);
        }

        // A settled contract is not a chart, so there is nothing to downsample and no ratio to keep: it is
        // deleted, on its own shorter retention, and only when no position still points at it — the row
        // cascades to user_options, so a contract that kept one would take the position with it.
        $options = array_values(array_filter(
            $sent,
            static fn (array $call): bool => str_contains($call[0], 'option_contracts')
        ));

        $this->assertCount(1, $options);
        $this->assertStringContainsString('DELETE FROM option_contracts', $options[0][0]);
        $this->assertStringContainsString('NOT EXISTS', $options[0][0]);
        $this->assertStringContainsString('user_options.option_contract_id', $options[0][0]);
        $this->assertSame('EXPIRED', $options[0][1]['status']);
        $this->assertEqualsWithDelta(
            12.0 - PruneHistoryCommand::EXPIRED_OPTION_YEARS_KEPT,
            $options[0][1]['cutoff'],
            1e-9
        );

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Downsampling Market History', $output);
        $this->assertStringContainsString('Cleared 150 redundant rows from stock_history', $output);
        $this->assertStringContainsString('Cleared 150 redundant rows from etf_history', $output);
        $this->assertStringContainsString('Cleared 150 redundant rows from bond_history', $output);
        $this->assertStringContainsString('Cleared 150 settled contracts from option_contracts', $output);
    }
}
