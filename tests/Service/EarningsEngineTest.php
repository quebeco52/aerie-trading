<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\DTO\EarningsSimulationContext;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Math\MathUtility;
use App\Service\Math\CorporateMetrics;
use App\Service\Event\NarrativeEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\MarketConsensusEngine;
use App\Data\EconomicCycle;
use App\Entity\Stock;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AllowMockObjectsWithoutExpectations]
class EarningsEngineTest extends TestCase
{
    private MathUtility&Stub $mathUtilityMock;
    private MarketEventPublisher&Stub $marketEventMock;
    private CapitalAllocationEngine&Stub $capitalAllocationEngineMock;
    private DebtEngine&Stub $debtEngineMock;
    private CapExEngine&Stub $capExEngineMock;
    private CorporateMetrics&Stub $corporateMetricsMock;
    private NarrativeEngine&Stub $narrativeEngineMock;
    private EventDispatcherInterface&MockObject $eventDispatcherMock;
    private EarningsEngine $engine;

    protected function setUp(): void
    {
        $this->capitalAllocationEngineMock = $this->createStub(CapitalAllocationEngine::class);
        $this->capitalAllocationEngineMock->method('allocateCapital')->willReturn([
            'new_shares' => 1000000,
            'dividend_paid' => 0.0,
            'total_paid' => 0.0,
            'total_cash_spent' => 0.0,
            'organic_capex' => 0.0,
            'events' => []
        ]);

        $this->debtEngineMock = $this->createStub(DebtEngine::class);
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
        $this->debtEngineMock->method('calculateInterestExpense')->willReturn($debtMetrics);
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

        $this->marketEventMock = $this->createStub(MarketEventPublisher::class);
        $this->marketEventMock->method('publish')->willReturnCallback(function($stock, $type, $desc, $pct) {
            return [
                'type' => $type,
                'ticker' => $stock->getTicker(),
                'description' => $desc,
                'magnitude' => $pct
            ];
        });

        // 3. Mock MathUtility to control the stochastic Z-scores
        $this->mathUtilityMock = $this->createStub(MathUtility::class);
        $this->mathUtilityMock->method('generatePersistentZ')->willReturnCallback(function($prev, $phi) {
            return $this->mathUtilityMock->generateStandardNormal();
        });
        $this->mathUtilityMock->method('calculateKalmanSmoothedEps')->willReturnCallback(function($structuralEps, $actualRaw) {
            return $actualRaw;
        });
        $this->mathUtilityMock->method('calculateJumpDiffusion')->willReturn(['exponent' => 0.0]);

        $this->corporateMetricsMock = $this->createStub(CorporateMetrics::class);
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn(10000000.0);

        $this->narrativeEngineMock = $this->createStub(NarrativeEngine::class);
        $this->eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $this->capExEngineMock = $this->createStub(CapExEngine::class);

        // Instantiate the core engine
        $this->engine = new EarningsEngine(
            $this->eventDispatcherMock,
            $this->marketEventMock,
            $this->capitalAllocationEngineMock,
            $this->debtEngineMock,
            $this->capExEngineMock,
            $this->mathUtilityMock,
            $this->corporateMetricsMock,
            $this->narrativeEngineMock,
            new MarketConsensusEngine()
        );
    }

    private function getReportingTick(string $ticker, int $ticksPerYear = 252): int
    {
        $ticksPerQuarter = (int) ($ticksPerYear / 4);
        $ticksPerSeason = $ticksPerQuarter;
        return abs(crc32($ticker)) % max(1, $ticksPerSeason);
    }

    public function testCalculateReturnsNullWhenNoEventOccurs()
    {
        $stock = new Stock();
        $stock->setTicker('TEST');
        
        $reportingTick = $this->getReportingTick('TEST', 252);
        $offTick = ($reportingTick + 1) % 63;
        $macroState = new \App\DTO\MacroStateDTO();
        $result = $this->engine->calculate($stock, $macroState, $offTick, 252);
        
        $this->assertNull($result, 'Engine should return null when the earnings probability check fails.');
    }

    public function testStandardPositiveEarningsReport()
    {
        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setEarningsPerShare('10.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setVolatility('0.20');
        $stock->setCurrentVolatility('0.20');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('150000000');
        $stock->setWholesaleDebt('0');
        if (method_exists($stock, 'setCustomerDeposits')) $stock->setCustomerDeposits('0');
        $stock->setCorporateTreasury('10000000');
        $stock->setBaselineRoic('0.10');
        $stock->setOperatingMargin('0.20');
        if (method_exists($stock, 'setInvestedCapital')) {
            $stock->setInvestedCapital('140000000');
        }

        // Force a mildly positive business quarter
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.5);

        $reportingTick = $this->getReportingTick('TEST');
        $macroState = new \App\DTO\MacroStateDTO();
        $result = $this->engine->calculate($stock, $macroState, $reportingTick, 252);

        $this->assertNotNull($result);
        $this->assertArrayHasKey(0, $result);
        $this->assertEquals('EARNINGS', $result[0]['type']);
        $this->assertEquals('TEST', $result[0]['ticker']);
        
        // EPS should have increased
        $this->assertGreaterThan(10.00, (float) $stock->getEarningsPerShare());
    }

    public function testExtremeEarningsTriggersVolatilityShock()
    {
        $stock = new Stock();
        $stock->setTicker('SHOCK');
        $stock->setEarningsPerShare('10.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setVolatility('0.20');
        $stock->setCurrentVolatility('0.20');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('150000000');
        $stock->setWholesaleDebt('0');
        if (method_exists($stock, 'setCustomerDeposits')) $stock->setCustomerDeposits('0');
        $stock->setCorporateTreasury('10000000');
        $stock->setBaselineRoic('0.10');
        $stock->setOperatingMargin('0.20');
        if (method_exists($stock, 'setInvestedCapital')) {
            $stock->setInvestedCapital('140000000');
        }

        // Force an extreme blowout quarter (Z > 1.5 triggers the shock)
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(2.0);

        $reportingTick = $this->getReportingTick('SHOCK');
        $macroState = new \App\DTO\MacroStateDTO();
        $this->engine->calculate($stock, $macroState, $reportingTick, 252);

        $this->assertGreaterThan(0.20, (float) $stock->getCurrentVolatility(), 'Volatility should have spiked due to the extreme surprise.');
    }

    public function testNegativeEpsBenefitsFromRecoveryBoost()
    {
        $stock = new Stock();
        $stock->setTicker('RECOV');
        $stock->setEarningsPerShare('-10.00'); // Company is bleeding cash
        $stock->setSharesOutstanding('1000000');
        $stock->setVolatility('0.20');
        $stock->setCurrentVolatility('0.20');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('150000000');
        $stock->setWholesaleDebt('0');
        if (method_exists($stock, 'setCustomerDeposits')) $stock->setCustomerDeposits('0');
        $stock->setCorporateTreasury('10000000');
        $stock->setBaselineRoic('0.10');
        $stock->setOperatingMargin('0.20');
        if (method_exists($stock, 'setInvestedCapital')) {
            $stock->setInvestedCapital('140000000');
        }

        // Neutral quarter (0.0) isolates the recovery boost math
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $reportingTick = $this->getReportingTick('RECOV');
        $macroState = new \App\DTO\MacroStateDTO();
        $this->engine->calculate($stock, $macroState, $reportingTick, 252);

        $this->assertNotEquals(-10.00, (float) $stock->getEarningsPerShare(), 'A company with negative EPS should still see EPS changes.');
    }

    public function testEbitAndEbitdaAccountingBridge()
    {
        $stock = new Stock();
        $stock->setTicker('DEPR');
        $stock->setEarningsPerShare('5.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setVolatility('0.20');
        $stock->setCurrentVolatility('0.20');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('100000000');
        $stock->setWholesaleDebt('0');
        $stock->setCorporateTreasury('10000000');
        $stock->setBaselineRoic('0.12');
        $stock->setOperatingMargin('0.20');
        $stock->setDepreciationRate('0.08');

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        /** @var EarningsSimulationContext|null $capturedContext */
        $capturedContext = null;
        $this->eventDispatcherMock->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function($event) use (&$capturedContext) {
                if ($event instanceof \App\Service\Event\EarningsReportedEvent) {
                    $capturedContext = $event->getContext();
                }
                return $event;
            });

        $reportingTick = $this->getReportingTick('DEPR');
        $macroState = new \App\DTO\MacroStateDTO();
        $this->engine->calculate($stock, $macroState, $reportingTick, 252);

        $this->assertInstanceOf(EarningsSimulationContext::class, $capturedContext);
        $this->assertGreaterThan(0.0, $capturedContext->quarterlyDepreciation, 'Quarterly depreciation must be positive.');
        $this->assertEqualsWithDelta(
            $capturedContext->actualRevenue - $capturedContext->operatingCosts,
            $capturedContext->ebit,
            0.0001,
            'EBIT must equal revenue minus operating costs (no double-deduction).'
        );
        $this->assertEqualsWithDelta(
            $capturedContext->ebit + $capturedContext->quarterlyDepreciation,
            $capturedContext->ebitda,
            0.0001,
            'EBITDA must equal EBIT plus quarterly depreciation.'
        );
    }

    public function testVolatilityShockTriggersOnCompositeEarningsMiss()
    {
        $stock = new Stock();
        $stock->setTicker('MISS');
        $stock->setEarningsPerShare('10.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setVolatility('0.20');
        $stock->setCurrentVolatility('0.20');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('150000000');
        $stock->setWholesaleDebt('0');
        $stock->setCorporateTreasury('10000000');
        $stock->setBaselineRoic('0.10');
        $stock->setOperatingMargin('0.20');

        // Negative surprise shock (Z = -2.0) creates a massive miss
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(-2.0);

        $reportingTick = $this->getReportingTick('MISS');
        $macroState = new \App\DTO\MacroStateDTO();
        $this->engine->calculate($stock, $macroState, $reportingTick, 252);

        $this->assertGreaterThan(0.20, (float) $stock->getCurrentVolatility(), 'Volatility should have spiked due to the composite earnings miss.');
    }
}