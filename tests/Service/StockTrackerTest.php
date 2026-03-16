<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Service\StockTracker;
use App\Service\MarketEngine;
use App\Service\EarningsEngine;
use App\Service\CorporateActionEngine;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Entity\StockHistory;

class StockTrackerTest extends TestCase
{
    private EntityManagerInterface|MockObject $entityManagerMock;
    private MarketEngine|MockObject $marketEngineMock;
    private EarningsEngine|MockObject $earningsEngineMock;
    private CorporateActionEngine|MockObject $corporateActionEngineMock;
    private StockTracker $tracker;

    protected function setUp(): void
    {
        $this->entityManagerMock = $this->createMock(EntityManagerInterface::class);
        $this->marketEngineMock = $this->createMock(MarketEngine::class);
        $this->earningsEngineMock = $this->createMock(EarningsEngine::class);
        $this->corporateActionEngineMock = $this->createMock(CorporateActionEngine::class);
        
        $this->tracker = new StockTracker(
            $this->entityManagerMock,
            $this->marketEngineMock,
            $this->earningsEngineMock,
            $this->corporateActionEngineMock
        );
    }

    private function createDummyStock(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setSector('Technology');
        $stock->setPrice('100.00');
        $stock->setSharesOutstanding('1000');
        $stock->setEarningsPerShare('5.00');
        $stock->setVolatility('0.20');
        $stock->setCurrentVolatility('0.20');
        $stock->setBeta('1.0');
        $stock->setJumpIntensity('2.0');
        $stock->setJumpMean('0.01');
        $stock->setJumpVol('0.10');
        
        return $stock;
    }

    public function testUpdateStocksStandardFlowWithoutEventsOrHistory()
    {
        $stock = $this->createDummyStock();
        
        // Mock the engines returning standard, uneventful updates
        $this->marketEngineMock->method('calculateNextPrice')->willReturn([
            'price' => 105.0,
            'shock' => null, // No market shock
            'next_volatility' => 0.21
        ]);

        $this->earningsEngineMock->method('calculate')->willReturn(null); // No earnings report

        $this->corporateActionEngineMock->method('processSplits')->willReturn([
            'price' => 105.0,
            'eps' => 5.0,
            'shares' => 1000,
            'event' => null // No split
        ]);

        // We no longer flush in the tracker, so expect it to never be called
        $this->entityManagerMock->expects($this->never())->method('flush');
        
        // We passed false to $recordHistory and have no shocks, so persist should never be called
        $this->entityManagerMock->expects($this->never())->method('persist');

        $result = $this->tracker->updateStocks([$stock], 1.0, ['Technology' => 20.0], false);

        $this->assertIsArray($result);
        $this->assertCount(1, $result['updates']);
        $this->assertEmpty($result['events']);
        
        // Market cap should be 105.0 * 1000
        $this->assertEquals(105000.0, $result['total_cap']);
        
        // Verify the Doctrine entity was properly mutated
        $this->assertEquals('105', $stock->getPrice());
        $this->assertEquals('0.21', $stock->getCurrentVolatility());
    }

    public function testUpdateStocksWithMarketShockAndHistoryRecording()
    {
        $stock = $this->createDummyStock();

        $this->marketEngineMock->method('calculateNextPrice')->willReturn([
            'price' => 90.0,
            'shock' => -10.0, // Simulate a 10% drop shock
            'next_volatility' => 0.50
        ]);

        $this->earningsEngineMock->method('calculate')->willReturn(null);
        
        $this->corporateActionEngineMock->method('processSplits')->willReturn([
            'price' => 90.0,
            'eps' => 5.0,
            'shares' => 1000,
            'event' => null
        ]);

        // Persist should be called exactly TWICE:
        // 1. To save the StockEvent for the shock
        // 2. To save the StockHistory tick
        $this->entityManagerMock->expects($this->exactly(2))
            ->method('persist')
            ->with($this->logicalOr(
                $this->isInstanceOf(StockEvent::class),
                $this->isInstanceOf(StockHistory::class)
            ));
            
        $this->entityManagerMock->expects($this->never())->method('flush');

        // Suppress the terminal echo during PHPUnit tests but verify it happened
        $this->expectOutputRegex('/MARKET SHOCK on TEST: \-10\.00\%/');

        $result = $this->tracker->updateStocks([$stock], 1.0, [], true);

        $this->assertCount(1, $result['events']);
        $this->assertEquals('SHOCK', $result['events'][0]['type']);
    }
}