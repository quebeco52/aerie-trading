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

        // Expect two executeStatement calls (one for stock_history, one for etf_history)
        $connectionMock->expects($this->exactly(2))
            ->method('executeStatement')
            ->willReturn(150);

        $command = new PruneHistoryCommand($emMock);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--days' => '30',
            '--ratio' => '100'
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Downsampling Market History', $output);
        $this->assertStringContainsString('Cleared 150 redundant rows from stock_history', $output);
        $this->assertStringContainsString('Cleared 150 redundant rows from etf_history', $output);
    }
}
