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

    private function pricingPowerMultiplier(Stock $stock, MacroStateDTO $macroState): float
    {
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$stock->getIndustry()]['business_model'] ?? 'none';

        return \App\Data\Sectors::getBusinessModelStrategy($businessModel)->getMacroPhysics($stock, $macroState)['pricing_power_multiplier'];
    }

}
