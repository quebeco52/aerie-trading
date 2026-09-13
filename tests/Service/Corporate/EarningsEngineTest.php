<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Service\Event\EarningsReportedEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use App\Data\LifecycleStage;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CorporateLedgerService;
use App\Service\Math\MathUtility;
use App\Service\Math\CorporateMetrics;
use App\Service\Event\NarrativeEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\MarketConsensusEngine;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class EarningsEngineTest extends TestCase
{
    private EarningsEngine $earningsEngine;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->mathUtility = new MathUtility();
        $this->earningsEngine = $this->buildEngine($this->createStub(EventDispatcherInterface::class));
    }

    private function buildEngine(EventDispatcherInterface $eventDispatcher): EarningsEngine
    {
        $corporateMetrics = new CorporateMetrics();
        $debtEngine = new DebtEngine($this->mathUtility, $corporateMetrics);
        $capExEngine = new CapExEngine();
        $marketConsensusEngine = new MarketConsensusEngine();

        $ledgerMock = $this->createStub(CorporateLedgerService::class);
        $eventPublisherMock = $this->createStub(MarketEventPublisher::class);
        $narrativeEngineMock = $this->createStub(NarrativeEngine::class);

        $treasuryEngine = new TreasuryEngine(
            $corporateMetrics,
            $debtEngine,
            $capExEngine,
            $this->mathUtility
        );

        $capitalAllocationEngine = new CapitalAllocationEngine(
            $ledgerMock,
            $corporateMetrics,
            $debtEngine,
            $this->mathUtility,
            $treasuryEngine
        );

        return new EarningsEngine(
            $eventDispatcher,
            $eventPublisherMock,
            $capitalAllocationEngine,
            $debtEngine,
            $capExEngine,
            $this->mathUtility,
            $corporateMetrics,
            $narrativeEngineMock,
            $marketConsensusEngine
        );
    }

    public function testConsecutiveContractionsDoNotCollapseStructuralMargin(): void
    {
        $stock = new Stock();
        $stock->setTicker('AUTO');
        $stock->setIndustry('Auto Manufacturers');
        $stock->setEarningsPerShare('2.50');
        $stock->setSharesOutstanding('1000000000');
        $stock->setPrice('50.00');
        $stock->setVolatility('0.15');
        $stock->setCurrentVolatility('0.15');
        $stock->setBeta('1.5');
        $stock->setTotalEquity('50000000000');
        $stock->setWholesaleDebt('20000000000');
        $stock->setCorporateTreasury('10000000000');
        $stock->setRetainedEarnings('10000000000');
        $stock->setTotalRevenue('80000000000');
        $stock->setOperatingMargin('0.10');
        $stock->setTargetPayoutRatio('0.30');
        $stock->setDividendSpeed('0.10');
        $stock->setFixedCostRatio(0.60);
        $stock->setCapexRatio('0.50');

        $initialMargin = 0.10; // Baseline Auto Manufacturers margin
        $stock->setStructuralVariableMargin($initialMargin);

        // Simulate a severe recession to trigger cost stickiness
        $macroState = new MacroStateDTO(
            outputGapEma: -0.05,
            nominalGdpIndex: 1.0,
            corporateTaxRate: 0.21,
            marketVolatilityEma: 0.35,
            policyRateEma: 0.05,
            yield5yEma: 0.05,
            macroCreditSpreadEma: 0.04,
            interbankLiquiditySpreadEma: 0.01
        );
        
        $stock->setPreviousRevenue('80000000000.0000'); // $80B annualized
        
        // Quarter 1: Severe contraction
        $this->earningsEngine->calculate($stock, $macroState, 1);
        
        $marginQ1 = (float) $stock->getStructuralVariableMargin();
        // The structural margin should remain stable around 0.10, not collapse to 0.01
        $this->assertGreaterThan(0.08, $marginQ1, "Structural margin dropped too aggressively in Q1 due to cost stickiness overwrite.");
        
        // Quarter 2: Continued contraction (let the engine use previousRevenue updated by Q1)
        $this->earningsEngine->calculate($stock, $macroState, 2);
        
        $marginQ2 = (float) $stock->getStructuralVariableMargin();
        // Even after back-to-back recessions, structural margin must not enter a death spiral
        $this->assertGreaterThan(0.07, $marginQ2, "Structural margin collapsed in Q2. Cost stickiness is causing a death spiral.");
    }

    public function testConsecutiveQuartersDuringBoomDoNotTriggerEmergencyBorrowing(): void
    {
        $stock = new Stock();
        $stock->setTicker('IBHI');
        $stock->setIndustry('Construction & Engineering');
        $stock->setEarningsPerShare('4.00');
        $stock->setSharesOutstanding('1000000000');
        $stock->setPrice('100.00');
        $stock->setVolatility('0.15');
        $stock->setCurrentVolatility('0.15');
        $stock->setBeta('1.2');
        $stock->setTotalEquity('50000000000');
        $stock->setWholesaleDebt('25000000000');
        $stock->setCorporateTreasury('15000000000');
        $stock->setRetainedEarnings('20000000000');
        $stock->setTotalRevenue('80000000000');
        $stock->setOperatingMargin('0.15');
        $stock->setTargetPayoutRatio('0.30');
        $stock->setDividendSpeed('0.10');
        $stock->setFixedCostRatio(0.50);
        $stock->setCapexRatio('0.30');

        $stock->setStructuralVariableMargin(0.15);
        $stock->setPreviousRevenue('80000000000.0000'); // $80B annualized

        // Macro boom
        $boomMacro = new MacroStateDTO(
            outputGapEma: 0.03,
            nominalGdpIndex: 1.10,
            corporateTaxRate: 0.21,
            marketVolatilityEma: 0.12,
            policyRateEma: 0.03,
            yield5yEma: 0.035,
            macroCreditSpreadEma: 0.015,
            interbankLiquiditySpreadEma: 0.001
        );

        // Run 4 consecutive quarters in a boom
        for ($q = 1; $q <= 4; $q++) {
            $events = $this->earningsEngine->calculate($stock, $boomMacro, $q);
            $eventDesc = $events[0]['description'] ?? '';
            
            // In a boom with massive profits, company must NOT be forced into emergency borrowing or dilutive stock offerings
            $this->assertStringNotContainsString('Forced to borrow', $eventDesc, "Quarter {$q} triggered false emergency borrowing during an economic boom.");
            $this->assertStringNotContainsString('emergency stock offering', $eventDesc, "Quarter {$q} triggered false emergency stock offering during an economic boom.");
        }

        // Treasury should remain healthy and positive
        $treasury = (float) $stock->getCorporateTreasury();
        $this->assertGreaterThan(5_000_000_000.0, $treasury, 'Corporate treasury was depleted during a boom.');
    }
    private function buildMatureIndustrial(string $ticker): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setIndustry('Auto Manufacturers');
        $stock->setEarningsPerShare('2.50');
        $stock->setSharesOutstanding('1000000000');
        $stock->setPrice('50.00');
        $stock->setVolatility('0.15');
        $stock->setCurrentVolatility('0.15');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('50000000000');
        $stock->setWholesaleDebt('20000000000');
        $stock->setCorporateTreasury('15000000000');
        $stock->setRetainedEarnings('10000000000');
        $stock->setTotalRevenue('80000000000');
        $stock->setPreviousRevenue('80000000000');
        $stock->setOperatingMargin('0.12');
        $stock->setTargetPayoutRatio('0.30');
        $stock->setDividendSpeed('1.00');
        $stock->setLastDividend('0.00');
        $stock->setFixedCostRatio(0.40);
        $stock->setCapexRatio('0.20');
        // Leave the structural variable cost ratio null: the engine derives it from margin and fixed cost ratio.

        return $stock;
    }

    public function testQuarterlyReportClassifiesTheDickinsonLifecycleStageFromCashFlowSigns(): void
    {
        $stock = $this->buildMatureIndustrial('LIFE');
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);

        $stageBeforeReport = $stock->getLifecycleStage();
        $this->assertNull($stageBeforeReport);

        $report = $this->earningsEngine->calculate($stock, $macroState, EarningsEngine::resolveReportingTick('LIFE', 252));
        $this->assertNotNull($report, 'the engine must report on the ticker\'s resolved reporting tick');

        $stage = $stock->getLifecycleStage();
        $this->assertInstanceOf(LifecycleStage::class, $stage);
        // A profitable, investing incumbent is Growth or Mature depending on whether it raised or returned
        // capital this quarter; it can never read as a cash-burning introduction or decline stage.
        $this->assertContains($stage, [LifecycleStage::Growth, LifecycleStage::Mature, LifecycleStage::ShakeOut]);
    }

    public function testIntroductionStageFirmsDoNotInitiateDividendsWhileMatureFirmsDo(): void
    {
        mt_srand(42); // a random deep negative demand jump can zero the payout; pin the draws
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);

        $mature = $this->buildMatureIndustrial('MATR');
        $mature->setLifecycleStage(LifecycleStage::Mature);
        $this->earningsEngine->calculate($mature, $macroState, EarningsEngine::resolveReportingTick('MATR', 252));
        $this->assertGreaterThan(0.0, (float) $mature->getLastDividend(), 'a cash-generative mature firm pays out');

        // Identical economics, but classified last quarter as a pre-profit firm funded by outside capital:
        // the life-cycle gate (Dickinson 2011) sets the target payout to zero.
        $introduction = $this->buildMatureIndustrial('INTR');
        $introduction->setLifecycleStage(LifecycleStage::Introduction);
        $this->earningsEngine->calculate($introduction, $macroState, EarningsEngine::resolveReportingTick('INTR', 252));
        $this->assertSame(0.0, (float) $introduction->getLastDividend(), 'an introduction-stage firm retains every dollar');
    }

    public function testStockCompensationIsAddedBackToCashFlowAndSettledInNewShares(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        $stock = $this->buildMatureIndustrial('SAAS');
        $stock->setIndustry('Software - Application');
        $stock->setOperatingMargin('0.25');
        $stock->setLifecycleStage(LifecycleStage::Mature);
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);

        $engine->calculate($stock, $macroState, EarningsEngine::resolveReportingTick('SAAS', 252));

        $this->assertNotNull($captured);
        $intensity = \App\Data\Sectors::getBusinessModelStrategy('tech')->getStockCompensationIntensity();
        $this->assertGreaterThan(0.05, $intensity, 'software pays a material share of revenue in equity');
        $this->assertEqualsWithDelta($captured->actualRevenue * $intensity, $captured->stockCompensation, 1.0);

        // Non-cash: the charge sits inside net income and comes straight back in operating cash flow. The
        // whole bridge is asserted, working capital included, so the add-back cannot hide behind a loose bound.
        $expectedOperatingCashFlow = $captured->actualQuarterlyNetIncome
            + $captured->quarterlyDepreciation
            + $captured->stockCompensation
            + $captured->inventoryWriteDown
            + $captured->receivablesProvision
            + $captured->deferredTaxExpense
            - $captured->deltaWorkingCapital;
        $this->assertEqualsWithDelta($expectedOperatingCashFlow, $captured->operatingCashFlow, 1.0);

        // Settled in shares: the count ends above whatever the capital allocation left it at, by SBC / price.
        $expectedShares = ((float) $captured->allocation['new_shares']) + ($captured->stockCompensation / 50.0);
        $this->assertEqualsWithDelta($expectedShares, (float) $stock->getSharesOutstanding(), 1.0);
    }

    /**
     * ASC 718: the SBC charge never leaves the company, so a quarter's equity must absorb it back as
     * paid-in capital. Before this rule the expense reduced equity while the add-back kept the cash,
     * so invested capital (equity + debt - cash) shrank by the SBC amount every single quarter.
     */
    public function testStockCompensationLeavesEquityAndInvestedCapitalWholeAcrossManyQuarters(): void
    {
        $captured = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured[] = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        $stock = $this->buildMatureIndustrial('SBCQ');
        $stock->setIndustry('Software - Application');
        $stock->setOperatingMargin('0.25');
        $stock->setLifecycleStage(LifecycleStage::Mature);
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);

        $ticksPerQuarter = 63;
        $reportingTick = EarningsEngine::resolveReportingTick('SBCQ', 252);

        $equityBefore = (float) $stock->getTotalEquity();
        $totalSbc = 0.0;
        $totalNetIncome = 0.0;
        $totalDistributions = 0.0;

        for ($quarter = 0; $quarter < 8; $quarter++) {
            $equityOpening = (float) $stock->getTotalEquity();
            $engine->calculate($stock, $macroState, ($quarter * $ticksPerQuarter) + $reportingTick, 252);

            $ctx = end($captured);
            $totalSbc += $ctx->stockCompensation;
            $totalNetIncome += $ctx->actualQuarterlyNetIncome;
            $totalDistributions += (float) $ctx->allocation['total_paid'] + (float) $ctx->allocation['total_cash_spent'];
            $this->assertGreaterThan(0.0, $ctx->stockCompensation, 'software must be granting equity every quarter');
            unset($equityOpening);
        }

        $equityAfter = (float) $stock->getTotalEquity();
        $explained = $equityBefore + $totalNetIncome + $totalSbc - $totalDistributions;

        // Clean surplus: equity is fully explained by income, the paid-in-capital credit for equity
        // compensation, and distributions. Nothing else may move book value.
        $this->assertEqualsWithDelta($explained, $equityAfter, abs($explained) * 0.02);

        // The leak this fixes was exactly the cumulative SBC, which is material over eight quarters.
        $this->assertGreaterThan(abs($equityAfter) * 0.005, $totalSbc);
    }

    /**
     * The depreciation rate reclassifies cost between the cash base and the D&A line without moving the
     * firm's structural EBIT margin: operatingMargin still means EBIT margin, which every seed, valuation
     * and solvency test depends on. What a heavier rate does change is how much EBIT swings when the plant
     * is run away from full capacity, which is the whole point of putting depreciation below the line.
     */
    public function testDepreciationRateReclassifiesCostWithoutRedefiningTheMargin(): void
    {
        $run = function (string $rate): \App\DTO\EarningsSimulationContext {
            $captured = null;
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
                $captured = $event->getContext();
            });
            $engine = $this->buildEngine($dispatcher);

            mt_srand(4242);
            $stock = $this->buildMatureIndustrial('DEPX');
            $stock->setDepreciationRate($rate);
            $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('DEPX', 252));

            return $captured;
        };

        $light = $run('0.04');
        $heavy = $run('0.10');

        $this->assertGreaterThan($light->quarterlyDepreciation, $heavy->quarterlyDepreciation);

        // The income statement bridge holds exactly on both runs.
        $this->assertEqualsWithDelta($light->quarterlyDepreciation, $light->ebitda - $light->ebit, 1.0);
        $this->assertEqualsWithDelta($heavy->quarterlyDepreciation, $heavy->ebitda - $heavy->ebit, 1.0);

        // The heavier charge is carved out of the cash cost base, so EBITDA rises by roughly the amount
        // reclassified. It is not exact: only the fixed slice carries through untouched, while the variable
        // slice is scaled by realized revenue and reshaped by cost stickiness on its way to the P&L.
        $reclassified = $heavy->structuralDepreciation - $light->structuralDepreciation;
        $this->assertEqualsWithDelta(
            $reclassified,
            $heavy->ebitda - $light->ebitda,
            $reclassified * 0.15,
            'a heavier charge must move cost out of the cash base and into depreciation'
        );

        // EBIT barely moves, because operatingMargin is an EBIT margin and the carve-out is self-cancelling
        // at normal capacity. All that survives is the gap between the scheduled charge and the realized one.
        $this->assertLessThan(
            ($heavy->ebitda - $light->ebitda) * 0.25,
            abs($heavy->ebit - $light->ebit),
            'more than doubling the depreciation rate must not redefine the firm structural EBIT margin'
        );
    }

    /**
     * Units-of-production depreciation (ASC 360) is a variable charge: the plant is consumed in proportion
     * to how hard it is actually run, so the realized charge tracks utilization rather than a fixed
     * schedule. This is what now reaches EBIT — under the old arrangement depreciation was added back on
     * top of EBIT and a utilization swing could only ever move EBITDA.
     */
    public function testDepreciationChargeTracksCapacityUtilization(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(4242);
        $stock = $this->buildMatureIndustrial('WEAR');
        $stock->setDepreciationRate('0.10');
        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('WEAR', 252));

        $this->assertGreaterThan(0.0, $captured->capacityUtilization);
        $this->assertEqualsWithDelta(
            $captured->structuralDepreciation * $captured->capacityUtilization,
            $captured->quarterlyDepreciation,
            $captured->quarterlyDepreciation * 0.001,
            'the charge must be linear in utilization'
        );

        // A plant running below normal capacity is consumed more slowly than its schedule assumes, and
        // above it, faster. Either way the deviation lands on EBIT, not on EBITDA.
        if ($captured->capacityUtilization < 1.0) {
            $this->assertLessThan($captured->structuralDepreciation, $captured->quarterlyDepreciation);
        } else {
            $this->assertGreaterThanOrEqual($captured->structuralDepreciation, $captured->quarterlyDepreciation);
        }

        $this->assertEqualsWithDelta($captured->quarterlyDepreciation, $captured->ebitda - $captured->ebit, 1.0);
    }

    /**
     * Goodwill is impairment-tested, never depreciated, and working capital does not wear out. Charging
     * depreciation on invested capital overstated it for every acquisitive or inventory-heavy firm.
     */
    public function testGoodwillIsNotDepreciated(): void
    {
        $strategy = \App\Data\Sectors::getBusinessModelStrategy('auto_manufacturer');

        $clean = $this->buildMatureIndustrial('CLEAN');
        $clean->setGrossPpe('40000000000');
        $clean->setAccumulatedDepreciation('20000000000');

        $acquisitive = $this->buildMatureIndustrial('ACQR');
        $acquisitive->setGrossPpe('40000000000');
        $acquisitive->setAccumulatedDepreciation('20000000000');
        $acquisitive->setGoodwill('30000000000');

        $this->assertEqualsWithDelta(
            $strategy->getDepreciableBase($clean),
            $strategy->getDepreciableBase($acquisitive),
            1.0,
            'a firm carrying goodwill must not depreciate more than an identical firm without it'
        );
    }

    /**
     * Construction placed in service leaves CIP and enters gross PP&E, where it starts depreciating.
     */
    public function testCompletedConstructionMovesFromCipIntoGrossPpe(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        $stock = $this->buildMatureIndustrial('CIPX');
        $stock->setGrossPpe('40000000000');
        $stock->setAccumulatedDepreciation('20000000000');
        $stock->setCipBalance('4000000000');

        $grossBefore = (float) $stock->getGrossPpe();
        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('CIPX', 252));

        $this->assertGreaterThan(0.0, $captured->completedCip, 'the CIP queue must place assets in service');
        $this->assertLessThan(4_000_000_000.0, (float) $stock->getCipBalance(), 'the CIP balance must draw down');
        $this->assertGreaterThan($grossBefore, (float) $stock->getGrossPpe(), 'completed construction must reach the plant ledger');
    }

    /**
     * Cohort retirement keeps the ledger coherent: accumulated depreciation can never exceed gross cost, and
     * a firm reinvesting at replacement holds its asset age steady instead of ageing toward a fully written
     * down plant it has in fact been replacing all along.
     */
    public function testFixedAssetLedgerStaysCoherentAndDoesNotAgeWithoutBound(): void
    {
        $engine = $this->buildEngine($this->createStub(EventDispatcherInterface::class));

        mt_srand(777);
        $stock = $this->buildMatureIndustrial('LEDG');
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);
        $reportingTick = EarningsEngine::resolveReportingTick('LEDG', 252);

        for ($quarter = 0; $quarter < 24; $quarter++) {
            $engine->calculate($stock, $macroState, ($quarter * 63) + $reportingTick, 252);

            $gross = (float) $stock->getGrossPpe();
            $accumulated = (float) $stock->getAccumulatedDepreciation();

            $this->assertGreaterThan(0.0, $gross, "gross PP&E collapsed in Q{$quarter}");
            $this->assertLessThanOrEqual($gross, $accumulated, "accumulated depreciation exceeded gross cost in Q{$quarter}");
            $this->assertGreaterThanOrEqual(0.0, $accumulated, "accumulated depreciation went negative in Q{$quarter}");
        }

        $this->assertLessThan(0.95, $stock->getAssetAge(), 'a firm reinvesting at replacement must not age to a fully written down plant');
    }

    /**
     * The ledger seeds itself on the first report, so an existing database heals without a backfill, and it
     * seeds mid-life rather than brand new: a zero-age plant would under-depreciate for years.
     */
    public function testLedgerSeedsItselfMidLifeOnTheFirstReport(): void
    {
        $engine = $this->buildEngine($this->createStub(EventDispatcherInterface::class));

        $stock = $this->buildMatureIndustrial('SEED');
        $this->assertNull($stock->getGrossPpe());

        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('SEED', 252));

        $this->assertNotNull($stock->getGrossPpe());
        $this->assertGreaterThan(0.0, $stock->getNetPpe());
        $this->assertGreaterThan(0.10, $stock->getAssetAge(), 'the opening plant must carry accumulated depreciation');
        $this->assertLessThan(0.90, $stock->getAssetAge());
    }

    /**
     * Depreciation follows a published schedule, so analysts forecast it correctly. If it were left out of
     * the consensus every quarter would open with a structural miss the size of the D&A line.
     */
    public function testDepreciationIsInsideTheAnalystExpectationSoItIsNotAStructuralMiss(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(31337);
        $stock = $this->buildMatureIndustrial('CONS');
        $stock->setDepreciationRate('0.10');
        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('CONS', 252));

        $this->assertGreaterThan(0.0, $captured->quarterlyDepreciation);

        // Expected EBIT is struck after the same depreciation charge as the actual, so the gap between them
        // stays far smaller than the charge itself rather than being dominated by it.
        $expectedGap = abs($captured->ebit - $captured->expectedEbit);
        $this->assertLessThan($captured->quarterlyDepreciation, $expectedGap);
    }

    /**
     * Replacement cost (BEA perpetual inventory method): depreciation is measured on what the plant
     * originally cost, but replacing it costs today's price. A firm whose plant was bought when prices
     * were 20% lower has to spend 1.2x its depreciation charge just to stand still.
     *
     * This is how inflation reaches the balance sheet now. It used to arrive as a straight write-up of
     * equity every quarter, which created book value with no income and no cash behind it — something US
     * GAAP never does, since plant is carried at historical cost and never revalued upward.
     */
    public function testMaintenanceCapexIsPricedAtReplacementCostNotHistoricalCost(): void
    {
        $run = function (float $deflator): \App\DTO\EarningsSimulationContext {
            $captured = null;
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
                $captured = $event->getContext();
            });
            $engine = $this->buildEngine($dispatcher);

            mt_srand(9090);
            $stock = $this->buildMatureIndustrial('RPLC');
            $stock->setGrossPpe('40000000000');
            $stock->setAccumulatedDepreciation('20000000000');
            // The plant was bought when the price level was 1.0; the macro state says what it is now.
            $stock->setPpeVintageDeflator('1.000000');

            $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, gdpDeflator: $deflator);
            $engine->calculate($stock, $macroState, EarningsEngine::resolveReportingTick('RPLC', 252), 252);

            return $captured;
        };

        $stablePrices = $run(1.00);
        $inflatedPrices = $run(1.20);

        $this->assertEqualsWithDelta(1.00, $stablePrices->replacementCostRatio, 0.0001);
        $this->assertEqualsWithDelta(1.20, $inflatedPrices->replacementCostRatio, 0.0001);

        // Same plant, same depreciation charge: only the cash cost of replacing it has moved.
        $this->assertEqualsWithDelta($stablePrices->quarterlyDepreciation, $inflatedPrices->quarterlyDepreciation, 1.0);
        $this->assertGreaterThan($stablePrices->totalReportedCapex, $inflatedPrices->totalReportedCapex);
    }

    /**
     * A firm that has never reported inherits no replacement bill for inflation that happened before it
     * existed: its plant is stamped with today's price level on the first report.
     */
    public function testVintageSeedsToTodaysPriceLevelOnTheFirstReport(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        $stock = $this->buildMatureIndustrial('VNTG');
        $this->assertNull($stock->getPpeVintageDeflator());

        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, gdpDeflator: 1.35), EarningsEngine::resolveReportingTick('VNTG', 252), 252);

        $this->assertEqualsWithDelta(1.0, $captured->replacementCostRatio, 0.0001);
        $this->assertNotNull($stock->getPpeVintageDeflator());
    }

    /**
     * A firm replacing its plant steadily pulls its vintage forward toward current prices, so its
     * replacement bill stabilizes instead of compounding forever.
     */
    public function testSteadyReinvestmentPullsThePlantVintageTowardCurrentPrices(): void
    {
        $engine = $this->buildEngine($this->createStub(EventDispatcherInterface::class));

        mt_srand(5150);
        $stock = $this->buildMatureIndustrial('VINT');
        $stock->setGrossPpe('40000000000');
        $stock->setAccumulatedDepreciation('20000000000');
        $stock->setPpeVintageDeflator('1.000000');

        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, inflationEma: 0.04, gdpDeflator: 1.40);
        $reportingTick = EarningsEngine::resolveReportingTick('VINT', 252);

        for ($quarter = 0; $quarter < 16; $quarter++) {
            $engine->calculate($stock, $macroState, ($quarter * 63) + $reportingTick, 252);
        }

        $vintage = (float) $stock->getPpeVintageDeflator();
        $this->assertGreaterThan(1.0, $vintage, 'reinvestment must carry the plant vintage forward');
        $this->assertLessThanOrEqual(1.4001, $vintage, 'the vintage can never overshoot the current price level');
    }

    /**
     * Book value must move only through earnings, paid-in capital and distributions. The engine no longer
     * writes plant up with inflation, so a high-inflation quarter with no earnings cannot manufacture equity.
     */
    public function testInflationAloneNoLongerCreatesBookValue(): void
    {
        $engine = $this->buildEngine($this->createStub(EventDispatcherInterface::class));

        mt_srand(2468);
        $stock = $this->buildMatureIndustrial('NOMR');
        $equityBefore = (float) $stock->getTotalEquity();
        $retainedBefore = (float) $stock->getRetainedEarnings();

        // 8% inflation with the price level already well above the plant's vintage.
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, inflationEma: 0.08, gdpDeflator: 1.50);
        $engine->calculate($stock, $macroState, EarningsEngine::resolveReportingTick('NOMR', 252), 252);

        $equityGrowth = (float) $stock->getTotalEquity() - $equityBefore;
        $retainedGrowth = (float) $stock->getRetainedEarnings() - $retainedBefore;

        // Whatever equity did, it was earned: the move is bounded by retained earnings plus the equity
        // compensation credit, with no revaluation term left over.
        $this->assertLessThanOrEqual(
            abs($retainedGrowth) + max(0.0, (float) $stock->getTotalRevenue()) * 0.05 + 1.0,
            abs($equityGrowth),
            'inflation must not write equity up on its own'
        );
    }

    /**
     * ASC 330, lower of cost and net realizable value: when the plant is running well below capacity the
     * goods are not moving, and stock that has to be cleared goes out below what it cost to make. The
     * charge is non-cash and analysts do not forecast it, so it lands as a miss.
     */
    public function testCollapsedDemandWritesInventoryDownAndLandsAsAMiss(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(1122);
        $stock = $this->buildMatureIndustrial('INVD');
        // Seed a ledger and a plant so the second report has balances to impair.
        $stock->setGrossPpe('40000000000');
        $stock->setAccumulatedDepreciation('20000000000');
        $stock->setReceivables('5000000000');
        $stock->setInventory('4000000000');
        $stock->setPayables('2000000000');

        // A deep depression collapses volumes far below the NRV trigger.
        $macroState = new MacroStateDTO(
            outputGapEma: -0.12,
            corporateTaxRate: 0.21,
            policyRateEma: 0.04,
            yield5yEma: 0.04,
        );

        $engine->calculate($stock, $macroState, EarningsEngine::resolveReportingTick('INVD', 252), 252);

        $this->assertLessThan(
            \App\Service\Math\FinancialConstants::INVENTORY_NRV_UTILIZATION_TRIGGER,
            $captured->capacityUtilization,
            'fixture must be running below the writedown trigger for this test to mean anything'
        );
        $this->assertGreaterThan(0.0, $captured->inventoryWriteDown, 'unsold stock must be written down to net realizable value');

        // The charge reduces reported profit, but no money moved, so the cash flow statement reconciles
        // exactly: earnings plus every non-cash charge, less the working capital the quarter tied up.
        $expectedOperatingCashFlow = $captured->actualQuarterlyNetIncome
            + $captured->quarterlyDepreciation
            + $captured->stockCompensation
            + $captured->inventoryWriteDown
            + $captured->receivablesProvision
            - $captured->deltaWorkingCapital;

        $this->assertEqualsWithDelta($expectedOperatingCashFlow, $captured->operatingCashFlow, 1.0);
    }

    /**
     * A firm running at healthy capacity is selling what it makes and has nothing to write down.
     */
    public function testHealthyDemandProducesNoInventoryWriteDown(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(3344);
        $stock = $this->buildMatureIndustrial('INVH');
        $stock->setGrossPpe('40000000000');
        $stock->setAccumulatedDepreciation('20000000000');
        $stock->setReceivables('5000000000');
        $stock->setInventory('4000000000');
        $stock->setPayables('2000000000');

        $engine->calculate($stock, new MacroStateDTO(outputGapEma: 0.02, corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('INVH', 252), 252);

        $this->assertGreaterThanOrEqual(
            \App\Service\Math\FinancialConstants::INVENTORY_NRV_UTILIZATION_TRIGGER,
            $captured->capacityUtilization / max(0.01, $captured->seasonalFactor)
        );
        $this->assertEquals(0.0, $captured->inventoryWriteDown);
    }

    /**
     * ASC 326: the credit loss allowance is a level, not a flow. A provision is booked when the expected
     * loss rate rises; once the allowance is at its target, a persistent downturn stops generating charges.
     * Modelling it as a flow would charge a firm every single quarter of a recession.
     */
    public function testCreditLossProvisionIsBookedOnDeterioratingOutlookNotEveryQuarter(): void
    {
        $captured = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured[] = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(5566);
        $stock = $this->buildMatureIndustrial('CECL');
        $stock->setGrossPpe('40000000000');
        $stock->setAccumulatedDepreciation('20000000000');
        $stock->setReceivables('5000000000');
        $stock->setInventory('4000000000');
        $stock->setPayables('2000000000');

        // A default wave arrives and then persists at the same elevated level.
        $stressed = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, corporateDefaultRateEma: 0.08);
        $reportingTick = EarningsEngine::resolveReportingTick('CECL', 252);

        $engine->calculate($stock, $stressed, $reportingTick, 252);
        $engine->calculate($stock, $stressed, $reportingTick + 63, 252);

        $this->assertGreaterThan(0.0, $captured[0]->receivablesProvision, 'the deterioration must be provisioned when it appears');
        $this->assertLessThan(
            $captured[0]->receivablesProvision,
            abs($captured[1]->receivablesProvision),
            'a persistent default rate must not re-charge the same loss every quarter'
        );
        // The allowance is a real contra-asset: what the firm expects to collect is gross less allowance.
        $allowance = (float) $stock->getReceivablesAllowance();
        $this->assertGreaterThan(0.0, $allowance);
        $this->assertEqualsWithDelta(
            (float) $stock->getReceivables() - $allowance,
            $stock->getNetReceivables(),
            1.0
        );
    }

    /**
     * Working capital is carried as the balances it is actually made of, and the net figure is derived from
     * them. Receivables track billed revenue; inventory and payables are carried at cost.
     */
    public function testWorkingCapitalIsCarriedAsRealBalancesThatDeriveTheNetFigure(): void
    {
        $engine = $this->buildEngine($this->createStub(EventDispatcherInterface::class));

        mt_srand(7788);
        $stock = $this->buildMatureIndustrial('WCAP');
        $this->assertNull($stock->getNetWorkingCapital(), 'no ledger before the first report');

        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('WCAP', 252), 252);

        $this->assertNotNull($stock->getReceivables());
        $this->assertNotNull($stock->getInventory());
        $this->assertNotNull($stock->getPayables());
        $this->assertGreaterThan(0.0, (float) $stock->getReceivables());

        $expectedNwc = $stock->getNetReceivables() + (float) $stock->getInventory() - (float) $stock->getPayables();
        $this->assertEqualsWithDelta($expectedNwc, (float) $stock->getNetWorkingCapital(), 1.0);
    }

    /**
     * The migration that introduced the working capital columns was auto-generated as a RENAME of the old
     * single net-working-capital column onto `receivables`, so an upgraded database has a net figure
     * (negative, for a float business) sitting in a gross balance with no inventory or payables beside it.
     *
     * A partial set is not a ledger. Such a row must read as unseeded so the engine rebuilds it from the
     * cash conversion cycle, rather than differencing this quarter against a number that never meant this.
     */
    public function testAPartialLedgerLeftByTheColumnRenameReadsAsUnseeded(): void
    {
        $stock = $this->buildMatureIndustrial('MIGR');
        // Exactly what the rename leaves behind: a stale net figure, nothing else.
        $stock->setReceivables('-2000000000');

        $this->assertFalse($stock->hasWorkingCapitalLedger());
        $this->assertNull($stock->getNetWorkingCapital(), 'an incomplete ledger must not present a net figure');

        $engine = $this->buildEngine($this->createStub(EventDispatcherInterface::class));
        mt_srand(2222);
        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('MIGR', 252), 252);

        // The first report rebuilds all three balances from the cycle, discarding the stale value.
        $this->assertTrue($stock->hasWorkingCapitalLedger());
        $this->assertGreaterThan(0.0, (float) $stock->getReceivables(), 'receivables are a gross balance and cannot be negative');
    }

    /**
     * A balance-sheet business holds loans and securities, not stock in a warehouse, so it must never
     * accrue a trade cycle or take a writedown on one.
     */
    public function testFinancialModelsCarryNoTradeWorkingCapital(): void
    {
        $engine = $this->buildEngine($this->createStub(EventDispatcherInterface::class));

        mt_srand(9900);
        $stock = $this->buildMatureIndustrial('BANK');
        $stock->setIndustry('Banks - Diversified');
        $stock->setCustomerDeposits('300000000000');

        $engine->calculate($stock, new MacroStateDTO(outputGapEma: -0.12, corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('BANK', 252), 252);

        $this->assertEqualsWithDelta(0.0, (float) ($stock->getInventory() ?? '0'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float) ($stock->getReceivables() ?? '0'), 0.01);
    }

    /**
     * A comfortably profitable, lightly levered industrial with a young plant: the conditions under which
     * a deferred tax balance actually builds, since a firm paying no tax has nothing to postpone.
     */
    private function buildProfitableIndustrial(string $ticker): Stock
    {
        $stock = $this->buildMatureIndustrial($ticker);
        $stock->setOperatingMargin('0.30');
        $stock->setWholesaleDebt('2000000000');
        $stock->setGrossPpe('40000000000');
        $stock->setAccumulatedDepreciation('10000000000');

        return $stock;
    }

    /**
     * ASC 740: writing an asset off faster for the tax authority than for shareholders postpones tax
     * without changing how much is ultimately owed. Cash tax therefore falls below book tax expense and
     * the difference piles up as a deferred liability — the reason capital-hungry firms pay an effective
     * cash rate well under the statutory one for years.
     */
    public function testAcceleratedTaxDepreciationDefersCashTaxAndBuildsALiability(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(1357);
        $stock = $this->buildProfitableIndustrial('DTAX'); // young plant: tax depreciation is well ahead of book

        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('DTAX', 252), 252);

        $bookTaxExpense = $captured->taxPaid;
        $this->assertGreaterThan(0.0, $bookTaxExpense, 'the fixture must be profitable for this test to mean anything');
        $this->assertGreaterThan(0.0, $captured->deferredTaxExpense, 'accelerated depreciation must postpone tax');
        $this->assertLessThan($bookTaxExpense, $captured->cashTaxPaid, 'cash tax must fall below the book charge');

        // The footnote identity: total expense is current plus deferred, always.
        $this->assertEqualsWithDelta($bookTaxExpense, $captured->cashTaxPaid + $captured->deferredTaxExpense, 1.0);
        $this->assertGreaterThan(0.0, (float) $stock->getDeferredTaxLiability());
    }

    /**
     * A timing difference moves cash, never profit. Reported earnings and EPS must be identical whether or
     * not the tax schedule is accelerated, which is precisely what makes this an ASC 740 timing difference
     * rather than a change in what the firm owes.
     */
    public function testDeferredTaxMovesCashWithoutMovingReportedEarnings(): void
    {
        $run = function (bool $withTimingDifference): \App\DTO\EarningsSimulationContext {
            $captured = null;
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
                $captured = $event->getContext();
            });
            $engine = $this->buildEngine($dispatcher);

            mt_srand(2468);
            $stock = $this->buildProfitableIndustrial('TIMD');
            // Setting the tax basis equal to book value with a zero rate would be artificial; instead the
            // no-difference case is a plant whose tax basis is already exhausted, so tax depreciation is nil.
            $stock->setPpeTaxBasis($withTimingDifference ? '30000000000' : '0');

            $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('TIMD', 252), 252);

            return $captured;
        };

        $accelerated = $run(true);
        $exhausted = $run(false);

        // Same earnings either way.
        $this->assertEqualsWithDelta($exhausted->actualQuarterlyNetIncome, $accelerated->actualQuarterlyNetIncome, 1.0);
        $this->assertEqualsWithDelta($exhausted->actualQuarterlyEps, $accelerated->actualQuarterlyEps, 0.0001);

        // Different cash.
        $this->assertLessThan($exhausted->cashTaxPaid, $accelerated->cashTaxPaid);
    }

    /**
     * A plant whose tax basis is exhausted has no shelter left: book depreciation continues while tax
     * depreciation has stopped, so the postponed tax comes back and cash tax exceeds the book charge.
     */
    public function testExhaustedTaxBasisReversesTheDeferralAndRaisesCashTax(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(8642);
        $stock = $this->buildProfitableIndustrial('RVRS');
        $stock->setPpeTaxBasis('0'); // fully written off for tax, still depreciating for shareholders
        $stock->setDeferredTaxLiability('5000000000');

        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('RVRS', 252), 252);

        $this->assertLessThan(0.0, $captured->deferredTaxExpense, 'the deferral must reverse once the shelter is gone');
        $this->assertGreaterThan($captured->taxPaid, $captured->cashTaxPaid, 'cash tax must exceed the book charge on reversal');
        $this->assertLessThan(5_000_000_000.0, (float) $stock->getDeferredTaxLiability(), 'the liability must unwind');
    }

    /**
     * Deferred tax is postponed cash, so it belongs in invested capital as an equity equivalent: the money
     * is financing the business interest free until it is paid.
     */
    public function testDeferredTaxCountsAsCapitalEmployed(): void
    {
        $withoutDeferral = $this->buildMatureIndustrial('CAPA');
        $withDeferral = $this->buildMatureIndustrial('CAPB');
        $withDeferral->setDeferredTaxLiability('5000000000');

        $this->assertEqualsWithDelta(
            $withoutDeferral->getInvestedCapital() + 5_000_000_000.0,
            $withDeferral->getInvestedCapital(),
            1.0
        );
    }

    /**
     * A balance-sheet business has no plant to depreciate on either schedule, so it accrues no deferred tax.
     */
    public function testFinancialModelsAccrueNoDeferredTax(): void
    {
        $engine = $this->buildEngine($this->createStub(EventDispatcherInterface::class));

        mt_srand(1111);
        $stock = $this->buildMatureIndustrial('FBNK');
        $stock->setIndustry('Banks - Diversified');
        $stock->setCustomerDeposits('300000000000');

        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('FBNK', 252), 252);

        $this->assertEqualsWithDelta(0.0, (float) $stock->getDeferredTaxLiability(), 0.01);
    }

    /**
     * The cash flow statement has exactly one job: its three sections must sum to the change in cash. If
     * they do not, some flow is being booked at a value other than the cash that moved (an emergency raise
     * at the screen price rather than the discounted offering price was one such case). Asserted every
     * quarter, on an industrial that invests, distributes and borrows.
     */
    public function testCashFlowStatementReconcilesToTheChangeInCashEveryQuarter(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(4242);
        $stock = $this->buildMatureIndustrial('RCNL');
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, inflationEma: 0.02, gdpDeflator: 1.0);
        $reportingTick = EarningsEngine::resolveReportingTick('RCNL', 252);

        for ($quarter = 0; $quarter < 8; $quarter++) {
            $cashBefore = (float) $stock->getCorporateTreasury();
            $debtBefore = (float) $stock->getWholesaleDebt();
            $engine->calculate($stock, $macroState, ($quarter * 63) + $reportingTick, 252);
            $cashAfter = (float) $stock->getCorporateTreasury();

            $statement = $captured->operatingCashFlow + $captured->investingCashFlow + $captured->financingCashFlow;
            $this->assertEqualsWithDelta($cashAfter - $cashBefore, $statement, 1.0, "cash flow statement failed to reconcile in Q{$quarter}");

            // And the financing section is built from cash that moved, never from a share count times a price.
            $allocation = $captured->allocation;
            $this->assertEqualsWithDelta(
                ($debtAfter = (float) $stock->getWholesaleDebt()) - $debtBefore
                    + (float) $allocation['equity_raised'] - (float) $allocation['total_cash_spent'] - (float) $allocation['total_paid'],
                $captured->financingCashFlow,
                1.0,
                "financing section is not the sum of its cash legs in Q{$quarter}"
            );
        }
    }

    /**
     * Sloan (1996): accruals are the gap between reported profit and cash from OPERATIONS. Measuring them
     * against free cash flow after capex penalized every firm that built plant, which is the opposite of
     * what the anomaly describes.
     */
    public function testAccrualsAreMeasuredAgainstOperatingCashFlowNotFreeCashFlow(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(6161);
        $stock = $this->buildProfitableIndustrial('SLOA');
        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('SLOA', 252), 252);

        $this->assertNotNull($captured);
        $lease = (new CorporateMetrics())->calculateLeaseLiability((float) $stock->getTotalRevenue(), $captured->strategy->getLeaseIntensity());
        $expected = (($captured->actualQuarterlyNetIncome - $captured->operatingCashFlow) * 4.0) / $stock->getTotalAssets($lease);

        $this->assertEqualsWithDelta($expected, (float) $stock->getAccrualsRatio(), 1e-9);

        // The old definition would have charged this quarter's capex against earnings quality.
        $oldDefinition = (($captured->actualQuarterlyNetIncome - $captured->trueQuarterlyFcf) * 4.0) / $stock->getTotalAssets($lease);
        $this->assertNotEqualsWithDelta($oldDefinition, (float) $stock->getAccrualsRatio(), 1e-9, 'capex must not be read as an accrual');
    }

    public function testAnnualImpairmentTestWritesGoodwillDownWhenReturnsTrailTheHurdle(): void
    {
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, equityRiskPremium: 0.05);
        $ticksPerQuarter = 63;

        $build = function (string $ticker): Stock {
            $stock = $this->buildMatureIndustrial($ticker);
            $stock->setGoodwill('5000000000');
            $stock->setOperatingMargin('0.03');            // acquired businesses earn well below the hurdle
            $stock->setBaselineRoic('0.02');
            $stock->setRoicTtm('0.02');
            $stock->setLifecycleStage(LifecycleStage::Mature);
            return $stock;
        };

        // Fiscal Q1: no test, goodwill untouched even though returns trail the hurdle.
        $q1 = $build('GDWL');
        $this->earningsEngine->calculate($q1, $macroState, EarningsEngine::resolveReportingTick('GDWL', 252));
        $this->assertEqualsWithDelta(5_000_000_000.0, (float) $q1->getGoodwill(), 1.0);

        // Fiscal Q4: the annual test writes the shortfall off against goodwill and equity.
        $q4 = $build('GDWL');
        $equityBefore = (float) $q4->getTotalEquity();
        $q4Tick = (EarningsEngine::FISCAL_YEAR_END_QUARTER * $ticksPerQuarter) + EarningsEngine::resolveReportingTick('GDWL', 252);
        $this->earningsEngine->calculate($q4, $macroState, $q4Tick);

        $impairment = 5_000_000_000.0 - (float) $q4->getGoodwill();
        $this->assertGreaterThan(0.0, $impairment, 'goodwill must be impaired when trailing ROIC sits below WACC');
        $this->assertLessThanOrEqual(5_000_000_000.0, $impairment);
        $this->assertLessThan($equityBefore, (float) $q4->getTotalEquity(), 'the write-down is charged against equity');
    }

    /**
     * The charge is not floored: goodwill leaves the asset side, so the equity that carried it has to leave
     * the other side by the same amount, even when that takes book equity below zero. A floor would have
     * kept equity positive and left the balance sheet out by the difference.
     */
    public function testGoodwillImpairmentCanTakeBookEquityBelowZeroAndTheSheetStillBalances(): void
    {
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, equityRiskPremium: 0.05);
        $ticksPerQuarter = 63;

        // Thin equity under a large goodwill balance: the annual test writes off more than the book is worth.
        // Debt keeps invested capital large enough that the seeded plant is real rather than the floor.
        $stock = $this->buildMatureIndustrial('NEGQ');
        $stock->setTotalEquity('3000000000');
        $stock->setWholesaleDebt('40000000000');
        $stock->setCorporateTreasury('5000000000');
        $stock->setGoodwill('5000000000');
        $stock->setOperatingMargin('0.03');
        $stock->setBaselineRoic('0.02');
        $stock->setRoicTtm('0.02');
        $stock->setLifecycleStage(LifecycleStage::Mature);

        $q4Tick = (EarningsEngine::FISCAL_YEAR_END_QUARTER * $ticksPerQuarter) + EarningsEngine::resolveReportingTick('NEGQ', 252);
        $this->earningsEngine->calculate($stock, $macroState, $q4Tick);

        $impairment = 5_000_000_000.0 - (float) $stock->getGoodwill();
        $this->assertGreaterThan(3_000_000_000.0, $impairment, 'the fixture must impair more than its book equity');
        $this->assertLessThan(0.0, (float) $stock->getTotalEquity(), 'book equity goes negative rather than being floored');

        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS['Auto Manufacturers']['business_model'] ?? 'none';
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $lease = (new CorporateMetrics())->calculateLeaseLiability((float) $stock->getTotalRevenue(), $strategy->getLeaseIntensity());
        $assets = $stock->getTotalAssets($lease);
        $claims = $stock->getTotalLiabilities($lease) + (float) $stock->getTotalEquity();
        $this->assertEqualsWithDelta($assets, $claims, max(1.0, $assets * 1e-6), 'the balance sheet still balances after the write-off');
    }

    public function testRevenueCapacityIsAnchoredToStructuralTurnoverNotTrailingRoic(): void
    {
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);

        $run = function (string $roicTtm) use ($macroState): array {
            $captured = null;
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
                $captured = $event->getContext();
            });
            $this->mathUtility = new MathUtility(); // fresh Box-Muller state so both firms see identical draws
            $engine = $this->buildEngine($dispatcher);

            $stock = $this->buildMatureIndustrial('TURN');
            $stock->setBaselineRoic('0.10');
            $stock->setRoicTtm($roicTtm);
            $stock->setLifecycleStage(LifecycleStage::Mature);

            mt_srand(1234);
            $engine->calculate($stock, $macroState, EarningsEngine::resolveReportingTick('TURN', 252));

            return [$stock, $captured];
        };

        [$healthy, $healthyCtx] = $run('0.10');
        [$squeezed, $squeezedCtx] = $run('0.02');
        $this->assertNotNull($healthyCtx);
        $this->assertNotNull($squeezedCtx);

        // DuPont identity seeds the structural turnover once: ROIC / (margin x (1 - t)).
        $expectedTurnover = 0.10 / (0.12 * (1.0 - 0.21));
        $this->assertEqualsWithDelta($expectedTurnover, (float) $healthy->getAssetTurnover(), 0.001);
        $this->assertEqualsWithDelta($expectedTurnover, (float) $squeezed->getAssetTurnover(), 0.001);

        // Invested capital is $50B + $20B - $15B = $55B, no construction is in progress, and the only other
        // scalar on structural capacity is the model's inflation pass-through (pricing power multiplier).
        $pricingPower = $this->pricingPowerMultiplier($healthy, $macroState);
        $this->assertEqualsWithDelta(55_000_000_000.0 * $expectedTurnover / 4.0 * $pricingPower, $healthyCtx->structuralRevenue, 1.0);

        // A collapsed trailing return lowers the planning return used for reinvestment, but a plant does not
        // shrink because last year was unprofitable: revenue capacity is identical for both firms.
        $this->assertLessThan($healthyCtx->baselineRoic, $squeezedCtx->baselineRoic);
        $this->assertEqualsWithDelta($healthyCtx->structuralRevenue, $squeezedCtx->structuralRevenue, 1.0);
        $this->assertEqualsWithDelta($healthyCtx->expectedRevenue, $squeezedCtx->expectedRevenue, 1.0);
    }

    public function testStructuralTurnoverPersistsThroughMarginDrift(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        $stock = $this->buildMatureIndustrial('HOLD');
        $stock->setAssetTurnover('1.6000');
        $stock->setOperatingMargin('0.30'); // modernization drift must not re-derive the turnover
        $stock->setLifecycleStage(LifecycleStage::Mature);

        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);
        $engine->calculate($stock, $macroState, EarningsEngine::resolveReportingTick('HOLD', 252));

        $this->assertNotNull($captured);
        $this->assertEqualsWithDelta(1.6, (float) $stock->getAssetTurnover(), 1e-6);
        $pricingPower = $this->pricingPowerMultiplier($stock, $macroState);
        $this->assertEqualsWithDelta(55_000_000_000.0 * 1.6 / 4.0 * $pricingPower, $captured->structuralRevenue, 1.0);
    }

    public function testTrailingEarningsSumTheLastFourReportedQuartersInsteadOfSmoothingThem(): void
    {
        $reported = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$reported): void {
            $reported[] = $event->getContext()->reportedActualNetIncome;
        });
        $engine = $this->buildEngine($dispatcher);

        $stock = $this->buildMatureIndustrial('TTMX');
        $stock->setLifecycleStage(LifecycleStage::Mature);
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);

        mt_srand(4242);
        $reportingTick = EarningsEngine::resolveReportingTick('TTMX', 252);

        // A full year plus two quarters, so the window has rolled past its seed.
        for ($quarter = 0; $quarter < 6; $quarter++) {
            $engine->calculate($stock, $macroState, $reportingTick + (63 * $quarter));
        }

        $this->assertCount(6, $reported);

        $history = $stock->getQuarterlyNetIncomeHistory();
        $this->assertIsArray($history);
        $this->assertCount(EarningsEngine::TTM_QUARTERS, $history, 'The window holds exactly four quarters.');

        $lastFour = array_slice($reported, -EarningsEngine::TTM_QUARTERS);
        foreach ($lastFour as $index => $quarterlyIncome) {
            $this->assertEqualsWithDelta($quarterlyIncome, (float) $history[$index], 1.0);
        }

        // The headline figure is the sum of those four prints. Because it is a sum, a collapse in any single
        // quarter reaches reported earnings at a full quarter's weight and leaves the window a year later.
        // The filter this replaced had a Kalman gain near 0.036, giving the headline EPS a half-life of
        // roughly nineteen quarters: the P/E on the screener lagged the business by years.
        $this->assertEqualsWithDelta(
            array_sum($lastFour),
            (float) $stock->getTotalNetIncome(),
            1.0,
            'Trailing net income must equal the sum of the last four reported quarters.'
        );

        // EPS is derived from that figure over current shares, so the screener reads real trailing earnings.
        $this->assertEqualsWithDelta(
            array_sum($lastFour) / (float) $stock->getSharesOutstanding(),
            (float) $stock->getEarningsPerShare(),
            1e-6
        );
    }

    public function testFirstReportSeedsTrailingEarningsFromTheDeseasonalizedRunRate(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        $stock = $this->buildMatureIndustrial('SEED');
        $stock->setLifecycleStage(LifecycleStage::Mature);
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);

        mt_srand(99);
        $engine->calculate($stock, $macroState, EarningsEngine::resolveReportingTick('SEED', 252));

        $this->assertNotNull($captured);

        // With no history the window is seeded from the seasonally adjusted annual run-rate rather than four
        // copies of the quarter that happened to report first, so the opening figure carries no seasonality.
        $history = $stock->getQuarterlyNetIncomeHistory();
        $this->assertCount(EarningsEngine::TTM_QUARTERS, $history);

        // Share count is taken from the report itself: buybacks settled later in the same quarter would
        // otherwise make the reconstruction disagree with the figure the engine actually seeded.
        $seededRunRate = $captured->actualAnnualEpsRaw * $captured->sharesOutstanding / 4.0;
        foreach (array_slice($history, 0, 3) as $seededQuarter) {
            $this->assertEqualsWithDelta($seededRunRate, (float) $seededQuarter, 1.0);
        }

        // The newest slot is the quarter actually reported, seasonality and all.
        $this->assertEqualsWithDelta($captured->reportedActualNetIncome, (float) $history[3], 1.0);
    }

    public function testSurpriseIsStandardizedByTheFirmsOwnSurpriseHistory(): void
    {
        $engine = $this->buildEngine($this->createStub(EventDispatcherInterface::class));

        $stock = $this->buildMatureIndustrial('SUEX');
        $stock->setLifecycleStage(LifecycleStage::Mature);
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);

        mt_srand(555);
        $reportingTick = EarningsEngine::resolveReportingTick('SUEX', 252);

        $this->assertSame([], $stock->getEarningsSurpriseHistory());

        for ($quarter = 0; $quarter < 12; $quarter++) {
            $engine->calculate($stock, $macroState, $reportingTick + (63 * $quarter));
        }

        $history = $stock->getEarningsSurpriseHistory();
        $this->assertIsArray($history);

        // The sample is a rolling window, so an old regime eventually leaves the denominator.
        $this->assertCount(EarningsEngine::SUE_HISTORY_QUARTERS, $history);

        // A firm whose surprises are chronically large must end up with a correspondingly large denominator,
        // otherwise every ordinary quarter reads as a multi-sigma event and the volatility shock never stops
        // firing. Measured over forty quarters the static sector constants understated the realized surprise
        // scale by between 1.5 and 5.8 times, which pinned most of the district's volatility near its ceiling.
        $realizedScale = (new MathUtility())->calculateMeanAbsoluteScale(array_map('floatval', $history));
        $meanAbsoluteSurprise = array_sum(array_map('abs', $history)) / count($history);

        $this->assertGreaterThan(0.0, $realizedScale);
        $this->assertGreaterThan(
            $meanAbsoluteSurprise,
            $realizedScale,
            'The normal-equivalent scale must exceed the mean absolute surprise it is derived from.'
        );

        // A typical quarter for this firm must not register as a sigma event against its own history.
        $this->assertLessThan(
            1.5,
            $meanAbsoluteSurprise / $realizedScale,
            'An average quarter must not trip the volatility shock threshold.'
        );
    }

    public function testMeanAbsoluteScaleRecoversSigmaAndResistsASingleOutlier(): void
    {
        $math = new MathUtility();

        // A zero-mean normal sample: the estimator must recover its sigma.
        mt_srand(2468);
        $sample = [];
        for ($i = 0; $i < 4000; $i++) {
            $sample[] = $math->generateStandardNormal() * 0.20;
        }
        $this->assertEqualsWithDelta(0.20, $math->calculateMeanAbsoluteScale($sample), 0.01);

        // One catastrophic quarter must not swamp the scale the way a sum of squares would.
        $ordinary = array_fill(0, 7, 0.05);
        $withOutlier = array_merge($ordinary, [2.0]);

        $robust = $math->calculateMeanAbsoluteScale($withOutlier);
        $sumOfSquares = sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $withOutlier)) / count($withOutlier));

        $this->assertLessThan($sumOfSquares, $robust);
        $this->assertEqualsWithDelta(0.0, $math->calculateMeanAbsoluteScale([]), 1e-12);
    }

    private function pricingPowerMultiplier(Stock $stock, MacroStateDTO $macroState): float
    {
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$stock->getIndustry()]['business_model'] ?? 'none';

        return \App\Data\Sectors::getBusinessModelStrategy($businessModel)->getMacroPhysics($stock, $macroState)['pricing_power_multiplier'];
    }

    /**
     * A bank's first report opens the earning-asset ledger: the loan book is a balance from then on, with an
     * allowance for the losses already expected sitting against it, and the quarter's provision, charge-offs
     * and originations are all real entries. Expansion cash goes onto the book, never into construction,
     * and the sheet balances to the dollar with every deposit, borrowing and share claimed.
     */
    public function testBankOpensAnEarningAssetLedgerAndItsBalanceSheetBalances(): void
    {
        $macroState = new MacroStateDTO(
            corporateTaxRate: 0.21, policyRate: 0.04, policyRateEma: 0.04, yield2yEma: 0.04, yield5yEma: 0.042,
            yield10yEma: 0.045, equityRiskPremium: 0.05, nominalGdpIndex: 1.0, macroCreditSpreadEma: 0.015
        );

        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        $stock = new Stock();
        $stock->setTicker('LEND');
        $stock->setIndustry('Banks - Diversified');
        $stock->setEarningsPerShare('2.50');
        $stock->setSharesOutstanding('1000000000');
        $stock->setPrice('50.00');
        $stock->setVolatility('0.15');
        $stock->setCurrentVolatility('0.15');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('40000000000');
        $stock->setWholesaleDebt('10000000000');
        $stock->setCustomerDeposits('300000000000');
        $stock->setCorporateTreasury('5000000000');
        $stock->setRetainedEarnings('10000000000');
        $stock->setBaselineRoe('0.12');
        $stock->setRoeTtm('0.12');
        $stock->setOperatingMargin('0.35');
        $stock->setTargetPayoutRatio('0.30');
        $stock->setDividendSpeed('0.20');
        $stock->setDepreciationRate('0.02');
        $this->assertFalse($stock->hasEarningAssetLedger());

        mt_srand(20260909);
        $engine->calculate($stock, $macroState, EarningsEngine::resolveReportingTick('LEND', 252));
        $this->assertNotNull($captured);

        $strategy = \App\Data\Sectors::getBusinessModelStrategy('commercial_bank');
        $this->assertTrue($stock->hasEarningAssetLedger(), 'the first report opens the loan book');
        $this->assertNull($stock->getGrossPpe(), 'a bank never opens a plant ledger');
        $this->assertEqualsWithDelta(0.0, (float) $stock->getCipBalance(), 1.0, 'loans are originated, not queued as construction');

        $book = (float) $stock->getEarningAssets();
        $allowance = (float) $stock->getCreditLossAllowance();
        $lifetimeRate = $strategy->getThroughTheCycleCreditLossRate() * $strategy->getCreditLossHorizonYears();
        $this->assertGreaterThan(300_000_000_000.0, $book, 'the book is the funding deployed away from cash');
        $this->assertGreaterThan(0.0, $lifetimeRate);
        $this->assertEqualsWithDelta($lifetimeRate, $allowance / $book, $lifetimeRate * 0.25, 'the allowance opens at its lifetime target and stays near it');

        // The quarter's credit entries: the through-the-cycle charge alone is a positive provision, and
        // what went bad is the conditional loss on the book, never more than the book.
        $this->assertGreaterThan(0.0, $captured->creditLossProvision);
        $this->assertGreaterThanOrEqual(0.0, $captured->netChargeOffs);
        $this->assertLessThan($book, $captured->netChargeOffs);
        $this->assertTrue(is_finite($captured->netLoanOriginations));

        $lease = (new CorporateMetrics())->calculateLeaseLiability((float) $stock->getTotalRevenue(), $strategy->getLeaseIntensity());
        $assets = $stock->getTotalAssets($lease);
        $claims = $stock->getTotalLiabilities($lease) + (float) $stock->getTotalEquity();
        $this->assertEqualsWithDelta($assets, $claims, max(1.0, $assets * 1e-6), 'a bank balance sheet balances');

        // The three cash flow sections still sum to the cash that moved, deposits included.
        $this->assertEqualsWithDelta(
            (float) $stock->getCorporateTreasury() - 5_000_000_000.0,
            $captured->operatingCashFlow + $captured->investingCashFlow + $captured->financingCashFlow,
            max(1.0, abs($assets) * 1e-6),
            'operating, investing and financing flows reconcile to the change in cash'
        );
    }

    /**
     * A lender whose credit physics is a margin shock reports no dollar charge-offs. Its allowance must still
     * hold at the lifetime target rather than ratchet up on a charge nothing consumes, and the provision it
     * reports is the through-the-cycle loss on its book.
     */
    public function testCardLenderAllowanceHoldsAtItsLifetimeTargetWithoutDollarChargeOffs(): void
    {
        $macroState = new MacroStateDTO(
            corporateTaxRate: 0.21, policyRate: 0.04, policyRateEma: 0.04, yield2yEma: 0.04, yield5yEma: 0.042,
            yield10yEma: 0.045, equityRiskPremium: 0.05, nominalGdpIndex: 1.0, macroCreditSpreadEma: 0.015
        );
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        $stock = $this->buildCardLender('CARD');

        $strategy = \App\Data\Sectors::getBusinessModelStrategy('credit_services');
        $lifetimeRate = $strategy->getThroughTheCycleCreditLossRate() * $strategy->getCreditLossHorizonYears();

        mt_srand(20260909);
        for ($quarter = 1; $quarter <= 4; $quarter++) {
            $engine->calculate($stock, $macroState, (($quarter - 1) * 63) + EarningsEngine::resolveReportingTick('CARD', 252));
            $this->assertNotNull($captured);
            $book = (float) $stock->getEarningAssets();
            $this->assertEqualsWithDelta($lifetimeRate, (float) $stock->getCreditLossAllowance() / $book, $lifetimeRate * 0.10, "allowance drifted from target in Q{$quarter}");
            $this->assertEqualsWithDelta($captured->netChargeOffs, $strategy->getThroughTheCycleCreditLossRate() / 4.0 * ($book + $captured->netChargeOffs), $captured->netChargeOffs * 0.05, 'realized losses run at the through-the-cycle rate');
        }
        $this->assertGreaterThan(0.04, $lifetimeRate, 'a card book reserves several percent of receivables');
    }

    /**
     * A lender's provision is a forecast line, not a surprise. The through-the-cycle charge on a disclosed
     * book is exactly the replenishment the allowance roll-forward books at steady state, so analysts carry
     * it in the estimate and only the cyclical excess the sector physics adds on top can surprise. An
     * operating company keeps no loan book and carries nothing.
     */
    public function testAnalystsCarryTheThroughTheCycleProvisionOfALender(): void
    {
        $macroState = new MacroStateDTO(
            corporateTaxRate: 0.21, policyRate: 0.04, policyRateEma: 0.04, yield2yEma: 0.04, yield5yEma: 0.042,
            yield10yEma: 0.045, equityRiskPremium: 0.05, nominalGdpIndex: 1.0, macroCreditSpreadEma: 0.015
        );
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        $lender = $this->buildCardLender('CARD');
        $strategy = \App\Data\Sectors::getBusinessModelStrategy('credit_services');

        mt_srand(20260912);
        $engine->calculate($lender, $macroState, EarningsEngine::resolveReportingTick('CARD', 252));
        $this->assertNotNull($captured);

        $openingBook = (float) $lender->getEarningAssets() + $captured->netChargeOffs;
        $this->assertEqualsWithDelta(
            $strategy->getThroughTheCycleCreditLossRate() / 4.0 * $openingBook,
            $captured->expectedCreditLossProvision,
            $captured->expectedCreditLossProvision * 0.05,
            'the estimate carries one quarter of the through-the-cycle charge on the opening book'
        );
        $this->assertGreaterThan(0.0, $captured->expectedCreditLossProvision);

        $industrial = $this->buildProfitableIndustrial('PLNT');
        $engine->calculate($industrial, $macroState, EarningsEngine::resolveReportingTick('PLNT', 252));
        $this->assertSame('PLNT', $captured->stock->getTicker());
        $this->assertSame(0.0, $captured->expectedCreditLossProvision, 'no loan book, no provision in the estimate');
    }

    /**
     * The regression that matters: a card issuer in a calm macro used to miss consensus every quarter and
     * report a fraction of its target ROE, because neither the revenue target nor the estimate funded the
     * charge-offs the ledger books. With both carrying it, the surprises are noise around zero and the
     * trailing ROE stays near the target.
     */
    public function testCardLenderEarnsItsTargetRoeAndDoesNotMissEveryQuarterInACalmMacro(): void
    {
        $macroState = new MacroStateDTO(
            corporateTaxRate: 0.21, policyRate: 0.04, policyRateEma: 0.04, yield2yEma: 0.04, yield5yEma: 0.042,
            yield10yEma: 0.045, equityRiskPremium: 0.05, nominalGdpIndex: 1.0, macroCreditSpreadEma: 0.015
        );
        $engine = $this->buildEngine(new EventDispatcher());
        $stock = $this->buildCardLender('CARD');
        $targetRoe = (float) $stock->getBaselineRoe();

        mt_srand(20260912);
        for ($quarter = 1; $quarter <= 8; $quarter++) {
            $engine->calculate($stock, $macroState, (($quarter - 1) * 63) + EarningsEngine::resolveReportingTick('CARD', 252));
        }

        $surprises = $stock->getEarningsSurpriseHistory() ?? [];
        $this->assertCount(8, $surprises);
        $meanSurprise = array_sum($surprises) / count($surprises);
        $this->assertGreaterThan(-0.10, $meanSurprise, 'a lender in a calm macro must not miss consensus chronically');
        $this->assertLessThan(0.15, $meanSurprise);

        $this->assertGreaterThan($targetRoe * 0.60, (float) $stock->getRoeTtm(), 'trailing ROE must land near the target, not the target minus the loss rate');
    }

    /**
     * A forward reserve build is booked once and released once (ASC 326). The forecast multiplier moves the
     * lifetime loss target the allowance converges to: the first stressed quarter carries a build on top of
     * the through-the-cycle charge, later stressed quarters converge back to that charge alone, and the
     * quarter the outlook clears carries a release. Charging the level every quarter, as the margin used
     * to, kept a card issuer loss-making for as long as the recession probability stayed elevated.
     */
    public function testForwardReserveIsBuiltOnceAndReleasedOnceRatherThanChargedEveryQuarter(): void
    {
        $calm = new MacroStateDTO(
            corporateTaxRate: 0.21, policyRate: 0.04, policyRateEma: 0.04, yield2yEma: 0.04, yield5yEma: 0.042,
            yield10yEma: 0.045, equityRiskPremium: 0.05, nominalGdpIndex: 1.0, macroCreditSpreadEma: 0.013,
            recessionProbabilityEma: 0.15
        );
        $stressed = new MacroStateDTO(
            corporateTaxRate: 0.21, policyRate: 0.04, policyRateEma: 0.04, yield2yEma: 0.04, yield5yEma: 0.042,
            yield10yEma: 0.045, equityRiskPremium: 0.05, nominalGdpIndex: 1.0, macroCreditSpreadEma: 0.013,
            recessionProbabilityEma: 0.65
        );

        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);
        $stock = $this->buildCardLender('CARD');
        $strategy = \App\Data\Sectors::getBusinessModelStrategy('credit_services');

        $excessProvision = [];
        $regimes = [$calm, $calm, $stressed, $stressed, $stressed, $stressed, $calm, $calm];
        mt_srand(20260912);
        foreach ($regimes as $i => $macro) {
            $engine->calculate($stock, $macro, ($i * 63) + EarningsEngine::resolveReportingTick('CARD', 252));
            $this->assertNotNull($captured);
            // Provision over and above the through-the-cycle charge on the opening book: the build or release.
            $ttcCharge = $strategy->getThroughTheCycleCreditLossRate() / 4.0 * ((float) $stock->getEarningAssets() + $captured->netChargeOffs);
            $excessProvision[$i] = $captured->creditLossProvision - $ttcCharge;
        }

        $book = (float) $stock->getEarningAssets();
        $this->assertEqualsWithDelta(0.0, $excessProvision[1], $book * 0.001, 'calm and converged: provision is the through-the-cycle charge');
        $this->assertGreaterThan($book * 0.002, $excessProvision[2], 'the first stressed quarter books a reserve build');
        // The build walks the gap closed at CREDIT_ALLOWANCE_CONVERGENCE_RATIO a quarter, so each stressed
        // quarter charges less than the one before and the fourth is well under the first.
        $this->assertGreaterThan($excessProvision[3], $excessProvision[2]);
        $this->assertGreaterThan($excessProvision[4], $excessProvision[3]);
        $this->assertGreaterThan($excessProvision[5], $excessProvision[4]);
        $this->assertLessThan($excessProvision[2] * 0.60, $excessProvision[5], 'a persistent outlook is not charged again once the reserve is built');
        $this->assertLessThan(-$book * 0.002, $excessProvision[6], 'the quarter the outlook clears books a release');

        $lifetimeRate = $strategy->getThroughTheCycleCreditLossRate() * $strategy->getCreditLossHorizonYears();
        $stressedTarget = $lifetimeRate * $strategy->getForwardCreditLossMultiplier($stock, $stressed);
        $this->assertGreaterThan($lifetimeRate * 1.2, $stressedTarget, 'a 65% recession probability materially raises the lifetime loss estimate');
    }

    private function buildCardLender(string $ticker): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setIndustry('Credit Services');
        $stock->setEarningsPerShare('2.50');
        $stock->setSharesOutstanding('1000000000');
        $stock->setPrice('50.00');
        $stock->setVolatility('0.15');
        $stock->setCurrentVolatility('0.15');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('120000000000');
        $stock->setWholesaleDebt('48000000000');
        $stock->setCustomerDeposits('850000000000');
        $stock->setCorporateTreasury('90000000000');
        $stock->setRetainedEarnings('60000000000');
        $stock->setBaselineRoe('0.25');
        $stock->setRoeTtm('0.25');
        $stock->setOperatingMargin('0.35');
        $stock->setTargetPayoutRatio('0.30');
        $stock->setDividendSpeed('0.20');
        $stock->setDepreciationRate('0.05');

        return $stock;
    }

    /**
     * The credit provision a lender reports has to be the provision it actually charged.
     *
     * The roll-forward used to carry a third term — a through-the-cycle charge on the gross book, on the
     * grounds that the stable cost base already contained it. It did not: the cost base is the
     * operating-margin complement of revenue and has no term that scales with the loan book, so no
     * earnings figure moved with it. The full sum was nonetheless added back to operating cash flow as a
     * non-cash charge, which converted roughly 20bps of the book into cash every quarter out of nothing —
     * measured at up to 12.8x quarterly net income. The balance sheet stayed square through it, because
     * cash rose by exactly what the book lost, which is why nothing caught it.
     *
     * The invariant that does catch it: the provision added back to cash equals the fall in EBIT it caused.
     */
    public function testReportedCreditProvisionEqualsTheChargeThatActuallyReachedEbit(): void
    {
        $macroState = new MacroStateDTO(
            corporateTaxRate: 0.21, policyRate: 0.04, policyRateEma: 0.04, yield2yEma: 0.04, yield5yEma: 0.042,
            yield10yEma: 0.045, equityRiskPremium: 0.05, nominalGdpIndex: 1.0, macroCreditSpreadEma: 0.015
        );
        $captured = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured[] = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);
        $stock = $this->buildCardLender('PROV');

        mt_srand(20260910);
        for ($quarter = 1; $quarter <= 8; $quarter++) {
            $openingBook = (float) $stock->getEarningAssets();
            $openingAllowance = (float) $stock->getCreditLossAllowance();

            $engine->calculate($stock, $macroState, (($quarter - 1) * 63) + EarningsEngine::resolveReportingTick('PROV', 252));
            $ctx = end($captured);
            $this->assertNotNull($ctx);

            // The ledger identity: net earning assets fall by exactly the provision recognised. Charge-offs
            // move gross book and allowance together and cost earnings nothing, so they cancel out here.
            if ($openingBook > 0.0) {
                $closingNetBook = (float) $stock->getEarningAssets() - (float) $stock->getCreditLossAllowance();
                $openingNetBook = $openingBook - $openingAllowance;
                $this->assertEqualsWithDelta(
                    -$ctx->creditLossProvision + $ctx->netLoanOriginations,
                    $closingNetBook - $openingNetBook,
                    max(1.0, abs($ctx->creditLossProvision) * 1e-6),
                    "Q{$quarter}: the net book must move by originations less the provision, and nothing else."
                );
            }

            // The one that catches the phantom charge. A card lender's credit physics is a loss RATE, not a
            // margin shock, so it emits no explicit provision of its own: every dollar it reports must
            // therefore be the level charge that was struck against EBIT, and it keeps no trade cycle, so
            // nothing else contributes to the impairment total. Any excess is a charge added back to cash
            // that no earnings figure ever paid for.
            $this->assertGreaterThan(0.0, $ctx->creditLossProvision, "Q{$quarter}: a live loan book provisions something.");
            $this->assertEqualsWithDelta(
                $ctx->impairmentCharges,
                $ctx->creditLossProvision,
                max(1.0, abs($ctx->creditLossProvision) * 1e-9),
                "Q{$quarter}: the provision added back to cash must be the provision charged to EBIT."
            );
        }
    }

    /**
     * A quarter that writes inventory down to net realizable value or provisions against its receivables
     * has to report the margin it actually earned.
     *
     * The seasonally adjusted run-rate is rebuilt from revenue and cost ratios rather than deseasonalized
     * from EBIT, and it used to start and stop at the operating cost base — so every impairment struck
     * against EBIT afterwards was invisible to it. That figure is persisted as reportedOperatingMargin and
     * handed to the spread and solvency tests, so a firm eating a ten-point margin hit still borrowed at an
     * unimpaired firm's rate: exactly the "collapse priced quarters late" the field exists to prevent.
     */
    public function testImpairmentsReachThePersistedStructuralMargin(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        // A deep demand shortfall: utilization well under the NRV trigger, and a default outlook that
        // forces a receivable provision.
        $macroState = new MacroStateDTO(
            corporateTaxRate: 0.21, outputGapEma: -0.08, policyRate: 0.01, policyRateEma: 0.01,
            yield2yEma: 0.04, yield5yEma: 0.02, yield10yEma: 0.01, nominalGdpIndex: 0.90,
            marketVolatilityEma: 0.45, macroCreditSpreadEma: 0.07, corporateDefaultRateEma: 0.08
        );

        $stock = $this->buildMatureIndustrial('IMPR');

        mt_srand(20260910);
        $sawACharge = false;
        for ($quarter = 1; $quarter <= 8; $quarter++) {
            $engine->calculate($stock, $macroState, (($quarter - 1) * 63) + EarningsEngine::resolveReportingTick('IMPR', 252));
            $this->assertNotNull($captured);

            if ($captured->impairmentCharges <= 1.0) {
                continue;
            }
            $sawACharge = true;

            // Rebuild the run-rate the way the engine does — from the deseasonalized revenue and the
            // realized cost ratio — and assert the impairments came off it. Drop the subtraction and this
            // is the figure the solvency tests would read instead.
            $costRatio = $captured->actualRevenue > 0.0
                ? $captured->actualVariableCosts / $captured->actualRevenue
                : $captured->realizedVariableMargin;
            $beforeCharges = ($captured->seasonallyAdjustedRevenue * (1.0 - $costRatio))
                - $captured->fixedCosts
                - $captured->quarterlyDepreciation;

            $this->assertEqualsWithDelta(
                $beforeCharges - $captured->impairmentCharges,
                $captured->seasonallyAdjustedEbit,
                max(1.0, abs($beforeCharges) * 1e-9),
                "Q{$quarter}: the seasonally adjusted run-rate must be struck after impairments."
            );
            $this->assertEqualsWithDelta(
                $captured->seasonallyAdjustedEbit / max(1.0, $captured->seasonallyAdjustedRevenue),
                (float) $stock->getReportedOperatingMargin(),
                1e-9,
                "Q{$quarter}: and that impaired run-rate is what gets persisted for the spread and solvency tests."
            );
            $this->assertEqualsWithDelta(
                $captured->inventoryWriteDown + $captured->receivablesProvision,
                $captured->impairmentCharges,
                1.0,
                "Q{$quarter}: an operating company's impairments are the write-down and the receivable provision."
            );
        }

        $this->assertTrue($sawACharge, 'The scenario must actually trigger an impairment for this to test anything.');
    }

    /**
     * ASC 330: a write-down lowers the carrying value of the stock and moves no cash. It used to cut the
     * gross balance, which the trade-cycle rebuild restored the same quarter, so the restoration was booked
     * as a working capital build the firm paid cash for and the sheet showed no impairment at all. Held as
     * an allowance, the carrying value stays lower until the impaired stock turns; the replacement cash
     * leaves then, through the working capital build, with no second pass through earnings.
     */
    public function testInventoryWriteDownIsHeldAsAnAllowanceAndReplacedOnlyAsTheStockTurns(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(1122);
        $stock = $this->buildMatureIndustrial('NRVA');
        $stock->setGrossPpe('40000000000');
        $stock->setAccumulatedDepreciation('20000000000');
        $stock->setReceivables('5000000000');
        $stock->setInventory('4000000000');
        $stock->setPayables('2000000000');
        $reportingTick = EarningsEngine::resolveReportingTick('NRVA', 252);

        $grossNwc = static fn (Stock $s): float => (float) $s->getReceivables() + (float) $s->getInventory() - (float) $s->getPayables();

        // Quarter 1: a depression collapses volumes below the NRV trigger.
        $depression = new MacroStateDTO(outputGapEma: -0.12, corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);
        $grossBefore = $grossNwc($stock);
        $engine->calculate($stock, $depression, $reportingTick, 252);

        $writeDown = $captured->inventoryWriteDown;
        $this->assertGreaterThan(0.0, $writeDown, 'fixture must trigger a write-down for this test to mean anything');

        $allowance = (float) $stock->getInventoryAllowance();
        $this->assertEqualsWithDelta($writeDown, $allowance, 1.0, 'the whole charge is still held against the stock: nothing impaired this quarter has turned yet');
        $this->assertEqualsWithDelta((float) $stock->getInventory() - $allowance, $stock->getNetInventory(), 0.01, 'the carrying value is cost less the write-down');
        $this->assertEqualsWithDelta(
            $grossNwc($stock) - $grossBefore,
            $captured->deltaWorkingCapital,
            1.0,
            'the write-down is non-cash: the working capital build is the change in the gross trade balances alone'
        );

        // Quarter 2: demand recovers, the impaired stock turns, and its replacement is what costs cash.
        $recovery = new MacroStateDTO(outputGapEma: 0.02, corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);
        $allowanceOpening = (float) $stock->getInventoryAllowance();
        $grossBefore = $grossNwc($stock);
        $engine->calculate($stock, $recovery, 63 + $reportingTick, 252);

        $allowanceClosing = (float) $stock->getInventoryAllowance();
        $this->assertLessThan($allowanceOpening, $allowanceClosing, 'the allowance unwinds as the written-down stock turns');
        $released = $allowanceOpening + $captured->inventoryWriteDown - $allowanceClosing;
        $this->assertEqualsWithDelta(
            ($grossNwc($stock) - $grossBefore) + $released,
            $captured->deltaWorkingCapital,
            1.0,
            'replacing the cleared stock is a working capital build: the cash the write-down foretold leaves now'
        );
    }

    /**
     * Overtime is what a firm pays when demand runs past the capacity it keeps for the calendar's own peak.
     * Measured against raw utilization, which carries the seasonal factor, every fourth quarter of a retailer
     * running at 1.35x paid a twelve-point convex penalty at exactly structural demand.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testOvertimePenaltyIsMeasuredAgainstDeseasonalizedUtilization(): void
    {
        $shocks = [];
        $mathUtility = $this->getMockBuilder(MathUtility::class)->onlyMethods(['calculateConvexPenalty'])->getMock();
        $mathUtility->method('calculateConvexPenalty')->willReturnCallback(
            function (float $shock, float $convexity = 1.5, float $scalar = 1.0) use (&$shocks): float {
                $shocks[] = $shock;

                return $shock <= 0.0 ? 0.0 : pow($shock, $convexity) * $scalar;
            }
        );
        $this->mathUtility = $mathUtility;

        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(7);
        $stock = $this->buildMatureIndustrial('XMAS');
        $stock->setIndustry('Internet Retail'); // Q4 seasonal factor 1.35
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);

        $engine->calculate($stock, $macroState, (3 * 63) + EarningsEngine::resolveReportingTick('XMAS', 252), 252);

        $this->assertNotNull($captured);
        $this->assertGreaterThan(1.2, $captured->seasonalFactor, 'the report has to land in the holiday quarter for this to test anything');
        $this->assertNotEmpty($shocks);
        // The engine's overtime call is the first convex penalty struck in a report (the margin process runs
        // before the sector physics), and it must see the run rate with the calendar taken out.
        $this->assertEqualsWithDelta(
            ($captured->capacityUtilization / $captured->seasonalFactor) - EarningsEngine::CAPACITY_OVERTIME_THRESHOLD,
            $shocks[0],
            1e-9,
            'overtime is the overshoot of the deseasonalized run rate, not of the seasonal peak'
        );
    }
}
