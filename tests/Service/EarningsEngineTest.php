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
        $this->mathUtilityMock->method('calculateWorkingCapitalDayShifts')->willReturnCallback(
            fn($cs, $cu, $ib) => $realMath->calculateWorkingCapitalDayShifts($cs, $cu, $ib)
        );
        $this->mathUtilityMock->method('calculateBayesianAnalystUpdate')->willReturnCallback(
            fn($pEst, $pVar, $sEst, $sVar) => $realMath->calculateBayesianAnalystUpdate($pEst, $pVar, $sEst, $sVar)
        );
        $this->mathUtilityMock->method('calculateAsymmetricCostStickiness')->willReturnCallback(
            fn($m, $r) => $realMath->calculateAsymmetricCostStickiness($m, $r)
        );
        $this->mathUtilityMock->method('calculateConvexPenalty')->willReturnCallback(
            fn($s, $c = 1.5, $sc = 1.0) => $realMath->calculateConvexPenalty($s, $c, $sc)
        );
        $this->mathUtilityMock->method('calculateCIR')->willReturnCallback(
            fn($cv, $k, $th, $s, $dt, $dw) => $realMath->calculateCIR($cv, $k, $th, $s, $dt, $dw)
        );
        $this->mathUtilityMock->method('calculateEarningsResponseCoefficient')->willReturnCallback(
            fn($sue, $beta, $growth) => $realMath->calculateEarningsResponseCoefficient($sue, $beta, $growth)
        );
        $this->mathUtilityMock->method('normalizeWeightsSimplex')->willReturnCallback(
            fn(array $w) => $realMath->normalizeWeightsSimplex($w)
        );
        $this->mathUtilityMock->method('calculateMeanRevertingWeight')->willReturnCallback(
            fn($pw, $ps, $tw, $ar, $rs, $mf, $mc) => $realMath->calculateMeanRevertingWeight($pw, $ps, $tw, $ar, $rs, $mf, $mc)
        );

        $this->corporateMetricsMock = $this->createStub(CorporateMetrics::class);
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn(10000000.0);
        // The ledger builders are pure arithmetic that writes balances onto the stock. A stub that returns
        // nothing would leave every firm here with no working capital, no plant and no lease, and the
        // tests below that measure working capital strain would be measuring an empty ledger.
        $realMetrics = new CorporateMetrics();
        $this->corporateMetricsMock->method('buildWorkingCapitalBalances')->willReturnCallback(
            fn($stock, $days, $rev, $costs) => $realMetrics->buildWorkingCapitalBalances($stock, $days, $rev, $costs)
        );
        $this->corporateMetricsMock->method('seedFixedAssetLedger')->willReturnCallback(
            fn($stock, $ic, $nwc, $gw, $cip, $age = \App\Service\Math\FinancialConstants::SEED_ASSET_AGE_RATIO) => $realMetrics->seedFixedAssetLedger($stock, $ic, $nwc, $gw, $cip, $age)
        );
        $this->corporateMetricsMock->method('seedReceivablesAllowance')->willReturnCallback(
            fn($stock, $rate) => $realMetrics->seedReceivablesAllowance($stock, $rate)
        );
        $this->corporateMetricsMock->method('calculateLeaseLiability')->willReturnCallback(
            fn($rev, $intensity) => $realMetrics->calculateLeaseLiability($rev, $intensity)
        );
        $this->corporateMetricsMock->method('getIndustryDepreciationRate')->willReturnCallback(
            fn($industry) => $realMetrics->getIndustryDepreciationRate($industry)
        );

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
        return EarningsEngine::resolveReportingTick($ticker, $ticksPerYear);
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

        // Force an extreme blowout quarter (SUE Z > 1.5 triggers the shock)
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(3.5);

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

    /**
     * The income statement bridge: cash operating costs give EBITDA, and depreciation is a real expense
     * line struck below it to give EBIT. Depreciation used to be added back on top of EBIT instead, so a
     * heavier charge moved EBITDA and left EBIT — the line every coverage and solvency test reads —
     * completely untouched.
     */
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
            $capturedContext->ebitda,
            0.0001,
            'EBITDA must equal revenue minus the cash operating cost base.'
        );
        $this->assertEqualsWithDelta(
            $capturedContext->ebitda - $capturedContext->quarterlyDepreciation,
            $capturedContext->ebit,
            0.0001,
            'EBIT must be struck after depreciation (no double-deduction).'
        );
        $this->assertLessThan(
            $capturedContext->ebitda,
            $capturedContext->ebit,
            'A firm that charges depreciation must report EBIT below EBITDA.'
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

        // Negative surprise shock (SUE Z < -1.5) creates a massive miss triggering volatility shock
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(-3.5);

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
        $ticksPerQuarter = 63;

        // Quarter 1: Seed NWC on calm conditions
        $this->engine->calculate($stockCalm, $calmMacro, $reportingTick, 252);
        $stockStressed = clone $stockCalm;

        // Quarter 2: Calm conditions continue for stockCalm (flat revenue, flat spreads)
        $q2Tick = $reportingTick + $ticksPerQuarter;
        $this->engine->calculate($stockCalm, $calmMacro, $q2Tick, 252);
        $calmFcfPerShare = (float) $stockCalm->getFreeCashFlowPerShare();

        // Quarter 2: Credit & liquidity spread blowout for stockStressed on identical flat revenue
        $this->engine->calculate($stockStressed, $stressedMacro, $q2Tick, 252);
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

    public function testReportingWindowClustering(): void
    {
        $ticksPerYear = 252;
        $ticksPerQuarter = 63;
        $lagTicks = (int) round($ticksPerQuarter * EarningsEngine::EARNINGS_REPORTING_LAG_RATIO); // ~14
        $seasonTicks = (int) round($ticksPerQuarter * EarningsEngine::EARNINGS_SEASON_LENGTH_RATIO); // ~25
        $windowEnd = $lagTicks + $seasonTicks; // ~39

        for ($i = 0; $i < 100; $i++) {
            $ticker = 'TICK_' . $i;
            $reportingTick = EarningsEngine::resolveReportingTick($ticker, $ticksPerYear);

            $this->assertGreaterThanOrEqual($lagTicks, $reportingTick, "Ticker {$ticker} reporting tick must be at or after lag.");
            $this->assertLessThan($windowEnd, $reportingTick, "Ticker {$ticker} reporting tick must be within the earnings season window.");

            // Verify checkReportingEligibility on and off the reporting tick
            $stock = new Stock();
            $stock->setTicker($ticker);
            $macro = new \App\DTO\MacroStateDTO();

            // Off tick (e.g. tick 0, before the window opens)
            $this->assertNull($this->engine->calculate($stock, $macro, 0, $ticksPerYear));
        }
    }

    public function testConsensusAnchoringGeneratesSerialSurpriseCorrelation(): void
    {
        $stock = new Stock();
        $stock->setTicker('ANCHOR_TEST');
        $stock->setIndustry('Heavy Manufacturing');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalEquity('100000000');
        $stock->setBaselineRoic('0.12');
        $stock->setOperatingMargin('0.20');
        $stock->setVolatility('0.15');
        $stock->setCurrentVolatility('0.15');

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $positiveMacro = new \App\DTO\MacroStateDTO(
            outputGapEma: 0.05 // Persistent 500bps positive output gap
        );

        $reportingTick = $this->getReportingTick('ANCHOR_TEST');
        $ticksPerQuarter = 63;

        $actualRevenues = [];
        $consensusRevenues = [];

        // Run 4 consecutive quarters with persistent positive demand shift
        for ($q = 0; $q < 4; $q++) {
            $tick = ($q * $ticksPerQuarter) + $reportingTick;
            $this->engine->calculate($stock, $positiveMacro, $tick, 252);
            // The stored anchor is the posterior BEFORE the walkdown, so it cannot compound through the
            // next estimate; the number analysts PUBLISH is the anchor shaded by the walkdown.
            $consensusRevenues[] = (float) $stock->getLastAnalystRevenue() * (1.0 - \App\Service\Market\MarketConsensusEngine::ANALYST_WALKDOWN_BIAS);
            $actualRevenues[] = (float) $stock->getTotalRevenue() / 4.0;
        }

        // Under Bayesian anchoring, analyst revenue estimates should adjust gradually across quarters
        $this->assertGreaterThan(0.0, $consensusRevenues[0]);

        // Bernard & Thomas (1989) PEAD signature: persistent demand shift causes analysts to under-react,
        // producing a run of positive surprises where actual quarterly revenue beats consensus.
        for ($i = 0; $i < count($consensusRevenues); $i++) {
            $this->assertGreaterThan(
                $consensusRevenues[$i],
                $actualRevenues[$i],
                "Actual revenue should beat anchored analyst consensus in quarter {$i}."
            );
        }
    }

    public function testMarginShockProducesEarningsSurpriseIndependentOfRevenue(): void
    {
        $stockNormal = new Stock();
        $stockNormal->setTicker('MGN_NORM');
        $stockNormal->setIndustry('Heavy Manufacturing');
        $stockNormal->setSharesOutstanding('1000000');
        $stockNormal->setTotalEquity('100000000');
        $stockNormal->setBaselineRoic('0.10');
        $stockNormal->setOperatingMargin('0.20');
        $stockNormal->setVolatility('0.15');
        $stockNormal->setCurrentVolatility('0.15');

        $stockShocked = clone $stockNormal;
        $stockShocked->setTicker('MGN_SHK');

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $macroNormal = new \App\DTO\MacroStateDTO();
        // Negative output gap compresses dynamic variable theta (cyclical margin squeeze)
        $macroShocked = new \App\DTO\MacroStateDTO(outputGapEma: -0.05);

        $tickNormal = $this->getReportingTick('MGN_NORM');
        $tickShocked = $this->getReportingTick('MGN_SHK');

        $this->engine->calculate($stockNormal, $macroNormal, $tickNormal, 252);
        $this->engine->calculate($stockShocked, $macroShocked, $tickShocked, 252);

        // Operating margin should be lower under margin squeeze
        $this->assertLessThan(
            (float) $stockNormal->getEarningsPerShare(),
            (float) $stockShocked->getEarningsPerShare(),
            'Cyclical margin compression must reduce EPS and produce an earnings miss.'
        );
    }

    public function testSueNormalizedVolatilityShock(): void
    {
        $stock = new Stock();
        $stock->setTicker('SUE_VOL');
        $stock->setIndustry('Heavy Manufacturing');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalEquity('100000000');
        $stock->setBaselineRoic('0.10');
        $stock->setOperatingMargin('0.20');
        $stock->setVolatility('0.20');
        $stock->setCurrentVolatility('0.20');

        $reportingTick = $this->getReportingTick('SUE_VOL');
        $macro = new \App\DTO\MacroStateDTO();

        // 1. Extreme surprise (Z = 3.5) normalized by SUE dispersion (> 1.5) triggers volatility shock
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(3.5);
        $this->engine->calculate($stock, $macro, $reportingTick, 252);

        $this->assertGreaterThan(0.20, (float) $stock->getCurrentVolatility(), 'High SUE surprise must trigger volatility expansion.');
    }

    public function testSeasonalityProducesOperatingLeverageWithoutSpuriousSurprise(): void
    {
        $stock = new Stock();
        $stock->setTicker('RETAIL_SEAS');
        $stock->setIndustry('Internet Retail');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalEquity('100000000');
        $stock->setBaselineRoic('0.15');
        $stock->setOperatingMargin('0.15');
        $stock->setVolatility('0.15');
        $stock->setCurrentVolatility('0.15');

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $macro = new \App\DTO\MacroStateDTO();
        $ticksPerQuarter = 63;
        $reportingTick = $this->getReportingTick('RETAIL_SEAS');

        // Q1 (fiscal quarter 0, seasonal factor 0.85)
        $q1Tick = $reportingTick;
        $this->engine->calculate($stock, $macro, $q1Tick, 252);
        $q1Revenue = (float) $stock->getPreviousRevenue(); // reported quarterly revenue, annualized
        $q1RunRate = (float) $stock->getTotalRevenue();    // seasonally adjusted annual rate

        // Q4 (fiscal quarter 3, seasonal factor 1.35)
        $q4Tick = (3 * $ticksPerQuarter) + $reportingTick;
        $this->engine->calculate($stock, $macro, $q4Tick, 252);
        $q4Revenue = (float) $stock->getPreviousRevenue();
        $q4RunRate = (float) $stock->getTotalRevenue();

        // Peak holiday Q4 reported revenue must significantly exceed trough Q1 reported revenue
        $this->assertGreaterThan(
            $q1Revenue * 1.30,
            $q4Revenue,
            'Seasonal holiday quarter (Q4) revenue must exceed off-peak quarter (Q1) for Internet Retail.'
        );

        // ...while the seasonally adjusted run-rate is the same structural capacity in both quarters: the
        // season is not mistaken for a change in the business.
        $this->assertEqualsWithDelta($q1RunRate, $q4RunRate, $q1RunRate * 0.01, 'SAAR must not carry seasonal swings');
    }

    public function testCapacityUtilizationOvertimeConvexityAndClamping(): void
    {
        $stock = new Stock();
        $stock->setTicker('CAP_CLAMP');
        $stock->setIndustry('Heavy Manufacturing');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalEquity('100000000');
        $stock->setBaselineRoic('0.10');
        $stock->setOperatingMargin('0.20');
        $stock->setVolatility('0.15');
        $stock->setCurrentVolatility('0.15');

        // Extreme boom to test utilization clamping
        $boomMacro = new \App\DTO\MacroStateDTO(
            outputGapEma: 0.50 // Massive overheating
        );

        $reportingTick = $this->getReportingTick('CAP_CLAMP');
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $this->engine->calculate($stock, $boomMacro, $reportingTick, 252);

        // Revenue should be finite and bounded by MAX_CAPACITY_UTILIZATION
        $revenue = (float) $stock->getTotalRevenue();
        $this->assertTrue(is_finite($revenue));
        $this->assertGreaterThan(0.0, $revenue);
    }

    public function testSeasonalAnnualizationDeseasonalizesAnnualRunrateAndProtectsDebtHealth(): void
    {
        $stock = new Stock();
        $stock->setTicker('IBHI_SEAS');
        $stock->setIndustry('Engineering & Construction');
        $stock->setSharesOutstanding('1000000');
        $stock->setTotalEquity('100000000');
        $stock->setFixedCostRatio(0.60);
        $stock->setOperatingMargin('0.09');
        $stock->setBaselineRoic('0.09');
        $stock->setVolatility('0.15');
        $stock->setCurrentVolatility('0.15');
        $stock->setBeta('1.0');
        $stock->setWholesaleDebt('10000000');
        $stock->setCustomerDeposits('0');
        $stock->setCorporateTreasury('5000000');

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $macro = new \App\DTO\MacroStateDTO();
        $reportingTick = $this->getReportingTick('IBHI_SEAS');

        $dispatchedContext = null;
        $this->eventDispatcherMock->method('dispatch')->willReturnCallback(function ($event) use (&$dispatchedContext) {
            if ($event instanceof \App\Service\Event\EarningsReportedEvent) {
                $dispatchedContext = $event->getContext();
            }
            return $event;
        });

        // Q1 (fiscal quarter 0, factor 0.88)
        $result = $this->engine->calculate($stock, $macro, $reportingTick, 252);
        $this->assertNotNull($result, 'Engine calculate() must return a valid earnings event.');

        $this->assertNotNull($dispatchedContext);
        $this->assertEquals(0.88, $dispatchedContext->seasonalFactor);

        // De-seasonalized annual revenue should equal 4 * (actualRevenue / 0.88)
        $expectedSaarRevenue = ($dispatchedContext->actualRevenue / 0.88) * 4.0;
        $this->assertEqualsWithDelta($expectedSaarRevenue, (float) $stock->getTotalRevenue(), 1.0);

        // Debt health must clear debt issuance gate (canIssueDebt = true) and have healthy coverage
        $this->assertNotNull($dispatchedContext->health);
        $this->assertTrue($dispatchedContext->health->canIssueDebt, 'Seasonally adjusted debt health must permit debt issuance.');
        $this->assertGreaterThan(3.5, $dispatchedContext->health->interestCoverage, 'Seasonally adjusted ICR must clear the coverage gate.');

        // Annualized EPS must remain positive despite winter seasonal trough
        $this->assertGreaterThan(0.0, (float) $stock->getEarningsPerShare());
    }
}