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
        $realMath = new MathUtility();
        $this->mathUtilityMock->method('calculateDynamicWorkingCapitalIntensity')->willReturnCallback(
            fn($base, $cs, $cu, $ib) => $realMath->calculateDynamicWorkingCapitalIntensity($base, $cs, $cu, $ib)
        );

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

    public function testCalculateReturnsNullWhenNoEventOccurs(): void
    {
        $stock = new Stock();
        $stock->setTicker('TEST');
        
        $reportingTick = $this->getReportingTick('TEST', 252);
        $offTick = ($reportingTick + 1) % 63;
        $macroState = new \App\DTO\MacroStateDTO();
        $result = $this->engine->calculate($stock, $macroState, $offTick, 252);
        
        $this->assertNull($result, 'Engine should return null when the earnings probability check fails.');
    }

    public function testStandardPositiveEarningsReport(): void
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
        $stock->setCustomerDeposits('0');
        $stock->setCorporateTreasury('10000000');
        $stock->setBaselineRoic('0.10');
        $stock->setOperatingMargin('0.20');

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

    public function testExtremeEarningsTriggersVolatilityShock(): void
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
        $stock->setCustomerDeposits('0');
        $stock->setCorporateTreasury('10000000');
        $stock->setBaselineRoic('0.10');
        $stock->setOperatingMargin('0.20');

        // Force an extreme blowout quarter (Z > 1.5 triggers the shock)
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(2.0);

        $reportingTick = $this->getReportingTick('SHOCK');
        $macroState = new \App\DTO\MacroStateDTO();
        $this->engine->calculate($stock, $macroState, $reportingTick, 252);

        $this->assertGreaterThan(0.20, (float) $stock->getCurrentVolatility(), 'Volatility should have spiked due to the extreme surprise.');
    }

    public function testNegativeEpsBenefitsFromRecoveryBoost(): void
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
        $stock->setCustomerDeposits('0');
        $stock->setCorporateTreasury('10000000');
        $stock->setBaselineRoic('0.10');
        $stock->setOperatingMargin('0.20');

        // Neutral quarter (0.0) isolates the recovery boost math
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $reportingTick = $this->getReportingTick('RECOV');
        $macroState = new \App\DTO\MacroStateDTO();
        $this->engine->calculate($stock, $macroState, $reportingTick, 252);

        $this->assertNotEquals(-10.00, (float) $stock->getEarningsPerShare(), 'A company with negative EPS should still see EPS changes.');
    }

    public function testEbitAndEbitdaAccountingBridge(): void
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

    public function testVolatilityShockTriggersOnCompositeEarningsMiss(): void
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

    public function testWorkingCapitalStrainDrainsFreeCashFlowEvenOnFlatRevenue(): void
    {
        $stockCalm = new Stock();
        $stockCalm->setTicker('NWCF');
        $stockCalm->setEarningsPerShare('2.00');
        $stockCalm->setSharesOutstanding('1000000');
        $stockCalm->setVolatility('0.10');
        $stockCalm->setCurrentVolatility('0.10');
        $stockCalm->setBeta('1.0');
        $stockCalm->setTotalEquity('100000000');
        $stockCalm->setWholesaleDebt('0');
        $stockCalm->setCorporateTreasury('10000000');
        $stockCalm->setBaselineRoic('0.10');
        $stockCalm->setOperatingMargin('0.20');

        $stockStressed = clone $stockCalm;

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $calmMacro = new \App\DTO\MacroStateDTO(
            macroCreditSpreadEma: 0.02,
            interbankLiquiditySpreadEma: 0.0015
        );
        $stressedMacro = new \App\DTO\MacroStateDTO(
            macroCreditSpreadEma: 0.06, // +400bps credit spread strain (DSO expansion)
            interbankLiquiditySpreadEma: 0.0100 // +85bps interbank liquidity strain (DPO contraction)
        );

        $reportingTick = $this->getReportingTick('NWCF');

        // Run Calm
        $stockCalm->setPreviousRevenue('25000000');
        $this->engine->calculate($stockCalm, $calmMacro, $reportingTick, 252);
        $calmFcfPerShare = (float) $stockCalm->getFreeCashFlowPerShare();

        // Run Stressed on identical initial state
        $stockStressed->setPreviousRevenue('25000000');
        $this->engine->calculate($stockStressed, $stressedMacro, $reportingTick, 252);
        $stressedFcfPerShare = (float) $stockStressed->getFreeCashFlowPerShare();

        // Under macro stress with identical revenue, dynamic intensity rises and drains FCF (stressed FCF < calm FCF)
        $this->assertLessThan(
            $calmFcfPerShare,
            $stressedFcfPerShare,
            'Credit spread stress expanding CCC days must drain Free Cash Flow even with flat revenue.'
        );
    }

    public function testCipQueueDoesNotWipeoutRevenueGeneratingCapital(): void
    {
        $stock = new Stock();
        $stock->setTicker('RIVE_TEST');
        $stock->setIndustry('Specialty Industrial Machinery');
        $stock->setEarningsPerShare('2.00');
        $stock->setSharesOutstanding('1000000000');
        $stock->setVolatility('0.26');
        $stock->setCurrentVolatility('0.26');
        $stock->setBeta('1.3');
        $stock->setTotalEquity('48000000000');
        $stock->setWholesaleDebt('28000000000');
        $stock->setCorporateTreasury('5000000000');
        $stock->setBaselineRoic('0.22');
        $stock->setOperatingMargin('0.18');

        // Simulate accumulated CIP balance that exceeds invested capital
        $stock->setCipBalance('100000000000'); // $100B in CIP queue

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $macroState = new \App\DTO\MacroStateDTO();
        $reportingTick = $this->getReportingTick('RIVE_TEST');

        $this->engine->calculate($stock, $macroState, $reportingTick, 252);

        $revenue = (float) $stock->getTotalRevenue();
        // Annual revenue must remain substantial (at least 75% of baseline capacity) despite huge CIP queue
        $this->assertGreaterThan(10_000_000_000.0, $revenue, 'Revenue-generating capital must not be wiped out by elevated CIP queue.');
    }

    public function testAnnualizedPreviousRevenueDoesNotCauseNwcImplosion(): void
    {
        $stock = new Stock();
        $stock->setTicker('NWC_BUG');
        $stock->setIndustry('Heavy Manufacturing');
        $stock->setEarningsPerShare('2.00');
        $stock->setSharesOutstanding('1000000');
        $stock->setVolatility('0.10');
        $stock->setCurrentVolatility('0.10');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('100000000');
        $stock->setWholesaleDebt('0');
        $stock->setCorporateTreasury('10000000');
        $stock->setBaselineRoic('0.10');
        $stock->setOperatingMargin('0.20');

        // Simulate that the DB contains a previously *annualized* revenue of $100M
        $stock->setPreviousRevenue('100000000');

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $macroState = new \App\DTO\MacroStateDTO();
        $reportingTick = $this->getReportingTick('NWC_BUG');

        $this->engine->calculate($stock, $macroState, $reportingTick, 252);

        // Before the bug fix, $100M was treated as quarterly, generating an implied annualized previous revenue of $400M.
        // If current revenue was flat at $100M annualized, this caused a $300M negative delta, massively inflating FCF.
        // With the fix, the delta should be minimal, and FCF should be a reasonable portion of net income.
        $fcfPerShare = (float) $stock->getFreeCashFlowPerShare();
        $totalFcf = $fcfPerShare * (float) $stock->getSharesOutstanding();

        // Under the bug, previous revenue was quadrupled to $400M, triggering an artificial $300M revenue drop
        // that generated over $250M+ in annualized false NWC release.
        // With the fix, annualized FCF remains within reasonable bounds (< $75M).
        $this->assertLessThan(
            75_000_000.0,
            $totalFcf,
            'Free Cash Flow should not be massively inflated by a false negative NWC delta caused by annualized previous revenue mismatch.'
        );
    }

    public function testWageInflationOverheatingSqueezesFixedCosts(): void
    {
        $createStock = function(): Stock {
            $stock = new Stock();
            $stock->setTicker('WAGE_TEST');
            $stock->setIndustry('Technology');
            $stock->setEarningsPerShare('2.00');
            $stock->setSharesOutstanding('1000000');
            $stock->setVolatility('0.10');
            $stock->setCurrentVolatility('0.10');
            $stock->setBeta('1.0');
            $stock->setTotalEquity('100000000');
            $stock->setWholesaleDebt('0');
            $stock->setCorporateTreasury('10000000');
            $stock->setBaselineRoic('0.10');
            $stock->setOperatingMargin('0.20');
            $stock->setPreviousRevenue('100000000');
            return $stock;
        };

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $reportingTick = $this->getReportingTick('WAGE_TEST');

        // 1. Normal wage growth economy (3.5%)
        $stockNormal = $createStock();
        $normalMacro = new \App\DTO\MacroStateDTO(
            wageGrowth: 0.035,
            wageGrowthEma: 0.035,
            inflation: 0.02,
            inflationEma: 0.02
        );
        $this->engine->calculate($stockNormal, $normalMacro, $reportingTick, 252);
        $normalEps = (float) $stockNormal->getEarningsPerShare();

        // 2. Severe wage-push inflation economy (7.5% wage growth)
        $stockOverheated = $createStock();
        $overheatedMacro = new \App\DTO\MacroStateDTO(
            wageGrowth: 0.075,
            wageGrowthEma: 0.075,
            inflation: 0.02,
            inflationEma: 0.02
        );
        $this->engine->calculate($stockOverheated, $overheatedMacro, $reportingTick, 252);
        $overheatedEps = (float) $stockOverheated->getEarningsPerShare();

        $this->assertLessThan(
            $normalEps,
            $overheatedEps,
            'Excess wage growth above trend must inflate fixed SG&A overhead costs and compress corporate earnings.'
        );
    }
}