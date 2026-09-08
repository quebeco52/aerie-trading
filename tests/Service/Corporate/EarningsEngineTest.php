<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Service\Event\EarningsReportedEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
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

        // Non-cash: operating cash flow carries the add-back above the accounting profit line.
        $this->assertGreaterThan(
            $captured->actualQuarterlyNetIncome + $captured->quarterlyDepreciation - $captured->stockCompensation,
            $captured->operatingCashFlow
        );

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

}
