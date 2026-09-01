<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\MarketSimulateCommand;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\MarketEventPublisher;
use App\Service\Event\NarrativeEngine;
use App\Service\Macro\MacroEngine;
use App\Service\Market\EtfTracker;
use App\Service\Market\MarketOperator;
use App\Service\Market\StockTracker;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class MarketSimulateCommandTest extends TestCase
{
    public function testExecuteSimulatesSpecifiedNumberOfYears(): void
    {
        $emMock = $this->createMock(EntityManagerInterface::class);
        $stockTrackerMock = $this->createMock(StockTracker::class);
        $etfTrackerMock = $this->createMock(EtfTracker::class);
        $macroEngineMock = $this->createMock(MacroEngine::class);
        $marketOperatorMock = $this->createStub(MarketOperator::class);
        $marketEventMock = $this->createStub(MarketEventPublisher::class);
        $narrativeEngineMock = $this->createStub(NarrativeEngine::class);
        $redisMock = $this->createStub(\Redis::class);

        $connStub = $this->createStub(Connection::class);
        $emMock->method('getConnection')->willReturn($connStub);

        $stock = new Stock();
        $stock->setTicker('APEX');

        $stockRepoStub = $this->createStub(EntityRepository::class);
        $stockRepoStub->method('findAll')->willReturn([$stock]);

        $emMock->method('getRepository')->willReturnCallback(function (string $entityClass) use ($stockRepoStub) {
            if ($entityClass === Stock::class) {
                return $stockRepoStub;
            }
            return $this->createStub(EntityRepository::class);
        });

        $macroState = new MacroStateDTO();
        $macroEngineMock->method('updateMacroState')->willReturn($macroState);

        $stockTrackerMock->method('updateStocks')->willReturn([
            'updates' => [],
            'events' => [],
            'total_cap' => 1_000_000_000.0,
            'history' => []
        ]);

        $command = new MarketSimulateCommand(
            $emMock,
            $stockTrackerMock,
            $etfTrackerMock,
            $macroEngineMock,
            $marketOperatorMock,
            $marketEventMock,
            $narrativeEngineMock,
            $redisMock
        );

        $tester = new CommandTester($command);

        // Simulate a small fraction of a year (e.g. 0.05 years ~ 18 ticks)
        $exitCode = $tester->execute(['years' => '0.05']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Initializing Aerie God Engine', $output);
        $this->assertStringContainsString('Simulation complete!', $output);
    }
}
