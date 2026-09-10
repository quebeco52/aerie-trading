<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use App\Service\Market\StockTracker;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Market\MarketEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\CorporateActionEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Math\MathUtility;
use App\Service\Math\CorporateMetrics;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Entity\StockHistory;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
class StockTrackerTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManagerMock;
    private MarketEngine&Stub $marketEngineMock;
    private EarningsEngine&Stub $earningsEngineMock;
    private CorporateActionEngine&Stub $corporateActionEngineMock;
    private MergerAndAcquisitionEngine&Stub $maEngineMock;
    private MarketEventPublisher&Stub $marketEventMock;
    private DebtEngine&Stub $debtEngineMock;
    private MathUtility&Stub $mathUtilityMock;
    private CorporateMetrics&Stub $corporateMetricsMock;
    private \App\Service\Market\Flow\InMemoryOrderFlowStore $orderFlow;
    private StockTracker $tracker;

    protected function setUp(): void
    {
        $this->entityManagerMock = $this->createMock(EntityManagerInterface::class);
        $this->marketEngineMock = $this->createStub(MarketEngine::class);
        $this->earningsEngineMock = $this->createStub(EarningsEngine::class);
        $this->corporateActionEngineMock = $this->createStub(CorporateActionEngine::class);
        $this->maEngineMock = $this->createStub(MergerAndAcquisitionEngine::class);
        $this->marketEventMock = $this->createStub(MarketEventPublisher::class);
        $this->debtEngineMock = $this->createStub(DebtEngine::class);
        $this->mathUtilityMock = $this->createStub(MathUtility::class);
        $this->corporateMetricsMock = $this->createStub(CorporateMetrics::class);
        
        $this->mathUtilityMock->method('calculateSVJJJumps')->willReturn([
            'price_multiplier' => 1.0,
            'var_jump' => 0.0,
            'shock_pct' => null
        ]);
        
        $debtMetrics = new \App\DTO\DebtMetricsDTO(
            interestExpense: 0.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.01,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 1000.0,
            revenue: 5000.0,
            depreciation: 100.0,
            ebitda: 1100.0
        );
        $this->debtEngineMock->method('analyzeDebtHealth')->willReturn(new \App\DTO\DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.04,
            cashYield: 0.02,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 5.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 2.0,
            wacc: 0.08,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        ));
        
        $this->orderFlow = new \App\Service\Market\Flow\InMemoryOrderFlowStore();

        $this->tracker = new StockTracker(
            $this->entityManagerMock,
            $this->marketEngineMock,
            $this->earningsEngineMock,
            $this->corporateActionEngineMock,
            $this->maEngineMock,
            $this->marketEventMock,
            $this->debtEngineMock,
            $this->mathUtilityMock,
            $this->corporateMetricsMock,
            new \App\Service\Market\LiquidityEngine(new \App\Service\Math\MathUtility()),
            $this->orderFlow
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
        $stock->setJumpIntensity('2.0'); // Corresponds to lambda
        $stock->setJumpVol('0.10'); // Corresponds to jump_vol
        
        return $stock;
    }

    public function testUpdateStocksStandardFlowWithoutEventsOrHistory()
    {
        $stock = $this->createDummyStock();
        
        // Mock the engines returning standard, uneventful updates
        $this->marketEngineMock->method('calculateNextPrice')->willReturn([
            'price' => 105.0,
            'shock' => null, // No market shock
            'next_volatility' => 0.21,
            'analyst_targets' => [],
            'perceived_fair_value' => 100.0
        ]);

        $this->earningsEngineMock->method('calculate')->willReturn(null); // No earnings report

        $this->corporateActionEngineMock->method('processSplits')->willReturn([
            'price' => 105.0,
            'shares' => 1000,
            'event' => null // No split
        ]);

        // We no longer flush in the tracker, so expect it to never be called
        $this->entityManagerMock->expects($this->never())->method('flush');
        
        // We passed false to $recordHistory and have no shocks, so persist should never be called
        $this->entityManagerMock->expects($this->never())->method('persist');

        $result = $this->tracker->updateStocks([$stock], 1.0, false);

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
            'next_volatility' => 0.50,
            'analyst_targets' => [],
            'perceived_fair_value' => 100.0
        ]);

        $this->earningsEngineMock->method('calculate')->willReturn(null);
        
        $this->corporateActionEngineMock->method('processSplits')->willReturn([
            'price' => 90.0,
            'shares' => 1000,
            'event' => null
        ]);

        $this->marketEventMock->method('publish')->willReturn([
            'type' => 'SHOCK',
            'ticker' => 'TEST',
            'description' => 'Sudden market shock detected.',
            'change_percent' => -10.0
        ]);

        $this->entityManagerMock->expects($this->never())->method('persist');
        $this->entityManagerMock->expects($this->never())->method('flush');

        $result = $this->tracker->updateStocks([$stock], 1.0, true);

        $this->assertCount(1, $result['events']);
        $this->assertEquals('SHOCK', $result['events'][0]['type']);
        
        $this->assertIsArray($result['history']);
        $this->assertCount(1, $result['history']);
        $this->assertEquals(90.0, $result['history'][0]['price']);
    }
}