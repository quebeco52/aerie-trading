<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Data\Company\LifecycleStage;
use App\Data\Company\Sectors;
use App\Data\Macro\CapitalGoodsPrices;
use App\DTO\DebtHealthDTO;
use App\DTO\EarningsSimulationContext;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\CorporateLedgerService;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Industry\InMemoryIndustryShareStore;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Event\EarningsReportedEvent;
use App\Service\Event\MarketEventPublisher;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Market\Pricing\MarketConsensusEngine;
use App\Service\Math\Distributions;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\ResponseCurves;
use App\Service\Model\Sector\AssetManagementBusinessModel;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\HomebuilderBusinessModel;
use App\Service\Model\Sector\TechBusinessModel;
use App\Service\News\NarrativeEngine;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
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

    private function buildEngine(EventDispatcherInterface $eventDispatcher, ?IndustryShareLedger $industryShareLedger = null, ?DebtEngine $debtEngine = null): EarningsEngine
    {
        $corporateMetrics = new CorporateMetrics();
        $debtEngine ??= new DebtEngine($this->mathUtility, $corporateMetrics);
        $capExEngine = new CapExEngine();
        $marketConsensusEngine = new MarketConsensusEngine();

        $ledgerMock = $this->createStub(CorporateLedgerService::class);
        $eventPublisherMock = $this->createStub(MarketEventPublisher::class);
        $narrativeEngineMock = $this->createStub(NarrativeEngine::class);

        $treasuryEngine = new TreasuryEngine(
            $corporateMetrics,
            $debtEngine,
            $capExEngine,
            $this->mathUtility,
        );

        $capitalAllocationEngine = new CapitalAllocationEngine(
            $ledgerMock,
            $corporateMetrics,
            $debtEngine,
            $this->mathUtility,
            $treasuryEngine,
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
            $marketConsensusEngine,
            $industryShareLedger,
        );
    }

    /**
     * A rival that builds plant after the industry was anchored is oversupply for everyone in it. The firm
     * that built nothing sells the same volume at a lower price: revenue and the reported margin fall
     * against a control where the rival stood still, and the fixed cost base is untouched. Compared on one
     * seed so the two runs consume the random stream identically.
     */
    public function testARivalsCapacityBuildLowersTheIndustryPriceForAFirmThatBuiltNothing(): void
    {
        $report = function (float $rivalCapacityMultiple): array {
            $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
            $this->mathUtility = new MathUtility();
            $engine = $this->buildEngine($this->createStub(EventDispatcherInterface::class), $ledger);
            mt_srand(7); // seeded once every mock is built: PHPUnit names a new mock class from the same generator

            $firm = $this->buildMatureIndustrial('HOLD');
            $rival = $this->buildMatureIndustrial('BULD');
            $macro = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.045);
            $ticksPerYear = 252;

            // Both firms are known to the ledger at equal plant before anyone builds.
            $engine->calculate($firm, $macro, EarningsEngine::resolveReportingTick('HOLD', $ticksPerYear), $ticksPerYear);
            $rivalCapacity = (float) $firm->getTotalRevenue();
            // The rival holds half its market, so its build weighs on the industry price at half strength.
            $ledger->resolveIndustryCapacityRatio($rival, $rivalCapacity, 0.5, 1.0, 0.0, 0.0, 1, $ticksPerYear);
            // The rival's next report shows the plant it built (or did not).
            $ledger->resolveIndustryCapacityRatio($rival, $rivalCapacity * $rivalCapacityMultiple, 0.5, 1.0, 0.0, 0.0, 2, $ticksPerYear);

            $engine->calculate($firm, $macro, 63 + EarningsEngine::resolveReportingTick('HOLD', $ticksPerYear), $ticksPerYear);

            return [
                'revenue' => (float) $firm->getTotalRevenue(),
                'margin' => (float) $firm->getReportedOperatingMargin(),
            ];
        };

        $control = $report(1.0);
        $overbuilt = $report(1.6);

        $this->assertLessThan($control['revenue'], $overbuilt['revenue'], 'the industry price fell, so the same volume brings in less');
        $this->assertLessThan($control['margin'], $overbuilt['margin'], 'unit costs did not move with the industry price, so the margin took the cut');
        $this->assertGreaterThan($control['revenue'] * (1.0 - FinancialConstants::MAX_INDUSTRY_PRICE_RESPONSE), $overbuilt['revenue'], 'bounded by the per-firm response cap');
    }

    /**
     * Growth plant follows demand, not the reinvestment rate or how small the firm looks next to its market:
     * a price-taker at half a percent of a $10T market and a firm spanning forty percent of a $125B one, both
     * set to reinvest far past trend, grow their plant alike. Before the flexible accelerator the price-taker
     * compounded at its full reinvestment rate, since nothing it built could move a price.
     */
    public function testGrowthPlantFollowsDemandNotHowSmallTheFirmLooksInItsMarket(): void
    {
        $capitalGrowth = function (string $samRatio): float {
            $this->mathUtility = new MathUtility();
            $engine = $this->buildEngine($this->createStub(EventDispatcherInterface::class), new IndustryShareLedger(new InMemoryIndustryShareStore()));
            mt_srand(5); // seeded once every mock is built: PHPUnit names a new mock class from the same generator
            $stock = $this->buildMatureIndustrial('GROW');
            $stock->setSamRatio($samRatio);
            $stock->setCapexRatio('0.90');
            $macro = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.045, inflationEma: 0.02);
            $ticksPerYear = 252;

            $engine->calculate($stock, $macro, EarningsEngine::resolveReportingTick('GROW', $ticksPerYear), $ticksPerYear);
            $opening = $stock->getNetPpe();
            $this->assertGreaterThan(0.0, $opening, 'the first report seeds the plant ledger');
            for ($quarter = 1; $quarter <= 8; $quarter++) {
                $engine->calculate($stock, $macro, ($quarter * 63) + EarningsEngine::resolveReportingTick('GROW', $ticksPerYear), $ticksPerYear);
            }

            return $stock->getNetPpe() / $opening;
        };

        $priceTaker = $capitalGrowth('10.0');
        $dominant = $capitalGrowth('0.125');

        // Two years of the sector's nominal demand growth (its secular rate plus 2% inflation), with a point of
        // slack for replacement-cost maintenance; reinvesting at the full rate instead compounds to ~15%.
        $demandGrowth = Sectors::strategyFor('Auto Manufacturers')->getFadedSecularGrowthRate(new Stock(), 0.0) + 0.02;

        $this->assertGreaterThan(1.0, $priceTaker, 'the firm still grows with its market');
        $this->assertLessThan(exp(2.0 * $demandGrowth) + 0.01, $priceTaker, 'plant grows with demand, not at the reinvestment rate');
        $this->assertLessThanOrEqual($dominant + 0.01, $priceTaker, 'a small share of a large market is no licence to build past demand');
        // Alike, not identical: the two trade at different prices, so their market leverage, Hamada beta and hurdle
        // differ.
        $this->assertEqualsWithDelta($dominant, $priceTaker, 0.02, 'both grow their plant with demand');
    }

    /**
     * The error-correction half of the accelerator: a firm whose installed plant has fallen behind what its share
     * of trend demand calls for builds faster than the same firm sitting on its anchor, and closes the gap.
     */
    public function testAFirmBehindItsShareOfTrendDemandBuildsFasterThanOneOnIt(): void
    {
        $capitalGrowth = function (float $anchorOverCapacity): float {
            $this->mathUtility = new MathUtility();
            $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
            $engine = $this->buildEngine($this->createStub(EventDispatcherInterface::class), $ledger);
            mt_srand(5); // seeded once every mock is built: PHPUnit names a new mock class from the same generator
            $stock = $this->buildMatureIndustrial('BHND');
            $stock->setCapexRatio('0.90');
            $macro = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.045, inflationEma: 0.02);
            $ticksPerYear = 252;

            // Strike the anchor before the first report, at the plant the firm's share of demand calls for.
            $ledger->resolveIndustryCapacityRatio(
                $stock,
                (float) $stock->getTotalRevenue() * $anchorOverCapacity,
                0.05,
                IndustryShareLedger::trendNominalGdp($macro),
                0.0,
                0.0,
                0,
                $ticksPerYear,
            );

            $engine->calculate($stock, $macro, EarningsEngine::resolveReportingTick('BHND', $ticksPerYear), $ticksPerYear);
            $opening = $stock->getNetPpe();
            $this->assertGreaterThan(0.0, $opening, 'the first report seeds the plant ledger');
            for ($quarter = 1; $quarter <= 8; $quarter++) {
                $engine->calculate($stock, $macro, ($quarter * 63) + EarningsEngine::resolveReportingTick('BHND', $ticksPerYear), $ticksPerYear);
            }

            return $stock->getNetPpe() / $opening;
        };

        $onAnchor = $capitalGrowth(1.0);
        $behind = $capitalGrowth(3.0);

        $this->assertGreaterThan($onAnchor, $behind, 'a firm short of the plant its demand calls for builds toward it');
    }

    public function testASectorWideGoodQuarterIsNotBookedAsShareTakenFromRivals(): void
    {
        $bookedGain = function (float $sectorZ): array {
            $store = new InMemoryIndustryShareStore();
            $ledger = new IndustryShareLedger($store);
            $this->mathUtility = new MathUtility();
            $engine = $this->buildEngine($this->createStub(EventDispatcherInterface::class), $ledger);
            mt_srand(11); // seeded once every mock is built: PHPUnit names a new mock class from the same generator

            $firm = $this->buildMatureIndustrial('SECT');
            $macro = new MacroStateDTO(
                corporateTaxRate: 0.21,
                policyRateEma: 0.04,
                yield5yEma: 0.045,
                sectorDemandZ: [$firm->getSector() => $sectorZ],
            );

            $engine->calculate($firm, $macro, EarningsEngine::resolveReportingTick('SECT', 252), 252);

            return [
                'revenue' => (float) $firm->getTotalRevenue(),
                'gain' => $store->readIndustry('Auto Manufacturers')['SECT']['gain'],
            ];
        };

        $calm = $bookedGain(0.0);
        $boom = $bookedGain(2.0);

        // The sector factor lifted this firm's revenue — and every peer's. The ledger books only what was the
        // firm's own. The attribution follows the revenue-weighted Z of the demand streams; what the auto
        // model does to revenue beyond that line (multipliers, floors) leaves a residual, a fraction of the lift.
        $sectorLift = ($boom['revenue'] - $calm['revenue']) / $calm['revenue'];
        $this->assertGreaterThan(0.0, $sectorLift, 'the sector boom reached the top line');
        $this->assertLessThan(0.25 * $sectorLift, abs($boom['gain'] - $calm['gain']), 'most of the sector lift is not booked as a gain taken from rivals');
    }

    public function testABiotechsKnownCommercialShiftReachesExpectedRevenue(): void
    {
        $expectedRevenue = function (float $knownShift): float {
            $this->mathUtility = new MathUtility();

            $stock = $this->buildMatureIndustrial('PHRM');
            $stock->setIndustry('Drug Manufacturers - General');
            $stock->setEarningsMomentumZ([\App\Service\Model\Sector\BiotechBusinessModel::STATE_KNOWN_COMMERCIAL_SHIFT => $knownShift]);
            $macro = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.045);

            $captured = null;
            $dispatcher = $this->createStub(EventDispatcherInterface::class);
            $dispatcher->method('dispatch')->willReturnCallback(function (object $event) use (&$captured) {
                if ($event instanceof EarningsReportedEvent) {
                    $captured = $event->getContext();
                }
                return $event;
            });
            $engine = $this->buildEngine($dispatcher);
            mt_srand(5); // seeded once every mock is built: PHPUnit names a new mock class from the same generator
            $engine->calculate($stock, $macro, EarningsEngine::resolveReportingTick('PHRM', 252), 252);
            $this->assertNotNull($captured);

            return $captured->expectedRevenue;
        };

        $intact = $expectedRevenue(0.0);
        $eroding = $expectedRevenue(-0.20);

        // A cliff the clock has dated is in the estimate before it is in the books: expected revenue is
        // lower by the known shift, so the erosion itself is not a miss when it lands.
        $this->assertEqualsWithDelta(0.80, $eroding / $intact, 0.02);
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
            interbankLiquiditySpreadEma: 0.01,
        );

        // Quarter 1: Severe contraction
        $this->earningsEngine->calculate($stock, $macroState, 1);

        $marginQ1 = (float) $stock->getStructuralVariableMargin();
        // The structural margin should remain stable around 0.10, not collapse to 0.01
        $this->assertGreaterThan(0.08, $marginQ1, "Structural margin dropped too aggressively in Q1 due to cost stickiness overwrite.");

        // Quarter 2: continued contraction
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
        // Macro boom
        $boomMacro = new MacroStateDTO(
            outputGapEma: 0.03,
            nominalGdpIndex: 1.10,
            corporateTaxRate: 0.21,
            marketVolatilityEma: 0.12,
            policyRateEma: 0.03,
            yield5yEma: 0.035,
            macroCreditSpreadEma: 0.015,
            interbankLiquiditySpreadEma: 0.001,
        );

        // Run 4 consecutive quarters in a boom
        for ($q = 1; $q <= 4; $q++) {
            $events = $this->earningsEngine->calculate($stock, $boomMacro, $q);
            $eventDesc = $events[0]['description'] ?? '';

            // In a boom with massive profits, company must NOT be forced into emergency borrowing or dilutive stock
            // offerings
            $this->assertStringNotContainsString('Forced to borrow', $eventDesc, "Quarter {$q} triggered false emergency borrowing during an economic boom.");
            $this->assertStringNotContainsString('emergency stock offering', $eventDesc, "Quarter {$q} triggered false emergency stock offering during an economic boom.");
        }

        // Treasury should remain healthy and positive
        $treasury = (float) $stock->getCorporateTreasury();
        $this->assertGreaterThan(5_000_000_000.0, $treasury, 'Corporate treasury was depleted during a boom.');
    }
    /**
     * A price war is the industry's: one a rival started reaches a carrier through its report, one a carrier's own
     * report starts is booked for the whole industry from that tick, and the strategy (one instance serves every
     * carrier) holds no view of the industry once the report is done.
     */
    public function testAPriceWarIsBookedForTheIndustryAndReachesEveryCarrierThroughItsReport(): void
    {
        $regimeKey = \App\Service\Model\StreamContext::REGIME_STATE_PREFIX . \App\Service\Model\Sector\TelecomBusinessModel::REGIME_PRICE_WAR;
        $macro = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.045);
        $carrier = function (string $ticker): Stock {
            $stock = $this->buildMatureIndustrial($ticker);
            $stock->setIndustry('Telecom Services');

            return $stock;
        };
        $tick = EarningsEngine::resolveReportingTick('TELX', 252);

        // A war a rival started ten ticks earlier: this carrier is in its first quarter of it.
        $store = new InMemoryIndustryShareStore();
        $store->writeRegime('Telecom Services', 'price_war', ['start_tick' => $tick - 10, 'end_tick' => $tick + 300]);
        $joined = $carrier('TELX');
        $this->buildEngine($this->createStub(EventDispatcherInterface::class), new IndustryShareLedger($store))->calculate($joined, $macro, $tick, 252);
        $this->assertSame(1.0, ($joined->getEarningsMomentumZ() ?? [])[$regimeKey] ?? null);

        $strategy = Sectors::strategyFor('Telecom Services');
        $this->assertNull((new \ReflectionProperty(\App\Service\Model\AbstractBusinessModel::class, 'industryRegimes'))->getValue($strategy), 'no view outlives the report');

        // A carrier whose own draw starts one books it for the industry from its report tick, in whole quarters.
        $booked = null;
        for ($seed = 1; $seed <= 1_000 && $booked === null; $seed++) {
            mt_srand($seed);
            $this->mathUtility = new MathUtility();
            $store = new InMemoryIndustryShareStore();
            $starter = $carrier('TELX');
            $this->buildEngine($this->createStub(EventDispatcherInterface::class), new IndustryShareLedger($store))->calculate($starter, $macro, $tick, 252);
            if (isset($store->readRegimes('Telecom Services')['price_war'])) {
                $booked = [$store->readRegimes('Telecom Services')['price_war'], $starter];
            }
        }
        $this->assertNotNull($booked, 'some seed in 1,000 starts a war (2.3% a quarter)');
        [$run, $starter] = $booked;
        $this->assertSame($tick, $run['start_tick']);
        $this->assertSame(0, ($run['end_tick'] - $run['start_tick']) % 63, 'a war lasts whole quarters');
        $this->assertGreaterThanOrEqual(63, $run['end_tick'] - $run['start_tick']);
        $this->assertSame(1.0, ($starter->getEarningsMomentumZ() ?? [])[$regimeKey] ?? null);
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

    /**
     * The fixed base follows the declared season by the strategy's own share of it: a generator, whose season is in its
     * prices, carries its base evenly through the year where a firm staffing for a volume peak does not.
     */
    public function testTheFixedBaseFollowsTheSeasonByTheStrategysShare(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);
        $stock = $this->buildMatureIndustrial('DIPR');
        $stock->setIndustry('Utilities - Independent Power Producers');

        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('DIPR', 252), 252);

        $this->assertNotNull($captured);
        $this->assertNotEqualsWithDelta(1.0, $captured->seasonalFactor, 1e-6, 'control: the quarter carries a season');
        $this->assertSame(1.0, $captured->seasonalFixedCostFactor);
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
        $stock->setSector('Information Technology');
        // About ten times sales, where software trades: a tenth of revenue in equity is ~1% of the company a
        // year, inside the sector's burn-rate cap, so all of it is settled in shares.
        $stock->setPrice('300.00');
        $stock->setOperatingMargin('0.25');
        $stock->setLifecycleStage(LifecycleStage::Mature);
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);

        $engine->calculate($stock, $macroState, EarningsEngine::resolveReportingTick('SAAS', 252));

        $this->assertNotNull($captured);
        $intensity = Sectors::getBusinessModelStrategy('tech')->getStockCompensationIntensity($stock);
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
        $expectedShares = ((float) $captured->allocation['new_shares']) + ($captured->stockCompensation / 300.0);
        $this->assertEqualsWithDelta($expectedShares, (float) $stock->getSharesOutstanding(), 1.0);
    }

    /**
     * The cost side of the quarter is filed with the KPIs, for the company page's business map: the variable cost
     * ratio the quarter ran at, the fixed cost base, the price gap of each input in the model's basket at the macro
     * the quarter was struck under, and the input-cost gap the firm has not yet passed on.
     */
    public function testTheQuarterFilesItsCostSide(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);
        $stock = $this->buildMatureIndustrial('COST');
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, industrialMetalsIndexEma: 130.0, realWageGap: -0.02);

        $engine->calculate($stock, $macroState, EarningsEngine::resolveReportingTick('COST', 252));

        $this->assertNotNull($captured);
        $kpis = $captured->kpis;
        $this->assertEqualsWithDelta($captured->actualVariableCosts / $captured->actualRevenue, $kpis['variable_cost_ratio'], 1e-12);
        $this->assertSame($captured->fixedCosts, $kpis['fixed_costs']);
        $this->assertEqualsWithDelta(0.30, $kpis['input_gap_metals'], 1e-12, 'metals at 130 against a base of 100');
        $this->assertEqualsWithDelta(-0.02, $kpis['input_gap_labor'], 1e-12);
        $this->assertArrayNotHasKey('input_gap_agri', $kpis, 'only inputs the basket buys are filed');
        $state = $stock->getEarningsMomentumZ() ?? [];
        $this->assertEqualsWithDelta(
            $state[FinancialConstants::STATE_INPUT_COST_DRAG_BASE] * ($kpis['input_cost_level'] - $kpis['input_cost_recovery']),
            $kpis['input_cost_drag'],
            1e-12,
        );
        $this->assertEqualsWithDelta(
            $kpis['input_cost_drag'],
            $kpis['input_drag_metals'] + $kpis['input_drag_labor'] + $kpis['input_drag_earlier'],
            1e-12,
            'the drag split by input sums to the drag filed',
        );
    }

    /**
     * Stock compensation is a memo of pay already inside operating costs: a firm paying a tenth of its revenue in
     * shares reports the same costs, EBIT and pretax income as the same firm paying none.
     */
    public function testStockCompensationChangesNoMarginOrPretaxIncome(): void
    {
        $quarter = function (bool $paysInShares): EarningsSimulationContext {
            $captured = null;
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
                $captured = $event->getContext();
            });
            $this->mathUtility = new MathUtility();
            $engine = $this->buildEngine($dispatcher);

            // The software model, or the same model paying nothing in shares, swapped into the strategy cache.
            $cache = new \ReflectionProperty(Sectors::class, 'strategyInstances');
            $strategies = $cache->getValue();
            $strategies['tech'] = $paysInShares ? new TechBusinessModel() : new class extends TechBusinessModel {
                public function getStockCompensationIntensity(Stock $stock): float
                {
                    return 0.0;
                }
            };
            $cache->setValue(null, $strategies);
            mt_srand(7);

            try {
                $stock = $this->buildMatureIndustrial('SAAS');
                $stock->setIndustry('Software - Application');
                $stock->setSector('Information Technology');
                $stock->setPrice('300.00');
                $stock->setOperatingMargin('0.25');
                $stock->setLifecycleStage(LifecycleStage::Mature);
                $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('SAAS', 252));
            } finally {
                unset($strategies['tech']);
                $cache->setValue(null, $strategies);
            }

            $this->assertInstanceOf(EarningsSimulationContext::class, $captured);

            return $captured;
        };

        $paying = $quarter(true);
        $none = $quarter(false);

        $this->assertGreaterThan(0.0, $paying->kpis['stock_compensation']);
        $this->assertSame(0.0, (float) $none->kpis['stock_compensation']);
        $this->assertEqualsWithDelta($none->operatingCosts, $paying->operatingCosts, 1e-6);
        $this->assertEqualsWithDelta($none->ebit, $paying->ebit, 1e-6);
        $this->assertEqualsWithDelta($none->preTaxIncome, $paying->preTaxIncome, 1e-6);
    }

    /**
     * A board cannot grant past its burn-rate cap (ISS benchmarks): at a fifth of software's usual valuation the
     * same pay bill would print several percent of the company a year, so only the capped value is settled in
     * shares and the rest of the expense is paid in cash. The full charge is still reported; the add-back and
     * the dilution are the share-settled part alone.
     */
    public function testStockCompensationPastTheBurnRateCapIsPaidInCash(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        $stock = $this->buildMatureIndustrial('CAPD');
        $stock->setIndustry('Software - Application');
        $stock->setSector('Information Technology');
        $stock->setPrice('50.00');
        $stock->setOperatingMargin('0.25');
        $stock->setLifecycleStage(LifecycleStage::Mature);
        $sharesBefore = (float) $stock->getSharesOutstanding();

        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('CAPD', 252));

        $this->assertNotNull($captured);
        $expense = $captured->actualRevenue * Sectors::getBusinessModelStrategy('tech')->getStockCompensationIntensity($stock);
        $grantable = EarningsEngine::EQUITY_BURN_RATE_CAP_BY_SECTOR['Information Technology'] * 0.25 * $sharesBefore * 50.0;

        $this->assertGreaterThan($grantable, $expense, 'the fixture only means anything while the bill exceeds the cap');
        $this->assertEqualsWithDelta($expense, $captured->kpis['stock_compensation'], 1.0, 'the full charge is reported');
        $this->assertEqualsWithDelta($grantable, $captured->stockCompensation, 1.0, 'only the grantable value is settled in shares');
        $this->assertEqualsWithDelta(
            ((float) $captured->allocation['new_shares']) + ($grantable / 50.0),
            (float) $stock->getSharesOutstanding(),
            1.0,
            'dilution stops at the burn-rate cap',
        );
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
            $this->assertInstanceOf(EarningsSimulationContext::class, $ctx);
            $totalSbc += $ctx->stockCompensation;
            // The first report opens the plant's deferred tax against retained earnings: a restatement, not income.
            $totalNetIncome += $ctx->actualQuarterlyNetIncome + $ctx->cumulativeEffectAdjustment;
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
        $run = function (string $rate): EarningsSimulationContext {
            $captured = null;
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
                $captured = $event->getContext();
            });
            $this->mathUtility = new MathUtility(); // fresh Box-Muller state so both runs see identical draws
            $engine = $this->buildEngine($dispatcher);

            mt_srand(4242);
            $stock = $this->buildMatureIndustrial('DEPX');
            $stock->setDepreciationRate($rate);
            $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('DEPX', 252));
            $this->assertNotNull($captured);

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
            'a heavier charge must move cost out of the cash base and into depreciation',
        );

        // EBIT barely moves, because operatingMargin is an EBIT margin and the carve-out is self-cancelling
        // at normal capacity. All that survives is the gap between the scheduled charge and the realized one.
        $this->assertLessThan(
            ($heavy->ebitda - $light->ebitda) * 0.25,
            abs($heavy->ebit - $light->ebit),
            'more than doubling the depreciation rate must not redefine the firm structural EBIT margin',
        );
    }

    /**
     * A plant wears out on a schedule (ASC 360), so a boom and a bust charge the same depreciation: it is a fixed
     * cost, the demand swing reaches EBIT in full, and analysts, who read the schedule, forecast it exactly.
     */
    public function testDepreciationIsChargedOnScheduleWhateverTheOutput(): void
    {
        $boom = $this->reportDepreciation('Auto Manufacturers', 0.04);
        $bust = $this->reportDepreciation('Auto Manufacturers', -0.04);

        $this->assertGreaterThan($bust->capacityUtilization + 0.01, $boom->capacityUtilization, 'the cycle must move output for the test to have power');
        $this->assertEqualsWithDelta($bust->quarterlyDepreciation, $boom->quarterlyDepreciation, 1.0);
        foreach ([$boom, $bust] as $captured) {
            $this->assertEqualsWithDelta($captured->structuralDepreciation, $captured->quarterlyDepreciation, 1.0);
            $this->assertEqualsWithDelta($captured->quarterlyDepreciation, $captured->expectedDepreciation, 1.0);
            $this->assertEqualsWithDelta($captured->quarterlyDepreciation, $captured->ebitda - $captured->ebit, 1.0);
        }
    }

    /**
     * A producer uses its plant up by extracting it (ASC 932), so its charge follows output, and the estimate is
     * struck on the output the quarter would have run at before its own shocks.
     */
    public function testAnExtractiveProducerDepreciatesByUnitsOfProduction(): void
    {
        $captured = $this->reportDepreciation('Oil & Gas E&P', 0.0);

        $this->assertGreaterThan(0.0, $captured->structuralDepreciation);
        $this->assertNotEqualsWithDelta($captured->capacityUtilizationBeforeShocks, $captured->capacityUtilization, 1e-6, 'the quarter must draw its own shock for the test to have power');
        $this->assertEqualsWithDelta($captured->structuralDepreciation * $captured->capacityUtilization, $captured->quarterlyDepreciation, 1.0);
        $this->assertEqualsWithDelta($captured->structuralDepreciation * $captured->capacityUtilizationBeforeShocks, $captured->expectedDepreciation, 1.0);
        $this->assertEqualsWithDelta($captured->quarterlyDepreciation, $captured->ebitda - $captured->ebit, 1.0);
    }

    /**
     * Trend growth is a rate and reaches revenue through the plant it builds. Utilisation is a level rebuilt every
     * quarter, so a sector's demand drift must not sit on it: a fast sector runs at the same utilisation in its first
     * year as after its excess has faded to trend.
     */
    public function testUtilisationCarriesNoTrendGrowth(): void
    {
        $retail = Sectors::strategyFor('Internet Retail');
        $this->assertGreaterThan(
            $retail->getFadedSecularGrowthRate(new Stock(), 100.0) + 0.03,
            $retail->getFadedSecularGrowthRate(new Stock(), 0.0),
            'the sector must open well above trend for the test to have power',
        );

        $opening = $this->reportDepreciation('Internet Retail', 0.0, 0.0);
        $faded = $this->reportDepreciation('Internet Retail', 0.0, 100.0);

        $this->assertEqualsWithDelta($faded->capacityUtilizationBeforeShocks, $opening->capacityUtilizationBeforeShocks, 1e-9);
    }

    /**
     * A lender's book turns over at the yield it earns. Floored at a 1% return, a book whose yield fell below
     * 0.01 / (margin x (1 - t)) in an easing cycle booked revenue it never earned: BNTH's by 55% at 300bp lower rates.
     */
    public function testALenderWhoseYieldFellTurnsOverAtThatYield(): void
    {
        $ctx = new EarningsSimulationContext($this->buildCardLender('LOW'), new MacroStateDTO(), Sectors::getBusinessModelStrategy('commercial_bank'), 'commercial_bank');
        $ctx->stableMargin = 0.30;
        $ctx->corporateTaxRate = 0.20;
        $ctx->baselineRoic = 0.004;

        $turnover = (new \ReflectionMethod(EarningsEngine::class, 'resolveAnnualCapitalTurnover'))->invoke($this->buildEngine(new EventDispatcher()), $ctx);

        $this->assertEqualsWithDelta(0.004 / 0.8 / 0.30, $turnover, 1e-12, 'a 1.67% yield, not the floor\'s 4.17%');
    }

    /** @return iterable<string, array{string}> Industries whose model moves the cycle inside its own streams. */
    public static function industriesThatCarryTheCycleInTheirStreams(): iterable
    {
        foreach (['Furnishings, Fixtures & Appliances', 'Computer Hardware', 'Communication Equipment', 'Aerospace & Defense', 'Engineering & Construction', 'Telecom Services', 'Steel', 'Railroads', 'Specialty Industrial Machinery', 'Pollution & Treatment Controls', 'Security & Protection Services'] as $industry) {
            yield $industry => [$industry];
        }
    }

    /**
     * Analysts know the public quarter, the cycle a model applies inside its streams included. In a steady boom with
     * no news of the firm's own, the estimate converges on what it reports: read only through the root demand shift,
     * a model that carried the cycle in its streams was missed every quarter for as long as the boom lasted.
     */
    #[DataProvider('industriesThatCarryTheCycleInTheirStreams')]
    #[AllowMockObjectsWithoutExpectations]
    public function testASteadyBoomIsNotAStandingSurpriseOnceAnalystsSeeIt(string $industry): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        // The firm draws nothing of its own: every quarter is the one analysts expect.
        $quiet = $this->createPartialMock(MathUtility::class, ['generateStandardNormal', 'generateUniform', 'checkProbability', 'generatePoissonCount']);
        $quiet->method('generateStandardNormal')->willReturn(0.0);
        $quiet->method('generateUniform')->willReturn(0.5);
        $quiet->method('checkProbability')->willReturn(false);
        $quiet->method('generatePoissonCount')->willReturn(0);
        $this->mathUtility = $quiet;
        $engine = $this->buildEngine($dispatcher);
        $stock = $this->buildMatureIndustrial('BOOM');
        $stock->setIndustry($industry);
        $gap = 0.03;
        $boom = new MacroStateDTO(...[
            'corporateTaxRate' => 0.21,
            'policyRateEma' => 0.04,
            'yield5yEma' => 0.045,
            'outputGap' => $gap,
            'outputGapEma' => $gap,
            'foreignOutputGapEma' => $gap,
            'manufacturingPmiEma' => MacroConstants::PMI_BASELINE + 2.0,
            ...array_fill_keys(array_keys(MacroAggregateSubsystem::DEMAND_TRANSMISSION_LAGS), $gap),
        ]);

        for ($quarter = 0; $quarter < 12; ++$quarter) {
            $engine->calculate($stock, $boom, ($quarter * 63) + EarningsEngine::resolveReportingTick('BOOM', 252), 252);
        }
        $this->assertInstanceOf(EarningsSimulationContext::class, $captured);

        // Within the jump's compensator: a quarter with no jump sits that far above its mean, and analysts hear that
        // at their visibility. Missing the cycle missed by the whole boom, several percent.
        $unbiased = $captured->analystExpectedRevenue / (1.0 - MarketConsensusEngine::ANALYST_WALKDOWN_BIAS);
        $this->assertEqualsWithDelta(1.0, $unbiased / $captured->actualRevenue, 0.01);
    }

    /** One report of a mature firm in the given industry at the given output gap, on a fixed draw. */
    private function reportDepreciation(string $industry, float $outputGap, float $totalTime = 0.0): EarningsSimulationContext
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(4242);
        $stock = $this->buildMatureIndustrial('WEAR');
        $stock->setIndustry($industry);
        $stock->setDepreciationRate('0.10');
        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, outputGapEma: $outputGap, totalTime: $totalTime), EarningsEngine::resolveReportingTick('WEAR', 252));
        $this->assertInstanceOf(EarningsSimulationContext::class, $captured);

        return $captured;
    }

    /**
     * Goodwill is impairment-tested, never depreciated, and working capital does not wear out. Charging
     * depreciation on invested capital overstated it for every acquisitive or inventory-heavy firm.
     */
    public function testGoodwillIsNotDepreciated(): void
    {
        $strategy = Sectors::getBusinessModelStrategy('auto_manufacturer');

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
            'a firm carrying goodwill must not depreciate more than an identical firm without it',
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
        $this->assertNotNull($captured);

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
        $this->assertNotNull($captured);

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
        $run = function (float $deflator): EarningsSimulationContext {
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
            $this->assertNotNull($captured);

            return $captured;
        };

        $stablePrices = $run(1.00);
        $inflatedPrices = $run(1.20);

        $this->assertEqualsWithDelta(1.00, $stablePrices->replacementCostRatio, 0.0001);
        $this->assertEqualsWithDelta(1.20, $inflatedPrices->replacementCostRatio, 0.0001);

        // Same plant, same depreciation charge: only the cash cost of replacing it has moved. Growth spend nets the
        // revaluation out of its trend tranche, so the claim lives on the maintenance slice, not on total capex.
        $this->assertEqualsWithDelta($stablePrices->quarterlyDepreciation, $inflatedPrices->quarterlyDepreciation, 1.0);
        $this->assertGreaterThan(0.0, $stablePrices->maintenanceCapex);
        $this->assertEqualsWithDelta(1.20, $inflatedPrices->maintenanceCapex / $stablePrices->maintenanceCapex, 1e-9);
    }

    /**
     * A firm in breach of its covenants pays for upkeep from its own funds once its committed outlays are met: the
     * plan when the funds cover it, what is left of the funds when they do not, and never less than the share that
     * keeps the plant running, however deep the hole.
     */
    public function testAConstrainedFirmsUpkeepIsWhatItsFundsLeaveAfterCommittedOutlays(): void
    {
        $floor = 100.0 * EarningsEngine::MIN_DISTRESSED_MAINTENANCE_SHARE;

        $this->assertSame(100.0, EarningsEngine::fundingConstrainedMaintenance(100.0, 50.0, 400.0));
        $this->assertSame(60.0, EarningsEngine::fundingConstrainedMaintenance(100.0, 50.0, 110.0));
        $this->assertSame($floor, EarningsEngine::fundingConstrainedMaintenance(100.0, 50.0, 40.0), 'Committed outlays come first.');
        $this->assertSame($floor, EarningsEngine::fundingConstrainedMaintenance(100.0, 0.0, -500.0));
    }

    /**
     * Lenders advance no new money to a firm in breach of its covenants, leverage over the limit or coverage under the
     * minimum, so it cuts its upkeep to what its cash pays for rather than borrow it at penalty rates. The same firm
     * inside its covenants spends the full plan.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testAFirmInBreachOfItsCovenantCutsUpkeepToItsFunds(): void
    {
        $run = function (array $breach): EarningsSimulationContext {
            $captured = null;
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
                $captured = $event->getContext();
            });
            $assessor = new DebtEngine($this->mathUtility, new CorporateMetrics());
            $lenders = $this->getMockBuilder(DebtEngine::class)
                ->setConstructorArgs([$this->mathUtility, new CorporateMetrics()])
                ->onlyMethods(['analyzeTrailingDebtHealth'])
                ->getMock();
            $lenders->method('analyzeTrailingDebtHealth')->willReturnCallback(
                static fn(Stock $stock, MacroStateDTO $macro): DebtHealthDTO => new DebtHealthDTO(...[
                    ...get_object_vars($assessor->analyzeTrailingDebtHealth($stock, $macro)),
                    ...$breach,
                ]),
            );
            $engine = $this->buildEngine($dispatcher, null, $lenders);

            mt_srand(9090);
            $stock = $this->buildMatureIndustrial('BRCH');
            $stock->setGrossPpe('40000000000');
            $stock->setAccumulatedDepreciation('20000000000');
            $stock->setPpeVintageDeflator('1.000000');
            $stock->setCorporateTreasury('100000000');

            $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('BRCH', 252), 252);
            $this->assertNotNull($captured);

            return $captured;
        };

        $compliant = $run([]);
        $this->assertGreaterThanOrEqual($compliant->strategy->getMinIcr(), $compliant->health->interestCoverage, 'Control: inside its covenants.');
        $this->assertTrue($compliant->health->hasLeverageHeadroom);
        $plan = $compliant->maintenanceCapex;
        $this->assertGreaterThan(0.0, $plan);

        foreach (['leverage' => ['hasLeverageHeadroom' => false], 'coverage' => ['interestCoverage' => 1.0]] as $covenant => $breach) {
            $breached = $run($breach)->maintenanceCapex;
            $this->assertLessThan($plan, $breached, "Breach of the {$covenant} covenant.");
            $this->assertGreaterThanOrEqual($plan * EarningsEngine::MIN_DISTRESSED_MAINTENANCE_SHARE - 1.0, $breached);
        }
    }

    /**
     * Capital goods drift against the deflator by industry (BEA Fixed Assets): replacement is bought at the industry's
     * capital-goods price. Capacity stays the book: an industry's selling prices follow its capital costs in the long
     * run (rents with building costs, telecom tariffs with equipment), so a book dollar keeps its revenue.
     */
    public function testReplacementAndCapacityFollowTheIndustrysCapitalGoodsPrice(): void
    {
        $run = function (float $years, float $ppeRelativePrice): EarningsSimulationContext {
            $captured = null;
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
                $captured = $event->getContext();
            });
            $engine = $this->buildEngine($dispatcher);

            mt_srand(9090);
            $stock = $this->buildMatureIndustrial('CAPG');
            $stock->setGrossPpe('40000000000');
            $stock->setAccumulatedDepreciation('20000000000');
            $stock->setPpeVintageDeflator('1.000000');
            $stock->setEarningsMomentumZ([
                EarningsEngine::STATE_CAPITAL_GOODS_ANCHOR_TIME => 0.0,
                EarningsEngine::STATE_PPE_RELATIVE_PRICE => $ppeRelativePrice,
            ]);

            $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, gdpDeflator: 1.0, totalTime: $years);
            $engine->calculate($stock, $macroState, EarningsEngine::resolveReportingTick('CAPG', 252), 252);
            $this->assertNotNull($captured);

            return $captured;
        };

        $drift = CapitalGoodsPrices::DRIFT_BY_MODEL['AutoManufacturerBusinessModel'];
        $atAnchor = $run(0.0, 1.0);
        $decadeOn = $run(10.0, 1.0);
        $this->assertEqualsWithDelta(1.0, $atAnchor->replacementCostRatio, 1e-9, 'At the anchor capital goods cost what the plant did.');
        $this->assertEqualsWithDelta(exp($drift * 10.0), $decadeOn->replacementCostRatio, 1e-9, 'A decade on, replacement is bought at the drifted price.');

        // A plant bought when capital goods were dearer costs less to replace now, and earns what its book does.
        $dearVintage = $run(0.0, 1.2);
        $this->assertEqualsWithDelta(1.0 / 1.2, $dearVintage->replacementCostRatio, 1e-9);
        $this->assertEqualsWithDelta($atAnchor->revenueGeneratingCapital, $dearVintage->revenueGeneratingCapital, 1.0);
    }

    /** A distress episode the divestiture engine opened survives the report that rebuilds the stream map. */
    public function testAReportCarriesTheDistressEpisodeForward(): void
    {
        $engine = $this->buildEngine(new EventDispatcher());
        mt_srand(9091);
        $stock = $this->buildMatureIndustrial('EPIS');
        $stock->setEarningsMomentumZ([\App\Service\Corporate\MergerAndAcquisitionEngine::STATE_DISTRESS_SALE_EPISODE => 4.25]);

        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, gdpDeflator: 1.0, totalTime: 5.0), EarningsEngine::resolveReportingTick('EPIS', 252), 252);

        $this->assertSame(4.25, $stock->getEarningsMomentumZ()[\App\Service\Corporate\MergerAndAcquisitionEngine::STATE_DISTRESS_SALE_EPISODE] ?? null);
    }

    /**
     * The report rebuilds the stream map from what the physics registers. State kept under the carried prefix by
     * anything else survives it; a physics key the pass no longer registers does not, so a list it shortens stays
     * short.
     */
    public function testAReportCarriesOutsideStateAndDropsWhatThePhysicsStoppedRegistering(): void
    {
        $engine = $this->buildEngine(new EventDispatcher());
        mt_srand(9091);
        $stock = $this->buildMatureIndustrial('KEEP');
        $carried = EarningsEngine::CARRIED_STATE_PREFIX . 'probe';
        $stock->setEarningsMomentumZ([$carried => 7.0, 'state:probe' => 3.0]);

        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, gdpDeflator: 1.0, totalTime: 5.0), EarningsEngine::resolveReportingTick('KEEP', 252), 252);

        $state = $stock->getEarningsMomentumZ() ?? [];
        $this->assertSame(7.0, $state[$carried] ?? null);
        $this->assertArrayNotHasKey('state:probe', $state);
        $this->assertArrayHasKey(EarningsEngine::STATE_LAST_DEFLATOR, $state, 'the rebuilt map still carries what the report registers');
    }

    /**
     * A shock the whole sector drew moves the firm's revenue but takes no share from its peers: on the same draws, a
     * quarter whose sector drew two sigma books the same share gain as one whose sector drew nothing.
     */
    public function testASectorWideShockMovesRevenueButBooksNoShare(): void
    {
        $run = function (float $sectorDraw): array {
            $ledger = new class (new InMemoryIndustryShareStore()) extends IndustryShareLedger {
                /** @var list<float> */
                public array $gains = [];

                public function recordIdiosyncraticGain(Stock $stock, float $annualRevenue, float $gainFraction, float $addressableShare, int $tick): void
                {
                    $this->gains[] = $gainFraction;
                    parent::recordIdiosyncraticGain($stock, $annualRevenue, $gainFraction, $addressableShare, $tick);
                }
            };
            $captured = null;
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
                $captured = $event->getContext();
            });
            // A fresh generator per run: a Box-Muller spare left over from the first run would shift the second's
            // draws.
            $this->mathUtility = new MathUtility();

            mt_srand(5150);
            $stock = $this->buildMatureIndustrial('SECT');
            $stock->setSector('Consumer Discretionary');
            $this->buildEngine($dispatcher, $ledger)->calculate(
                $stock,
                new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, sectorDemandZ: ['Consumer Discretionary' => $sectorDraw]),
                EarningsEngine::resolveReportingTick('SECT', 252),
            );
            $this->assertInstanceOf(EarningsSimulationContext::class, $captured);
            $this->assertCount(1, $ledger->gains);

            return [$captured, $ledger->gains[0]];
        };

        [$calm, $calmGain] = $run(0.0);
        [$shocked, $shockedGain] = $run(2.0);

        $this->assertGreaterThan($calm->actualRevenue * 1.001, $shocked->actualRevenue, 'the sector draw must move revenue for the test to have power');
        $this->assertEqualsWithDelta(($calm->actualRevenue - $calm->expectedRevenue) / $calm->expectedRevenue, $calmGain, 1e-12);
        $this->assertEqualsWithDelta($calmGain, $shockedGain, 1e-9);
    }

    public function testCapitalGoodsDriftFallsBackThroughTheModelsParents(): void
    {
        $this->assertSame(CapitalGoodsPrices::DRIFT_BY_MODEL['TelecomBusinessModel'], CapitalGoodsPrices::driftFor(new \App\Service\Model\Sector\TelecomBusinessModel()));
        $this->assertSame(CapitalGoodsPrices::ALL_INDUSTRIES_DRIFT, CapitalGoodsPrices::driftFor(new \stdClass()));
        $this->assertLessThan(-0.015, CapitalGoodsPrices::DRIFT_BY_MODEL['TelecomBusinessModel'], 'Telecom plant gets cheaper against what it sells (BEA).');
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
        $this->assertNotNull($captured);

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
            'inflation must not write equity up on its own',
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
        $this->assertNotNull($captured);

        $this->assertLessThan(
            EarningsEngine::INVENTORY_NRV_UTILIZATION_TRIGGER,
            $captured->capacityUtilization,
            'fixture must be running below the writedown trigger for this test to mean anything',
        );
        $this->assertGreaterThan(0.0, $captured->inventoryWriteDown, 'unsold stock must be written down to net realizable value');

        // The charge reduces reported profit, but no money moved, so the cash flow statement reconciles
        // exactly: earnings plus every non-cash charge, less the working capital the quarter tied up.
        $expectedOperatingCashFlow = $captured->actualQuarterlyNetIncome
            + $captured->quarterlyDepreciation
            + $captured->stockCompensation
            + $captured->inventoryWriteDown
            + $captured->receivablesProvision
            + $captured->deferredTaxExpense
            - $captured->deltaWorkingCapital;

        $this->assertEqualsWithDelta($expectedOperatingCashFlow, $captured->operatingCashFlow, 1.0);
    }

    /**
     * The Street walks the estimate down by the firm's own surprise record: a firm without one is cut as a typical firm
     * is, and the next report reads the record the first one started.
     */
    public function testTheEpsWalkDownIsSizedFromTheFirmsSurpriseRecord(): void
    {
        $reports = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$reports): void {
            $reports[] = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);
        $stock = $this->buildMatureIndustrial('WALK');
        $macro = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);
        $tick = EarningsEngine::resolveReportingTick('WALK', 252);

        mt_srand(4646); // seeded once every mock is built: PHPUnit names a new mock class from the same generator
        $engine->calculate($stock, $macro, $tick, 252);
        $first = $reports[0];
        $this->assertEqualsWithDelta(
            MarketConsensusEngine::ANALYST_EPS_WALKDOWN_SURPRISE_SDS * MarketConsensusEngine::TYPICAL_EARNINGS_SURPRISE_SD * $first->analystExpectedRevenue,
            $first->consensusWalkDown,
            1e-6,
            'no record: walked down as a typical firm',
        );

        $record = $stock->getEarningsMomentumZ() ?? [];
        $surprise = ($first->reportedActualNetIncome - $first->managedAccrual + $first->specialItems - $first->reportedExpectedNetIncome - $first->consensusWalkDown)
            / $first->analystExpectedRevenue;
        [$squares, $weight] = MarketConsensusEngine::recordSurprise(0.0, 0.0, $surprise, $first->dt);
        $this->assertEqualsWithDelta($squares, (float) ($record[EarningsEngine::STATE_SURPRISE_SQUARES] ?? -1.0), 1e-12);
        $this->assertEqualsWithDelta($weight, (float) ($record[EarningsEngine::STATE_SURPRISE_WEIGHT] ?? -1.0), 1e-12);

        $engine->calculate($stock, $macro, $tick + 63, 252);
        $second = $reports[1];
        $this->assertEqualsWithDelta(
            MarketConsensusEngine::ANALYST_EPS_WALKDOWN_SURPRISE_SDS * sqrt($squares / $weight) * $second->analystExpectedRevenue,
            $second->consensusWalkDown,
            1e-6,
            'the second report reads the record the first left',
        );
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
        $this->assertNotNull($captured);

        $this->assertGreaterThanOrEqual(
            EarningsEngine::INVENTORY_NRV_UTILIZATION_TRIGGER,
            $captured->capacityUtilization / max(0.01, $captured->seasonalFactor),
        );
        $this->assertEquals(0.0, $captured->inventoryWriteDown);
    }

    /**
     * A homebuilder tests its own land (ASC 360-10 recoverability), and the land is its plant: the charge its model
     * reports comes off the carrying value of the plant, non-cash, and land at its normal share books nothing.
     */
    public function testABuildersLandWriteDownComesOffItsPlant(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);
        $builder = function (string $ticker): Stock {
            $stock = $this->buildMatureIndustrial($ticker);
            $stock->setIndustry('Residential Construction');
            $stock->setOperatingMargin('0.13');
            $stock->setFixedCostRatio(0.10);
            $stock->setGrossPpe('120000000000');
            $stock->setAccumulatedDepreciation('5000000000');
            $stock->setReceivables('0');
            $stock->setInventory('15000000000');
            $stock->setPayables('0');

            return $stock;
        };
        $macro = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);

        // Land carried at 55% of the price, against the 42% a home recovers after the 58% it costs to build.
        mt_srand(5566);
        $impaired = $builder('LNDA');
        $impaired->setEarningsMomentumZ([HomebuilderBusinessModel::STATE_LAND_COST => 0.55]);
        $openingNetPpe = $impaired->getNetPpe();
        $engine->calculate($impaired, $macro, EarningsEngine::resolveReportingTick('LNDA', 252), 252);
        $this->assertNotNull($captured);

        // At trend prices and input costs the lots are worth their normal share, so the bank of three years of
        // closings is written down from 0.55 to 0.20 of the price.
        $landBank = HomebuilderBusinessModel::LAND_BANK_YEARS * 4.0 * $captured->expectedRevenue;
        $this->assertEqualsWithDelta((0.55 - HomebuilderBusinessModel::LAND_SHARE_OF_PRICE) * $landBank, $captured->plantImpairment, 1.0, 'the model\'s charge is the write-down');
        $this->assertEqualsWithDelta(
            $openingNetPpe + $captured->totalReportedCapex + $captured->completedCip - $captured->quarterlyDepreciation - $captured->plantImpairment,
            $impaired->getNetPpe(),
            1.0,
            'the plant carries the land at its written-down value',
        );
        $this->assertEqualsWithDelta(
            $captured->actualQuarterlyNetIncome + $captured->quarterlyDepreciation + $captured->stockCompensation
                + $captured->inventoryWriteDown + $captured->plantImpairment + $captured->receivablesProvision
                + $captured->deferredTaxExpense - $captured->deltaWorkingCapital,
            $captured->operatingCashFlow,
            1.0,
            'the write-down is non-cash, and so is the tax benefit the loss it causes books',
        );

        // Land at its normal share recovers its cost and is not written down.
        mt_srand(5566);
        $sound = $builder('LNDB');
        $engine->calculate($sound, $macro, EarningsEngine::resolveReportingTick('LNDB', 252), 252);
        $this->assertNotNull($captured);
        $this->assertSame(0.0, $captured->plantImpairment);
    }

    /**
     * A recall or a fine the physics books is a one-off charge: GAAP EBIT carries it, while the run rate the market and
     * lenders read, the trailing year lenders underwrite on and the Street figure leave it out, as they do
     * restructuring.
     */
    public function testAnEventChargeIsASpecialItemOutsideTheRunRate(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);
        $macro = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);

        // A recall or a fine is a tail draw: walk the seeds to the first report that books one.
        $stock = null;
        for ($seed = 1; $seed <= 400; $seed++) {
            mt_srand($seed);
            $stock = $this->buildMatureIndustrial('RCLL');
            $stock->setIndustry('Packaged Foods');
            $engine->calculate($stock, $macro, EarningsEngine::resolveReportingTick('RCLL', 252), 252);
            $this->assertNotNull($captured);
            if ($captured->eventCharge > 0.0) {
                break;
            }
        }
        $this->assertGreaterThan(0.0, $captured->eventCharge, 'control: a recall or a fine fired');
        $this->assertContains($captured->eventType, [\App\Service\Event\ShockEvent::PRODUCT_RECALL, \App\Service\Event\ShockEvent::REGULATORY_FINE]);

        $this->assertEqualsWithDelta(
            $captured->actualRevenue - $captured->actualVariableCosts - $captured->fixedCosts - $captured->eventCharge - $captured->restructuringCharge - $captured->impairmentCharges,
            $captured->ebitda,
            1.0,
            'GAAP EBITDA carries the charge',
        );
        $costRatio = $captured->actualVariableCosts / $captured->actualRevenue;
        $this->assertEqualsWithDelta(
            ($captured->seasonallyAdjustedRevenue * (1.0 - $costRatio)) - ($captured->fixedCosts / $captured->seasonalFixedCostFactor)
                - $captured->quarterlyDepreciation - ($captured->impairmentCharges - $captured->plantImpairment),
            $captured->seasonallyAdjustedEbit,
            1.0,
            'the run rate leaves it out',
        );
        $history = $stock->getQuarterlyOperatingHistory() ?? [];
        $lastQuarter = end($history);
        $this->assertNotFalse($lastQuarter, 'the report entered the operating history');
        $this->assertEqualsWithDelta($captured->ebit + $captured->specialCharges, $lastQuarter['ebit'], 1.0, 'lenders add it back');
        $this->assertGreaterThanOrEqual($captured->eventCharge, $captured->specialCharges);
        $shares = max(1.0, $captured->sharesOutstanding);
        $this->assertEqualsWithDelta($captured->actualQuarterlyEps + ($captured->specialItems / $shares), $captured->streetQuarterlyEps, 1e-9, 'the Street figure leaves it out');
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
            'a persistent default rate must not re-charge the same loss every quarter',
        );
        // The allowance is a real contra-asset: what the firm expects to collect is gross less allowance.
        $allowance = (float) $stock->getReceivablesAllowance();
        $this->assertGreaterThan(0.0, $allowance);
        $this->assertEqualsWithDelta(
            (float) $stock->getReceivables() - $allowance,
            $stock->getNetReceivables(),
            1.0,
        );
    }

    /**
     * ASC 326-20-30-9: the lifetime estimate starts from the losses the book is taking now and reverts to the
     * through-the-cycle rate, so a lender charging off heavily holds a larger allowance against its book than one
     * in a calm quarter with the same forward outlook, instead of releasing reserves while the losses run.
     */
    public function testALenderTakingHeavyLossesReservesMoreAgainstItsBook(): void
    {
        $allowanceRatio = function (float $retailDefaults, float $corporateDefaults): float {
            $this->mathUtility = new MathUtility();
            $engine = $this->buildEngine(new EventDispatcher());

            mt_srand(4242);
            $stock = $this->buildMatureIndustrial('RSRV');
            $stock->setIndustry('Banks - Diversified');
            $stock->setCustomerDeposits('300000000000');
            $macro = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, retailDefaultRateEma: $retailDefaults, corporateDefaultRateEma: $corporateDefaults);
            $reportingTick = EarningsEngine::resolveReportingTick('RSRV', 252);
            for ($quarter = 0; $quarter < 4; $quarter++) {
                $engine->calculate($stock, $macro, $reportingTick + (63 * $quarter), 252);
            }

            return (float) $stock->getCreditLossAllowance() / (float) $stock->getEarningAssets();
        };

        $calm = $allowanceRatio(0.025, 0.016);
        $stressed = $allowanceRatio(0.06, 0.054);

        $this->assertGreaterThan(1.5 * $calm, $stressed);
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
        $this->assertNotNull($captured);

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
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(2468);
        $stock = $this->buildProfitableIndustrial('TIMD');
        // A plant whose tax basis still equals its book value: tax depreciation runs at twice the book rate.
        $stock->setPpeTaxBasis('30000000000');

        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('TIMD', 252), 252);
        $this->assertNotNull($captured);

        // Reported tax is the statutory rate on book income, whatever the schedule.
        $this->assertGreaterThan(0.0, $captured->preTaxIncome);
        $this->assertEqualsWithDelta($captured->preTaxIncome * 0.79, $captured->actualQuarterlyNetIncome, 1.0);

        // Cash tax is lower by the rate on the timing difference, and the difference is the liability.
        $this->assertGreaterThan(0.0, $captured->deferredTaxExpense);
        $this->assertEqualsWithDelta($captured->taxPaid - $captured->deferredTaxExpense, $captured->cashTaxPaid, 1.0);
        $this->assertEqualsWithDelta($captured->deferredTaxExpense, (float) $stock->getDeferredTaxLiability(), 1.0);
    }

    /**
     * A going concern's plant opens mid-life on the tax books too: at the steady-state basis (g + d)/(g + 2d) of book,
     * with the deferred tax on the gap booked against retained earnings. Opening at book value started every listing
     * with no deferral and a cash tax rate far below its steady state for a decade.
     */
    public function testAPlantLedgerOpensAtItsSteadyStateTaxBasis(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        mt_srand(9753);
        $stock = $this->buildProfitableIndustrial('TXBS');
        $this->assertNull($stock->getPpeTaxBasis(), 'control: the ledger has no tax basis yet');

        $this->buildEngine($dispatcher)->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, inflationEma: 0.02), EarningsEngine::resolveReportingTick('TXBS', 252), 252);
        $this->assertNotNull($captured);

        $ratio = (float) $stock->getPpeTaxBasis() / $stock->getNetPpe();
        $this->assertGreaterThan(0.5, $ratio);
        $this->assertLessThan(0.95, $ratio, 'the tax books run ahead of the book ones');
        $this->assertLessThan(0.0, $captured->cumulativeEffectAdjustment, 'the opening deferral is charged to retained earnings');
        $this->assertGreaterThan(0.21 * ($stock->getNetPpe() - (float) $stock->getPpeTaxBasis()) * 0.9, (float) $stock->getDeferredTaxLiability());
    }

    /**
     * A row carrying a loss from before the rule recognizes the asset that loss already was against retained earnings
     * on its first report (ASC 250 cumulative effect), not as that quarter's income, and the sheet balances.
     */
    public function testACarriedLossOpensItsAssetAgainstRetainedEarnings(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(8643);
        $stock = $this->buildMatureIndustrial('ODTA');
        $stock->setNetOperatingLoss('1000000000');
        $this->assertNull($stock->getPretaxIncomeHistory(), 'control: the row predates the rule');
        $retainedBefore = (float) $stock->getRetainedEarnings();

        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('ODTA', 252), 252);
        $this->assertNotNull($captured);

        $this->assertCount(EarningsEngine::VALUATION_ALLOWANCE_LOOKBACK_QUARTERS, $stock->getPretaxIncomeHistory() ?? []);
        $this->assertEqualsWithDelta(0.21 * (float) $stock->getNetOperatingLoss(), $stock->getDeferredTaxAsset(), 1.0);
        $this->assertEqualsWithDelta($captured->preTaxIncome * 0.79, $captured->actualQuarterlyNetIncome, 1.0, 'the quarter is taxed at the rate; the opening asset is not income');
        // Retained earnings move by the quarter's income less its dividend plus the opening balances, and nothing else.
        $this->assertEqualsWithDelta(
            $retainedBefore + $captured->actualQuarterlyNetIncome - (float) ($captured->allocation['total_paid'] ?? 0.0) + $captured->cumulativeEffectAdjustment,
            (float) $stock->getRetainedEarnings(),
            1.0,
        );
    }

    /**
     * Through the engine: a loss quarter prints at (1 - t) of its pre-tax loss with the benefit on the asset, and the
     * asset and the equity it backs move together.
     */
    public function testALossQuarterBooksItsBenefitAsAnAssetAndTheSheetBalances(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(8642);
        $stock = $this->buildMatureIndustrial('LDTA');
        $stock->setOperatingMargin('0.01');
        $stock->setNetOperatingLoss('1000000000');
        // Three profitable years on record, so this loss is not a cumulative one and no allowance applies.
        $stock->setPretaxIncomeHistory(array_fill(0, 11, 2_000_000_000.0));
        $stock->setDeferredTaxAsset(0.21 * 1_000_000_000.0);
        $equityBefore = (float) $stock->getTotalEquity();
        $macroState = new MacroStateDTO(outputGapEma: -0.08, corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);

        $engine->calculate($stock, $macroState, EarningsEngine::resolveReportingTick('LDTA', 252), 252);
        $this->assertNotNull($captured);

        $this->assertLessThan(0.0, $captured->preTaxIncome, 'fixture must report a loss');
        $this->assertEqualsWithDelta($captured->preTaxIncome * 0.79, $captured->actualQuarterlyNetIncome, 1.0, 'the loss prints net of its tax benefit');
        $this->assertEqualsWithDelta(0.21 * (float) $stock->getNetOperatingLoss(), $stock->getDeferredTaxAsset(), 1.0, 'the carryforward is carried at the rate');
        $this->assertGreaterThan(0.21 * 1_000_000_000.0, $stock->getDeferredTaxAsset());

        $strategy = Sectors::getBusinessModelStrategy(Sectors::businessModelFor('Auto Manufacturers'));
        $lease = (new CorporateMetrics())->resolveLeaseLiability($stock, (float) $stock->getTotalRevenue(), $strategy->getLeaseIntensity($stock));
        $this->assertEqualsWithDelta(
            $stock->getTotalAssets($lease),
            $stock->getTotalLiabilities($lease) + (float) $stock->getTotalEquity(),
            max(1.0, $stock->getTotalAssets($lease) * 1e-6),
            'the asset and the equity it backs moved together',
        );
        $this->assertNotEquals($equityBefore, (float) $stock->getTotalEquity());
    }

    /**
     * ASC 740: a loss prints at (1 - t) of itself, its tax benefit carried as a deferred tax asset on the carryforward,
     * and a profit that uses the carryforward is still taxed at the statutory rate on the books, with only the cash
     * tax falling. Without the asset a loss quarter printed t/(1 - t) deeper and the recovery quarters that used it
     * printed above the statutory rate, both on earnings that were only timing.
     */
    public function testALossBooksADeferredTaxAssetAndItsRecoveryIsTaxedAtTheStatutoryRate(): void
    {
        $loss = EarningsEngine::resolveIncomeTax(-100.0, 0.0, 0.0, false, 0.21);
        $this->assertSame(0.0, $loss['current']);
        $this->assertEqualsWithDelta(-21.0, $loss['deferred'], 1e-12, 'the loss is a tax benefit');
        $this->assertEqualsWithDelta(21.0, $loss['dta'], 1e-12);
        $this->assertEqualsWithDelta(100.0, $loss['nol'], 1e-12);

        $recovery = EarningsEngine::resolveIncomeTax(50.0, $loss['nol'], $loss['dta'], false, 0.21);
        $this->assertEqualsWithDelta(0.21 * 50.0 * (1.0 - EarningsEngine::NOL_MAX_SHIELD_RATIO), $recovery['current'], 1e-12, 'cash tax falls by the shield');
        $this->assertEqualsWithDelta(0.21 * 50.0, $recovery['current'] + $recovery['deferred'], 1e-12, 'book tax stays at the statutory rate');

        // A run of losses (the valuation allowance) gets no benefit, and the asset already on the books is written off.
        $allowance = EarningsEngine::resolveIncomeTax(-100.0, 100.0, 21.0, true, 0.21);
        $this->assertSame(0.0, $allowance['dta']);
        $this->assertEqualsWithDelta(21.0, $allowance['current'] + $allowance['deferred'], 1e-12, 'the allowance is a charge, not a benefit');

        // A rate cut remeasures the asset at the new rate (ASC 740-10-35-4).
        $remeasured = EarningsEngine::resolveIncomeTax(0.0, 100.0, 21.0, false, 0.15);
        $this->assertEqualsWithDelta(6.0, $remeasured['deferred'], 1e-12);
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
        $this->assertNotNull($captured);

        $this->assertLessThan(0.0, $captured->deferredTaxExpense, 'the deferral must reverse once the shelter is gone');
        $this->assertGreaterThan($captured->taxPaid, $captured->cashTaxPaid, 'cash tax must exceed the book charge on reversal');
        $this->assertLessThan(5_000_000_000.0, (float) $stock->getDeferredTaxLiability(), 'the liability must unwind');
    }

    /**
     * A write-down is deducted for tax once. The tax books allow it only through the depreciation still to come on the
     * basis it never left, so the quarter it is booked adds it back to taxable income and carries it as a deductible
     * difference against the deferred liability, rather than cutting the cash tax now and again as the basis runs off.
     */
    public function testAPlantWriteDownIsDeductedForTaxOnce(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(5566);
        $stock = $this->buildMatureIndustrial('LNDT');
        $stock->setIndustry('Residential Construction');
        $stock->setOperatingMargin('0.13');
        $stock->setFixedCostRatio(0.10);
        $stock->setGrossPpe('120000000000');
        $stock->setAccumulatedDepreciation('5000000000');
        $stock->setReceivables('0');
        $stock->setInventory('15000000000');
        $stock->setPayables('0');
        $stock->setDepreciationRate('0.05');
        $stock->setPpeTaxBasis('100000000000');
        $stock->setDeferredTaxLiability('50000000000');
        // Land carried at 55% of the price, well above what a home now recovers for it.
        $stock->setEarningsMomentumZ([HomebuilderBusinessModel::STATE_LAND_COST => 0.55]);

        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('LNDT', 252), 252);
        $this->assertNotNull($captured);

        $rate = $captured->corporateTaxRate;
        $recurring = $captured->taxDepreciation - $captured->quarterlyDepreciation;
        $this->assertGreaterThan(0.0, $captured->plantImpairment, 'the land must be written down for the test to have power');
        $this->assertEqualsWithDelta(100.0e9 * 0.10 / 4.0, $captured->taxDepreciation, 1.0, 'tax depreciation runs on the basis the write-down never left');
        $this->assertEqualsWithDelta($captured->preTaxIncome - $recurring + $captured->plantImpairment, $captured->taxableIncome, 1.0, 'the write-down is added back to taxable income');
        $this->assertEqualsWithDelta(50.0e9 + ($rate * ($recurring - $captured->plantImpairment)), (float) $stock->getDeferredTaxLiability(), 1.0, 'and carried as a deductible difference');
    }

    /**
     * A change in the tax rate re-strikes the plant's deferred balance (ASC 740-10-35-4): the gap between its book
     * value and its tax basis is carried at today's rate, so the change lands once, as a deferred charge the estimate
     * carries too. A row struck before the rate was recorded is taken to have been struck at today's.
     */
    public function testATaxRateChangeReStrikesThePlantsDeferredBalance(): void
    {
        $run = function (?float $struckRate): array {
            $captured = null;
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
                $captured = $event->getContext();
            });
            // A fresh generator per run: a Box-Muller spare left over from the first run would shift the second's
            // draws.
            $this->mathUtility = new MathUtility();
            $engine = $this->buildEngine($dispatcher);

            mt_srand(2470);
            $stock = $this->buildProfitableIndustrial('RATE');
            // Book 30bn against a 20bn tax basis: a 10bn gap the liability is struck on.
            $stock->setPpeTaxBasis('20000000000');
            $stock->setDeferredTaxLiability('10000000000');
            if ($struckRate !== null) {
                $stock->setEarningsMomentumZ([EarningsEngine::STATE_PLANT_DEFERRED_TAX_RATE => $struckRate]);
            }
            $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('RATE', 252), 252);
            $this->assertInstanceOf(EarningsSimulationContext::class, $captured);

            return [$captured, $stock];
        };

        [$legacy, $legacyStock] = $run(null);
        $rate = $legacy->corporateTaxRate;
        [$same, $sameStock] = $run($rate);
        [$risen, $risenStock] = $run($rate - 0.05);

        $this->assertEqualsWithDelta((float) $sameStock->getDeferredTaxLiability(), (float) $legacyStock->getDeferredTaxLiability(), 1.0, 'an old row books no one-off');
        $this->assertEqualsWithDelta(0.05 * 10.0e9, (float) $risenStock->getDeferredTaxLiability() - (float) $sameStock->getDeferredTaxLiability(), 1.0);
        $this->assertEqualsWithDelta(0.05 * 10.0e9, $risen->deferredTaxExpense - $same->deferredTaxExpense, 1.0);
        $this->assertEqualsWithDelta(-0.05 * 10.0e9, $risen->actualQuarterlyNetIncome - $same->actualQuarterlyNetIncome, 1.0);
        $this->assertEqualsWithDelta(
            -0.05 * 10.0e9,
            $risen->expectedQuarterlyNetIncome - $same->expectedQuarterlyNetIncome,
            1.0,
            'the law is public, so the estimate carries the re-strike, and the walk-down is struck on revenue it does not move',
        );
        $this->assertSame($rate, ($risenStock->getEarningsMomentumZ() ?? [])[EarningsEngine::STATE_PLANT_DEFERRED_TAX_RATE] ?? null);
    }

    /**
     * The run rate the payout and buyback tests read is taxed as the quarter is: a firm whose books recognise its loss
     * carryforward prints (1 - t) of a pretax loss, not the whole of it.
     */
    public function testALossMakingRunRateCarriesItsTaxBenefit(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(3131);
        $stock = $this->buildMatureIndustrial('RUNL');
        // A plant that turns over a tenth of its capital a year cannot earn the coupon on the debt that funds it.
        $stock->setAssetTurnover('0.10');
        $stock->setWholesaleDebt('100000000000');
        // Three profitable years behind it, so the loss is recognised with no valuation allowance.
        $stock->setPretaxIncomeHistory(array_fill(0, EarningsEngine::VALUATION_ALLOWANCE_LOOKBACK_QUARTERS - 1, 5.0e9));

        $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04), EarningsEngine::resolveReportingTick('RUNL', 252), 252);
        $this->assertNotNull($captured);

        $runRateEbt = $captured->seasonallyAdjustedEbit - $captured->quarterlyInterestExpense + $captured->quarterlyInterestIncome;
        $this->assertLessThan(0.0, $runRateEbt, 'the run rate must be a loss for the test to have power');
        $this->assertEqualsWithDelta($runRateEbt * (1.0 - $captured->corporateTaxRate) * 4.0 / max(1.0, $captured->sharesOutstanding), $captured->actualAnnualEpsRaw, 1e-9);
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
            1.0,
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
            $this->assertNotNull($captured);

            $statement = $captured->operatingCashFlow + $captured->investingCashFlow + $captured->financingCashFlow;
            $this->assertEqualsWithDelta($cashAfter - $cashBefore, $statement, 1.0, "cash flow statement failed to reconcile in Q{$quarter}");

            // And the financing section is built from cash that moved, never from a share count times a price.
            $allocation = $captured->allocation;
            $this->assertEqualsWithDelta(
                ($debtAfter = (float) $stock->getWholesaleDebt()) - $debtBefore
                    + (float) $allocation['equity_raised'] - (float) $allocation['total_cash_spent'] - (float) $allocation['total_paid'],
                $captured->financingCashFlow,
                1.0,
                "financing section is not the sum of its cash legs in Q{$quarter}",
            );
        }
    }

    /**
     * Repaying the committed line is a financing outflow like retiring a note (ASC 230-10-45-15). A firm that opens
     * the quarter with its revolver drawn and cash above its floor sweeps the line, and the statement still has to
     * reconcile to the cash that left.
     */
    public function testARevolverSweepIsAFinancingOutflowAndTheStatementStillReconciles(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        mt_srand(4242);
        $stock = $this->buildMatureIndustrial('RVSW');
        $stock->setRevolverCommitment('10000000000');
        $stock->setRevolverDrawn('5000000000');
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, inflationEma: 0.02, gdpDeflator: 1.0);

        $cashBefore = (float) $stock->getCorporateTreasury();
        $engine->calculate($stock, $macroState, EarningsEngine::resolveReportingTick('RVSW', 252), 252);
        $this->assertNotNull($captured);

        $swept = 5_000_000_000.0 - (float) $stock->getRevolverDrawn();
        $this->assertGreaterThan(0.0, $swept, 'cash above the floor repays the drawn line');
        $this->assertEqualsWithDelta(
            (float) $stock->getCorporateTreasury() - $cashBefore,
            $captured->operatingCashFlow + $captured->investingCashFlow + $captured->financingCashFlow,
            1.0,
            'the sweep is cash that left through financing',
        );
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
        $lease = (new CorporateMetrics())->resolveLeaseLiability($stock, (float) $stock->getTotalRevenue(), $captured->strategy->getLeaseIntensity($stock));
        // Sloan measures REPORTED earnings against operating cash, so the managed entry belongs in the
        // numerator alongside net income — steering the headline with accruals is precisely the low-quality
        // earnings the anomaly detects. It is only ever zero on a quarter the firm had no reason to steer.
        $expected = ((($captured->actualQuarterlyNetIncome + $captured->managedAccrual) - $captured->operatingCashFlow) * 4.0) / $stock->getTotalAssets($lease);

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

        // Fiscal Q1 (filed in the Q2 season): no test, goodwill untouched even though returns trail the hurdle.
        $q1 = $build('GDWL');
        $this->earningsEngine->calculate($q1, $macroState, $ticksPerQuarter + EarningsEngine::resolveReportingTick('GDWL', 252));
        $this->assertEqualsWithDelta(5_000_000_000.0, (float) $q1->getGoodwill(), 1.0);

        // Fiscal Q4 (filed in the next year's first season): the annual test writes the shortfall off against goodwill
        // and equity.
        $q4 = $build('GDWL');
        $equityBefore = (float) $q4->getTotalEquity();
        $q4Tick = ((EarningsEngine::FISCAL_YEAR_END_QUARTER + 1) * $ticksPerQuarter) + EarningsEngine::resolveReportingTick('GDWL', 252);
        $this->earningsEngine->calculate($q4, $macroState, $q4Tick);

        $impairment = 5_000_000_000.0 - (float) $q4->getGoodwill();
        $this->assertGreaterThan(0.0, $impairment, 'goodwill must be impaired when trailing ROIC sits below WACC');
        $this->assertLessThanOrEqual(5_000_000_000.0, $impairment);
        $this->assertLessThan($equityBefore, (float) $q4->getTotalEquity(), 'the write-down is charged against equity');
    }

    /**
     * The single-step test (ASU 2017-04) writes book equity down to the reporting unit's fair value and no further:
     * the charge is the carrying amount over fair value, capped at goodwill, so a thin-equity acquirer loses the
     * excess and keeps a book equal to what it is worth. Goodwill leaves the asset side and the equity that carried
     * it leaves the other, so the sheet still balances.
     */
    public function testGoodwillImpairmentWritesBookDownToFairValueAndTheSheetStillBalances(): void
    {
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, equityRiskPremium: 0.05);
        $ticksPerQuarter = 63;

        // Thin equity under a large goodwill balance: the annual test writes off more than the book is worth.
        // Debt keeps invested capital large enough that the seeded plant is real rather than the floor.
        $stock = $this->buildMatureIndustrial('NEGQ');
        $stock->setTotalEquity('3000000000');
        $stock->setWholesaleDebt('40000000000');
        // The cash its $80B of revenue needs and no more: surplus cash is worth its face beside the business.
        $stock->setCorporateTreasury('4000000000');
        $stock->setGoodwill('5000000000');
        $stock->setOperatingMargin('0.03');
        $stock->setBaselineRoic('0.02');
        $stock->setRoicTtm('0.02');
        $stock->setLifecycleStage(LifecycleStage::Mature);

        $q4Tick = ((EarningsEngine::FISCAL_YEAR_END_QUARTER + 1) * $ticksPerQuarter) + EarningsEngine::resolveReportingTick('NEGQ', 252);
        $this->earningsEngine->calculate($stock, $macroState, $q4Tick);

        $impairment = 5_000_000_000.0 - (float) $stock->getGoodwill();
        $this->assertGreaterThan(0.0, $impairment, 'a reporting unit carried above its fair value is impaired');
        $this->assertLessThan(3_000_000_000.0, $impairment, 'the charge stops at fair value: it cannot write equity off below what the unit is worth');

        $businessModel = Sectors::INDUSTRY_METRICS['Auto Manufacturers']['business_model'] ?? 'none';
        $strategy = Sectors::getBusinessModelStrategy($businessModel);
        $lease = (new CorporateMetrics())->resolveLeaseLiability($stock, (float) $stock->getTotalRevenue(), $strategy->getLeaseIntensity($stock));
        $assets = $stock->getTotalAssets($lease);
        $claims = $stock->getTotalLiabilities($lease) + (float) $stock->getTotalEquity();
        $this->assertEqualsWithDelta($assets, $claims, max(1.0, $assets * 1e-6), 'the balance sheet still balances after the write-off');
    }

    /**
     * A write-down is reported, not missed: the surprise is struck on Street earnings, before special items (Bradshaw &
     * Sloan 2002), since consensus never forecasts them. GAAP EPS carries the impairment; the beat or miss does not.
     */
    public function testAGoodwillWriteDownLeavesTheStreetFigureAndTheSurpriseAlone(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $stock = $this->buildMatureIndustrial('STRT');
        $stock->setTotalEquity('3000000000');
        $stock->setWholesaleDebt('40000000000');
        // The cash its $80B of revenue needs and no more: surplus cash is worth its face beside the business.
        $stock->setCorporateTreasury('4000000000');
        $stock->setGoodwill('5000000000');
        $stock->setOperatingMargin('0.03');
        $stock->setBaselineRoic('0.02');
        $stock->setRoicTtm('0.02');
        $stock->setLifecycleStage(LifecycleStage::Mature);

        $q4Tick = ((EarningsEngine::FISCAL_YEAR_END_QUARTER + 1) * 63) + EarningsEngine::resolveReportingTick('STRT', 252);
        $this->buildEngine($dispatcher)->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, equityRiskPremium: 0.05), $q4Tick);
        $this->assertNotNull($captured);

        $this->assertGreaterThan(0.0, $captured->goodwillImpairment, 'control: the annual test wrote goodwill down');
        $shares = max(1.0, $captured->sharesOutstanding);
        $this->assertEqualsWithDelta($captured->actualQuarterlyEps + ($captured->specialItems / $shares), $captured->streetQuarterlyEps, 1e-9);
        $this->assertGreaterThanOrEqual($captured->goodwillImpairment, $captured->specialItems);
        $this->assertEqualsWithDelta($captured->streetQuarterlyEps - $captured->expectedQuarterlyEps, $captured->surpriseAmountQuarterly, 1e-9);
    }

    /**
     * The test is fair value against carrying amount, not trailing return against the hurdle: a reporting unit the
     * market values above its book keeps its goodwill. The old value-in-use capitalized one trailing year's return at
     * the hurdle forever, so a firm earning a little under its cost of capital wrote its acquisitions off whatever the
     * market thought the business was worth.
     */
    public function testAUnitValuedAboveItsBookKeepsItsGoodwill(): void
    {
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, equityRiskPremium: 0.05);
        $stock = $this->buildMatureIndustrial('GDCY');
        $stock->setGoodwill('5000000000');
        $stock->setTotalEquity('10000000000');
        // A business the market prices on its long-run return, in a trailing year under the hurdle: the old rule
        // impaired exactly this.
        $stock->setBaselineRoic('0.15');
        $stock->setLongRunReturn('0.15');
        $stock->setRoicTtm('0.06');
        $stock->setLifecycleStage(LifecycleStage::Mature);

        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $q4Tick = ((EarningsEngine::FISCAL_YEAR_END_QUARTER + 1) * 63) + EarningsEngine::resolveReportingTick('GDCY', 252);
        $equityBefore = (float) $stock->getTotalEquity();
        $this->buildEngine($dispatcher)->calculate($stock, $macroState, $q4Tick);
        $this->assertNotNull($captured);

        $this->assertGreaterThan($equityBefore, $captured->fairValueBeforeReport * $captured->sharesOutstanding, 'control: the market values the firm above its book');
        $this->assertSame(0.0, $captured->goodwillImpairment);
        $this->assertEqualsWithDelta(5_000_000_000.0, (float) $stock->getGoodwill(), 1.0);
    }

    /**
     * The price level reaches revenue the quarter it moves: plant booked at historical cost sells at today's prices,
     * so its capacity is struck on current cost (the replacement-cost ratio). Before this a 10% jump in the price
     * level left nominal revenue where it was, and real revenue fell one for one until years of capex restated the
     * book. Working capital is already at current prices, so only the plant's share of capital is revalued.
     */
    public function testAJumpInThePriceLevelReachesRevenueCapacityThroughThePlant(): void
    {
        $run = function (float $deflator): EarningsSimulationContext {
            $captured = null;
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
                $captured = $event->getContext();
            });
            mt_srand(7531);
            $stock = $this->buildProfitableIndustrial('PLVL');
            // The last report saw a price level of 1.0, and no inflation was expected: the whole move is a surprise.
            $stock->setEarningsMomentumZ([EarningsEngine::STATE_LAST_DEFLATOR => 1.0]);
            $this->buildEngine($dispatcher)->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, gdpDeflator: $deflator, inflationEma: 0.0), EarningsEngine::resolveReportingTick('PLVL', 252), 252);
            $this->assertNotNull($captured);

            return $captured;
        };

        $flat = $run(1.0);
        $jumped = $run(1.10);
        $plantShare = $flat->stock->getNetPpe() / $flat->revenueGeneratingCapital;

        $this->assertGreaterThan(0.5, $plantShare, 'control: the fixture is mostly plant');
        $this->assertEqualsWithDelta(1.0 + (0.10 * $plantShare), $jumped->revenueGeneratingCapital / $flat->revenueGeneratingCapital, 0.01);
        $this->assertGreaterThan($flat->structuralRevenue * 1.05, $jumped->structuralRevenue);
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

    /**
     * Goodwill is the premium paid over the identifiable assets and runs no plant. Two firms with the same
     * operating capital, one also carrying goodwill (and the equity that paid for it), have the same revenue
     * capacity — which is also what keeps an impairment from deleting revenue while the debt that paid for
     * the deal stays.
     */
    public function testGoodwillAddsNoRevenueCapacity(): void
    {
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);

        $run = function (float $goodwill) use ($macroState): object {
            $captured = null;
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
                $captured = $event->getContext();
            });
            $this->mathUtility = new MathUtility();
            $engine = $this->buildEngine($dispatcher);

            $stock = $this->buildMatureIndustrial('GWCP');
            $stock->setAssetTurnover('1.4000');
            $stock->setGoodwill((string) $goodwill);
            $stock->setTotalEquity((string) (50_000_000_000.0 + $goodwill));
            $stock->setLifecycleStage(LifecycleStage::Mature);

            mt_srand(4321);
            $engine->calculate($stock, $macroState, EarningsEngine::resolveReportingTick('GWCP', 252));
            $this->assertNotNull($captured);

            return $captured;
        };

        $plain = $run(0.0);
        $acquisitive = $run(12_000_000_000.0);

        $this->assertEqualsWithDelta($plain->structuralRevenue, $acquisitive->structuralRevenue, 1.0);
        $this->assertEqualsWithDelta($plain->expectedRevenue, $acquisitive->expectedRevenue, 1.0);
    }

    /**
     * The working capital ledger is rebuilt to its new balances every quarter, so the cash that funds or is
     * released by the change has to move by exactly that change. Clamping the cash leg to a quarter of
     * revenue left the excess on the balance sheet with no account behind it. Seeding the trade cycle off
     * a recorded revenue three times the plant's capacity forces a release far past that old clamp.
     */
    public function testAWorkingCapitalReleaseReachesCashInFull(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        $stock = $this->buildMatureIndustrial('WCRL');
        $stock->setTotalRevenue('240000000000');
        $stock->setLifecycleStage(LifecycleStage::Mature);

        mt_srand(2468);
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);
        $engine->calculate($stock, $macroState, EarningsEngine::resolveReportingTick('WCRL', 252));
        $this->assertNotNull($captured);

        $strategy = Sectors::getBusinessModelStrategy(Sectors::INDUSTRY_METRICS['Auto Manufacturers']['business_model']);
        $lease = (new CorporateMetrics())->resolveLeaseLiability($stock, (float) $stock->getTotalRevenue(), $strategy->getLeaseIntensity($stock));
        $assets = $stock->getTotalAssets($lease);
        $claims = $stock->getTotalLiabilities($lease) + (float) $stock->getTotalEquity();
        $this->assertEqualsWithDelta($assets, $claims, max(1.0, $assets * 1e-9), 'the released working capital must land in cash');

        $annualizedRevenue = $captured->actualRevenue / FinancialConstants::QUARTER_YEARS;
        $this->assertLessThan(-0.25 * $annualizedRevenue, $captured->deltaWorkingCapital, 'the fixture must release more than the old clamp allowed');
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
            'Trailing net income must equal the sum of the last four reported quarters.',
        );

        // EPS is derived from that figure over current shares, so the screener reads real trailing earnings.
        $this->assertEqualsWithDelta(
            array_sum($lastFour) / (float) $stock->getSharesOutstanding(),
            (float) $stock->getEarningsPerShare(),
            1e-6,
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
        $this->assertNotNull($history);
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

    /**
     * A report is a scheduled jump: its move, not its dividend, is booked into the name's announcement variance,
     * annualized, which the price process then gives back from its diffusion.
     */
    public function testAReportBooksItsMoveAsAnnouncementVariance(): void
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });

        $stock = $this->buildMatureIndustrial('ANNV');
        $stock->setLifecycleStage(LifecycleStage::Mature);
        $this->assertSame(0.0, $stock->getAnnouncementVarianceEma());

        mt_srand(777);
        $this->buildEngine($dispatcher)->calculate(
            $stock,
            new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04),
            EarningsEngine::resolveReportingTick('ANNV', 252),
        );

        $this->assertNotNull($captured);
        $this->assertNotSame(0.0, $captured->totalShockPct, 'the fixture must report a surprise for this test to have power');

        $weight = 1.0 - exp(-FinancialConstants::QUARTER_YEARS / EarningsEngine::ANNOUNCEMENT_VARIANCE_EMA_YEARS);
        $this->assertEqualsWithDelta(
            $weight * (log(1.0 + $captured->totalShockPct) ** 2) / FinancialConstants::QUARTER_YEARS,
            (float) $stock->getAnnouncementVarianceEma(),
            1e-12,
        );
    }

    /**
     * Reports alone settle the estimate on reports per year times the squared move; a warning between reports adds
     * its own squared move at the same weight without a second decay, so a quarter with a warning counts both.
     */
    public function testAnnouncementVarianceSettlesOnTheAnnualRateOfSquaredMoves(): void
    {
        $engine = $this->buildEngine($this->createStub(EventDispatcherInterface::class));
        $record = new \ReflectionMethod($engine, 'recordAnnouncementVariance');
        $stock = new Stock();
        $move = 0.05;

        for ($quarter = 0; $quarter < 400; $quarter++) {
            $record->invoke($engine, $stock, $move, true);
        }
        $this->assertEqualsWithDelta(($move ** 2) / FinancialConstants::QUARTER_YEARS, (float) $stock->getAnnouncementVarianceEma(), 1e-12);

        for ($quarter = 0; $quarter < 400; $quarter++) {
            $record->invoke($engine, $stock, $move, false);
            $record->invoke($engine, $stock, $move, true);
        }
        $this->assertEqualsWithDelta(2.0 * ($move ** 2) / FinancialConstants::QUARTER_YEARS, (float) $stock->getAnnouncementVarianceEma(), 1e-9);
    }

    private function pricingPowerMultiplier(Stock $stock, MacroStateDTO $macroState): float
    {
        $industry = $stock->getIndustry();
        $this->assertNotNull($industry);
        $businessModel = Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';

        return Sectors::getBusinessModelStrategy($businessModel)->getMacroPhysics($stock, $macroState)['pricing_power_multiplier'];
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
            corporateTaxRate: 0.21,
            policyRate: 0.04,
            policyRateEma: 0.04,
            yield2yEma: 0.04,
            yield5yEma: 0.042,
            yield10yEma: 0.045,
            equityRiskPremium: 0.05,
            nominalGdpIndex: 1.0,
            macroCreditSpreadEma: 0.015,
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

        $strategy = Sectors::getBusinessModelStrategy('commercial_bank');
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

        $lease = (new CorporateMetrics())->resolveLeaseLiability($stock, (float) $stock->getTotalRevenue(), $strategy->getLeaseIntensity($stock));
        $assets = $stock->getTotalAssets($lease);
        $claims = $stock->getTotalLiabilities($lease) + (float) $stock->getTotalEquity();
        $this->assertEqualsWithDelta($assets, $claims, max(1.0, $assets * 1e-6), 'a bank balance sheet balances');

        // The three cash flow sections still sum to the cash that moved, deposits included.
        $this->assertEqualsWithDelta(
            (float) $stock->getCorporateTreasury() - 5_000_000_000.0,
            $captured->operatingCashFlow + $captured->investingCashFlow + $captured->financingCashFlow,
            max(1.0, abs($assets) * 1e-6),
            'operating, investing and financing flows reconcile to the change in cash',
        );
    }

    /**
     * A lender's cost base is struck on its book, not on its gross interest income (FDIC: noninterest expense ~2.6% of
     * assets through rate cycles). The same bank at a 1% and a 5% policy rate carries the same structural cost, its
     * book times the operating cost ratio, while its revenue rises with the funding cost it passes through.
     */
    public function testALendersCostBaseDoesNotMoveWithTheRateLevel(): void
    {
        $run = function (float $policyRate): EarningsSimulationContext {
            $captured = null;
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
                $captured = $event->getContext();
            });
            $stock = $this->buildCardLender('RATE');
            $stock->setIndustry('Banks - Diversified');
            mt_srand(20261006);
            $this->buildEngine($dispatcher)->calculate($stock, new MacroStateDTO(
                corporateTaxRate: 0.21,
                policyRate: $policyRate,
                policyRateEma: $policyRate,
                yield2yEma: $policyRate,
                yield5yEma: $policyRate + 0.002,
                yield10yEma: $policyRate + 0.005,
                equityRiskPremium: 0.05,
                nominalGdpIndex: 1.0,
                macroCreditSpreadEma: 0.015,
            ), EarningsEngine::resolveReportingTick('RATE', 252));
            $this->assertNotNull($captured);

            return $captured;
        };
        $structuralCost = static fn(EarningsSimulationContext $ctx): float => ($ctx->fixedCosts / $ctx->committedCostScale)
            + ($ctx->baselineVariableMargin * $ctx->structuralRevenue) + $ctx->structuralDepreciation;

        $low = $run(0.01);
        $high = $run(0.05);
        $expected = $low->revenueGeneratingCapital * CommercialBankBusinessModel::OPERATING_COST_TO_EARNING_ASSETS / EarningsEngine::TTM_QUARTERS;

        $this->assertEqualsWithDelta($expected, $structuralCost($low), $expected * 1e-6, 'a quarter of the book times the cost ratio');
        $this->assertEqualsWithDelta($structuralCost($low), $structuralCost($high), $expected * 1e-6, 'four points of policy rate cost a bank nothing in branches');
        $this->assertGreaterThan($low->structuralRevenue * 1.1, $high->structuralRevenue, 'the funding cost passes into revenue');
    }

    /**
     * A lender whose credit physics is a margin shock reports no dollar charge-offs. Its allowance must still
     * hold at the lifetime target rather than ratchet up on a charge nothing consumes, and the provision it
     * reports is the through-the-cycle loss on its book.
     */
    public function testCardLenderAllowanceHoldsAtItsLifetimeTargetWithoutDollarChargeOffs(): void
    {
        $macroState = new MacroStateDTO(
            corporateTaxRate: 0.21,
            policyRate: 0.04,
            policyRateEma: 0.04,
            yield2yEma: 0.04,
            yield5yEma: 0.042,
            yield10yEma: 0.045,
            equityRiskPremium: 0.05,
            nominalGdpIndex: 1.0,
            macroCreditSpreadEma: 0.015,
        );
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        $stock = $this->buildCardLender('CARD');

        $strategy = Sectors::getBusinessModelStrategy('credit_services');
        $lifetimeRate = $strategy->getThroughTheCycleCreditLossRate() * $strategy->getCreditLossHorizonYears();

        mt_srand(20260909);
        for ($quarter = 1; $quarter <= 4; $quarter++) {
            // Losses are struck on the gross book the quarter opens with (the first report seeds it); the funding the
            // quarter deploys lands after them.
            $openingBook = $stock->hasEarningAssetLedger() ? (float) $stock->getEarningAssets() : null;
            $engine->calculate($stock, $macroState, (($quarter - 1) * 63) + EarningsEngine::resolveReportingTick('CARD', 252));
            $this->assertNotNull($captured);
            $book = (float) $stock->getEarningAssets();
            $this->assertEqualsWithDelta($lifetimeRate, (float) $stock->getCreditLossAllowance() / $book, $lifetimeRate * 0.10, "allowance drifted from target in Q{$quarter}");
            if ($openingBook !== null) {
                $this->assertEqualsWithDelta($strategy->getThroughTheCycleCreditLossRate() / 4.0 * $openingBook, $captured->netChargeOffs, $captured->netChargeOffs * 0.005, 'with defaults at trend, losses run at the through-the-cycle rate');
            }
        }
        $this->assertGreaterThan(0.04, $lifetimeRate, 'a card book reserves several percent of receivables');
    }

    /**
     * The reserve build a widening spread forces is public: the lifetime loss target moves with published spreads and
     * recession odds, so analysts carry the build the allowance roll-forward books toward it. Charged at the
     * through-the-cycle rate alone, a lender missed by the whole build in every quarter the outlook worsened.
     */
    public function testAnalystsCarryTheReserveBuildAWideningSpreadForces(): void
    {
        $provision = function (float $spread): array {
            $captured = null;
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
                $captured = $event->getContext();
            });
            $this->mathUtility = new MathUtility();
            $engine = $this->buildEngine($dispatcher);
            $lender = $this->buildCardLender('CARD');
            $macro = fn(float $spreadEma): MacroStateDTO => new MacroStateDTO(
                corporateTaxRate: 0.21,
                policyRate: 0.04,
                policyRateEma: 0.04,
                yield2yEma: 0.04,
                yield5yEma: 0.042,
                yield10yEma: 0.045,
                equityRiskPremium: 0.05,
                nominalGdpIndex: 1.0,
                macroCreditSpreadEma: $spreadEma,
                macroCreditSpreadTrend: 0.015,
            );
            mt_srand(20260912);
            // The first report seeds the allowance at its target; the next one meets the spread.
            $tick = EarningsEngine::resolveReportingTick('CARD', 252);
            $engine->calculate($lender, $macro(0.015), $tick);
            $engine->calculate($lender, $macro($spread), $tick + 63);
            $this->assertInstanceOf(EarningsSimulationContext::class, $captured);

            return [$captured->expectedCreditLossProvision, $captured->creditLossProvision];
        };

        [$calmExpected, $calmActual] = $provision(0.015);
        [$wideExpected, $wideActual] = $provision(0.035);

        $this->assertGreaterThan($calmActual * 1.05, $wideActual, 'the wider spread must build the reserve for the test to have power');
        $this->assertEqualsWithDelta($wideActual - $calmActual, $wideExpected - $calmExpected, 0.05 * ($wideActual - $calmActual), 'the build is in the estimate');
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
            corporateTaxRate: 0.21,
            policyRate: 0.04,
            policyRateEma: 0.04,
            yield2yEma: 0.04,
            yield5yEma: 0.042,
            yield10yEma: 0.045,
            equityRiskPremium: 0.05,
            nominalGdpIndex: 1.0,
            macroCreditSpreadEma: 0.015,
        );
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);

        $lender = $this->buildCardLender('CARD');
        $strategy = Sectors::getBusinessModelStrategy('credit_services');

        mt_srand(20260912);
        // The first report seeds the loan ledger; the second opens on it.
        $engine->calculate($lender, $macroState, EarningsEngine::resolveReportingTick('CARD', 252));
        $openingBook = (float) $lender->getEarningAssets();
        $engine->calculate($lender, $macroState, EarningsEngine::resolveReportingTick('CARD', 252) + 63);
        $this->assertNotNull($captured);

        $this->assertEqualsWithDelta(
            $strategy->getThroughTheCycleCreditLossRate() / 4.0 * $openingBook,
            $captured->expectedCreditLossProvision,
            $captured->expectedCreditLossProvision * 0.05,
            'the estimate carries one quarter of the through-the-cycle charge on the opening book',
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
            corporateTaxRate: MacroConstants::BASE_CORPORATE_TAX_RATE,
            policyRate: 0.04,
            policyRateEma: 0.04,
            yield2yEma: 0.04,
            yield5yEma: 0.042,
            yield10yEma: 0.045,
            equityRiskPremium: 0.05,
            nominalGdpIndex: 1.0,
            macroCreditSpreadEma: 0.015,
        );
        $surprises = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$surprises): void {
            $ctx = $event->getContext();
            $surprises[] = ($ctx->actualQuarterlyEps - $ctx->expectedQuarterlyEps) / max(0.01, abs($ctx->expectedQuarterlyEps));
        });
        $engine = $this->buildEngine($dispatcher);
        $stock = $this->buildCardLender('CARD');
        $targetRoe = (float) $stock->getBaselineRoe();

        mt_srand(20260912);
        for ($quarter = 1; $quarter <= 8; $quarter++) {
            $engine->calculate($stock, $macroState, (($quarter - 1) * 63) + EarningsEngine::resolveReportingTick('CARD', 252));
        }

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
            corporateTaxRate: 0.21,
            policyRate: 0.04,
            policyRateEma: 0.04,
            yield2yEma: 0.04,
            yield5yEma: 0.042,
            yield10yEma: 0.045,
            equityRiskPremium: 0.05,
            nominalGdpIndex: 1.0,
            macroCreditSpreadEma: 0.013,
            recessionProbabilityEma: 0.15,
        );
        $stressed = new MacroStateDTO(
            corporateTaxRate: 0.21,
            policyRate: 0.04,
            policyRateEma: 0.04,
            yield2yEma: 0.04,
            yield5yEma: 0.042,
            yield10yEma: 0.045,
            equityRiskPremium: 0.05,
            nominalGdpIndex: 1.0,
            macroCreditSpreadEma: 0.013,
            recessionProbabilityEma: 0.65,
        );

        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);
        $stock = $this->buildCardLender('CARD');
        $strategy = Sectors::getBusinessModelStrategy('credit_services');

        $excessProvision = [];
        $regimes = [$calm, $calm, $stressed, $stressed, $stressed, $stressed, $calm, $calm];
        mt_srand(20260912);
        foreach ($regimes as $i => $macro) {
            // The gross book the quarter opens with; the first report seeds it, and is not asserted on.
            $openingBook = (float) $stock->getEarningAssets();
            $engine->calculate($stock, $macro, ($i * 63) + EarningsEngine::resolveReportingTick('CARD', 252));
            $this->assertNotNull($captured);
            // Provision over and above the through-the-cycle charge on the gross opening book: the build or release.
            $ttcCharge = $strategy->getThroughTheCycleCreditLossRate() / 4.0 * $openingBook;
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

    /** An asset manager holding most of its treasury above its operating minimum, its seed sleeve 40% equity. */
    private function buildAssetManager(string $ticker): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setIndustry('Asset Management');
        $stock->setEarningsPerShare('2.00');
        $stock->setSharesOutstanding('1000000000');
        $stock->setPrice('40.00');
        $stock->setVolatility('0.20');
        $stock->setCurrentVolatility('0.20');
        $stock->setBeta('1.1');
        $stock->setTotalEquity('30000000000');
        $stock->setWholesaleDebt('5000000000');
        $stock->setCorporateTreasury('20000000000');
        $stock->setTotalRevenue('12000000000');
        $stock->setRetainedEarnings('15000000000');
        $stock->setBaselineRoe('0.18');
        $stock->setRoeTtm('0.18');
        $stock->setOperatingMargin('0.30');
        $stock->setTargetPayoutRatio('0.40');
        $stock->setDividendSpeed('0.20');
        $stock->setDepreciationRate('0.05');

        return $stock;
    }

    /**
     * Two reports of the same manager, the second at the given board indices against the first's 1.0: the context the
     * second files and the equity it leaves.
     *
     * @return array{EarningsSimulationContext, float, float}
     */
    private function sleeveQuarter(float $priceIndex, float $totalReturnIndex, bool $firstReading = false): array
    {
        $captured = null;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
            $captured = $event->getContext();
        });
        $engine = $this->buildEngine($dispatcher);
        $stock = $this->buildAssetManager('SEED');
        $macro = static fn(float $price, float $totalReturn): MacroStateDTO => new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield10yEma: 0.045, boardPriceIndex: $price, boardTotalReturnIndex: $totalReturn);
        $tick = EarningsEngine::resolveReportingTick('SEED', 252);

        mt_srand(20261020);
        if (!$firstReading) {
            $engine->calculate($stock, $macro(1.0, 1.0), $tick);
        }
        $stock->setTotalEquity('30000000000');
        $stock->setCorporateTreasury('20000000000');
        $engine->calculate($stock, $macro($priceIndex, $totalReturnIndex), $tick + 63);
        $this->assertInstanceOf(EarningsSimulationContext::class, $captured);

        return [$captured, (float) $stock->getTotalEquity(), (float) (($stock->getEarningsMomentumZ() ?? [])[EarningsEngine::STATE_SLEEVE_TRAILING_GAINS] ?? 0.0)];
    }

    /**
     * The treasury's equity earns the board's realised return: a 10% rise between reports marks the sleeve up 10%,
     * net of the tax its rebalance realises, in book value and the treasury it sits in, and never in earnings.
     */
    public function testTheEquitySleeveIsMarkedToTheBoardInBookValueNotEarnings(): void
    {
        [$flat, $flatEquity] = $this->sleeveQuarter(1.0, 1.0);
        [$up, $upEquity] = $this->sleeveQuarter(1.10, 1.10);
        $sleeve = (new AssetManagementBusinessModel())->resolveEquitySleeve($this->buildAssetManager('SEED'), new MacroStateDTO(policyRateEma: 0.04, yield10yEma: 0.045));

        $this->assertEqualsWithDelta($sleeve['value'] * 0.10 * (1.0 - 0.21), $up->equitySleeveMark, 1.0);
        $this->assertSame(0.0, $flat->equitySleeveMark);
        $this->assertGreaterThan(0.0, $flat->quarterlyInterestIncome);
        // The larger book holds a little more cash back at its operating minimum; the mark itself is no part of income.
        $this->assertEqualsWithDelta($flat->quarterlyInterestIncome, $up->quarterlyInterestIncome, 0.01 * $up->equitySleeveMark, 'the mark is not income');
        $this->assertLessThan(0.1 * $up->equitySleeveMark, abs($up->actualQuarterlyNetIncome - $flat->actualQuarterlyNetIncome), 'nor, beyond what a larger book of equity earns, earnings');
        $this->assertGreaterThan($flatEquity, $upEquity);
    }

    /** What the board paid the sleeve in dividends is income, in place of the return it was expected to earn. */
    public function testTheBoardsDividendsOnTheSleeveReachEarnings(): void
    {
        [$none] = $this->sleeveQuarter(1.0, 1.0);
        [$paid] = $this->sleeveQuarter(1.0, 1.01);
        $sleeve = (new AssetManagementBusinessModel())->resolveEquitySleeve($this->buildAssetManager('SEED'), new MacroStateDTO(policyRateEma: 0.04, yield10yEma: 0.045));

        $this->assertEqualsWithDelta(0.04, (float) $paid->equitySleeveDividendReturn, 1e-9, 'a quarter\'s 1% is 4% a year');
        $this->assertEqualsWithDelta($sleeve['value'] * 0.01, $paid->quarterlyInterestIncome - $none->quarterlyInterestIncome, 1.0);
    }

    /**
     * The gains the sleeve's rebalancing realises are carried as a trailing year's rate, for the statutory income the
     * NAIC dividend limit reads: one quarter's mark enters at the weight a half-year lag gives it.
     */
    public function testTheSleevesRealisedGainsAreCarriedForTheTrailingYear(): void
    {
        [$up, , $gains] = $this->sleeveQuarter(1.10, 1.10);
        [, , $none] = $this->sleeveQuarter(1.0, 1.0);

        $quarter = FinancialConstants::QUARTER_YEARS;
        $this->assertGreaterThan(0.0, $up->equitySleeveMark);
        $this->assertEqualsWithDelta(
            ($up->equitySleeveMark / $quarter) * (1.0 - exp(-$quarter / EarningsEngine::SLEEVE_GAINS_TRAILING_LAG_YEARS)),
            $gains,
            1.0,
        );
        $this->assertSame(0.0, $none);
    }

    /** A first report has no earlier reading of the board, so it marks nothing and books the expected return. */
    public function testAFirstReportMarksNothing(): void
    {
        [$first] = $this->sleeveQuarter(1.10, 1.10, firstReading: true);

        $this->assertSame(0.0, $first->equitySleeveMark);
        $this->assertNull($first->equitySleeveDividendReturn);
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
            corporateTaxRate: 0.21,
            policyRate: 0.04,
            policyRateEma: 0.04,
            yield2yEma: 0.04,
            yield5yEma: 0.042,
            yield10yEma: 0.045,
            equityRiskPremium: 0.05,
            nominalGdpIndex: 1.0,
            macroCreditSpreadEma: 0.015,
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
            $this->assertInstanceOf(EarningsSimulationContext::class, $ctx);

            // The ledger identity: net earning assets fall by exactly the provision recognised. Charge-offs
            // move gross book and allowance together and cost earnings nothing, so they cancel out here.
            if ($openingBook > 0.0) {
                $closingNetBook = (float) $stock->getEarningAssets() - (float) $stock->getCreditLossAllowance();
                $openingNetBook = $openingBook - $openingAllowance;
                $this->assertEqualsWithDelta(
                    -$ctx->creditLossProvision + $ctx->netLoanOriginations,
                    $closingNetBook - $openingNetBook,
                    max(1.0, abs($ctx->creditLossProvision) * 1e-6),
                    "Q{$quarter}: the net book must move by originations less the provision, and nothing else.",
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
                "Q{$quarter}: the provision added back to cash must be the provision charged to EBIT.",
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
            corporateTaxRate: 0.21,
            outputGapEma: -0.08,
            policyRate: 0.01,
            policyRateEma: 0.01,
            yield2yEma: 0.04,
            yield5yEma: 0.02,
            yield10yEma: 0.01,
            nominalGdpIndex: 0.90,
            marketVolatilityEma: 0.45,
            macroCreditSpreadEma: 0.07,
            corporateDefaultRateEma: 0.08,
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
            // realized cost ratio — and assert the recurring impairments came off it. Drop the subtraction and
            // this is the figure the solvency tests would read instead. Severance and plant write-downs are
            // special items, charged once to equity: they stay out of the run rate, as they stay out of the
            // Street figure and the lenders' EBITDA.
            $costRatio = $captured->actualRevenue > 0.0
                ? $captured->actualVariableCosts / $captured->actualRevenue
                : $captured->realizedVariableMargin;
            $beforeCharges = ($captured->seasonallyAdjustedRevenue * (1.0 - $costRatio))
                - ($captured->fixedCosts / $captured->seasonalFixedCostFactor)
                - $captured->quarterlyDepreciation;

            $this->assertEqualsWithDelta(
                $beforeCharges - ($captured->impairmentCharges - $captured->plantImpairment),
                $captured->seasonallyAdjustedEbit,
                max(1.0, abs($beforeCharges) * 1e-9),
                "Q{$quarter}: the seasonally adjusted run-rate must be struck after impairments.",
            );
            $this->assertEqualsWithDelta(
                $captured->seasonallyAdjustedEbit / max(1.0, $captured->seasonallyAdjustedRevenue),
                (float) $stock->getReportedOperatingMargin(),
                1e-9,
                "Q{$quarter}: and that impaired run-rate is what gets persisted for the spread and solvency tests.",
            );
            $this->assertEqualsWithDelta(
                $captured->inventoryWriteDown + $captured->receivablesProvision,
                $captured->impairmentCharges,
                1.0,
                "Q{$quarter}: an operating company's impairments are the write-down and the receivable provision.",
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

        $grossNwc = static fn(Stock $s): float => (float) $s->getReceivables() + (float) $s->getInventory() - (float) $s->getPayables();

        // Quarter 1: a depression collapses volumes below the NRV trigger.
        $depression = new MacroStateDTO(outputGapEma: -0.12, corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);
        $grossBefore = $grossNwc($stock);
        $engine->calculate($stock, $depression, $reportingTick, 252);
        $this->assertNotNull($captured);

        $writeDown = $captured->inventoryWriteDown;
        $this->assertGreaterThan(0.0, $writeDown, 'fixture must trigger a write-down for this test to mean anything');

        $allowance = (float) $stock->getInventoryAllowance();
        $this->assertEqualsWithDelta($writeDown, $allowance, 1.0, 'the whole charge is still held against the stock: nothing impaired this quarter has turned yet');
        $this->assertEqualsWithDelta((float) $stock->getInventory() - $allowance, $stock->getNetInventory(), 0.01, 'the carrying value is cost less the write-down');
        $this->assertEqualsWithDelta(
            $grossNwc($stock) - $grossBefore,
            $captured->deltaWorkingCapital,
            1.0,
            'the write-down is non-cash: the working capital build is the change in the gross trade balances alone',
        );

        // Quarter 2: demand recovers, the impaired stock turns, and its replacement is what costs cash.
        $recovery = new MacroStateDTO(outputGapEma: 0.02, corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);
        $allowanceOpening = (float) $stock->getInventoryAllowance();
        $grossBefore = $grossNwc($stock);
        $engine->calculate($stock, $recovery, 63 + $reportingTick, 252);
        $this->assertNotNull($captured);

        $allowanceClosing = (float) $stock->getInventoryAllowance();
        $this->assertLessThan($allowanceOpening, $allowanceClosing, 'the allowance unwinds as the written-down stock turns');
        $released = $allowanceOpening + $captured->inventoryWriteDown - $allowanceClosing;
        $this->assertEqualsWithDelta(
            ($grossNwc($stock) - $grossBefore) + $released,
            $captured->deltaWorkingCapital,
            1.0,
            'replacing the cleared stock is a working capital build: the cash the write-down foretold leaves now',
        );
    }

    /**
     * Overtime is what a firm pays when demand runs past the capacity it keeps for the calendar's own peak.
     * Measured against raw utilization, which carries the seasonal factor, every fourth quarter of a retailer
     * running at 1.35x paid a twelve-point convex penalty at exactly structural demand.
     */
    public function testOvertimePenaltyIsMeasuredAgainstDeseasonalizedUtilization(): void
    {
        $holiday = 1.35;
        $threshold = EarningsEngine::CAPACITY_OVERTIME_THRESHOLD;

        $this->assertSame(0.0, EarningsEngine::overtimePremium($threshold * $holiday, $holiday), 'structural demand at the seasonal peak is not overtime');
        $this->assertSame(0.0, EarningsEngine::overtimePremium($threshold, 1.0));
        $this->assertGreaterThan(0.0, EarningsEngine::overtimePremium($threshold * $holiday, 1.0), 'the same run rate off-season is overtime');
        $this->assertEqualsWithDelta(
            ResponseCurves::calculateConvexPenalty(0.10, EarningsEngine::CAPACITY_OVERTIME_CONVEXITY, EarningsEngine::CAPACITY_OVERTIME_SCALAR),
            EarningsEngine::overtimePremium(($threshold + 0.10) * $holiday, $holiday),
            1e-12,
            'overtime is the overshoot of the deseasonalized run rate, not of the seasonal peak',
        );
    }

    /**
     * A bank levy is charged on the balance sheet below the tax line: net income falls by the quarter's levy, the tax
     * charge does not, the estimate carries it (the rate is public), and the cash statement still reconciles.
     */
    public function testABankLevyComesOffAfterTaxAndTheEstimateCarriesIt(): void
    {
        $run = function (float $levyRate): array {
            $captured = null;
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
                $captured = $event->getContext();
            });
            // A fresh generator per run: a Box-Muller spare left over from the first run would shift the second's
            // draws.
            $this->mathUtility = new MathUtility();
            $engine = $this->buildEngine($dispatcher);

            mt_srand(1111);
            $stock = $this->buildMatureIndustrial('FBNK');
            $stock->setIndustry('Banks - Diversified');
            $stock->setCustomerDeposits('300000000000');
            $cashBefore = (float) $stock->getCorporateTreasury();
            $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04, bankLevyRate: $levyRate), EarningsEngine::resolveReportingTick('FBNK', 252), 252);
            $this->assertNotNull($captured);

            return [$captured, (float) $stock->getCorporateTreasury() - $cashBefore];
        };

        [$free] = $run(0.0);
        [$levied, $cashChange] = $run(0.0021);

        $this->assertSame(0.0, $free->bankLevy);
        // At least the uninsured deposits' share at the long-term rate, a quarter's worth.
        $this->assertGreaterThan(300.0e9 * (1.0 - 0.604) * 0.00105 / 4.0, $levied->bankLevy);
        $this->assertEqualsWithDelta($free->actualQuarterlyNetIncome - $levied->bankLevy, $levied->actualQuarterlyNetIncome, 1.0);
        // The estimate carries the whole levy: the walk-down is struck on revenue, which the levy does not move.
        $this->assertEqualsWithDelta($free->expectedQuarterlyNetIncome - $levied->bankLevy, $levied->expectedQuarterlyNetIncome, 1.0);
        $this->assertEqualsWithDelta($free->taxPaid, $levied->taxPaid, 1.0, 'The levy is not deductible.');
        $this->assertEqualsWithDelta($cashChange, $levied->operatingCashFlow + $levied->investingCashFlow + $levied->financingCashFlow, 1.0);
        // The run rate the payout and buyback tests read pays the levy too.
        $this->assertEqualsWithDelta($levied->bankLevy * 4.0 / max(1.0, $levied->sharesOutstanding), $free->actualAnnualEpsRaw - $levied->actualAnnualEpsRaw, 1e-9);
    }

    /**
     * Rules that cost a mine productivity cost it on every input (Greenstone, List & Syverson 2012): the committed base
     * rises by the factor its unit costs do, and the market's cost base is the whole of what the rules scale. A firm
     * that extracts nothing carries its base as before.
     */
    public function testTheRulesOnExtractionRaiseAMinesCommittedCostsToo(): void
    {
        $run = function (string $industry, float $stringency): EarningsSimulationContext {
            $captured = null;
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(EarningsReportedEvent::class, function (EarningsReportedEvent $event) use (&$captured): void {
                $captured = $event->getContext();
            });
            $this->mathUtility = new MathUtility(); // fresh Box-Muller state so every run sees identical draws
            $engine = $this->buildEngine($dispatcher);

            mt_srand(2222);
            $stock = $this->buildMatureIndustrial('MINE');
            $stock->setIndustry($industry);
            $engine->calculate($stock, new MacroStateDTO(corporateTaxRate: 0.21, extractionStringency: $stringency), EarningsEngine::resolveReportingTick('MINE', 252), 252);
            $this->assertNotNull($captured);

            return $captured;
        };

        $founding = $run('Copper', 0.0);
        $strict = $run('Copper', 1.0);
        $this->assertGreaterThan(0.0, $founding->fixedCosts);
        $this->assertEqualsWithDelta(FirmEconomics::calculateExtractionCostFactor(1.0), $strict->fixedCosts / $founding->fixedCosts, 1e-9);

        $industrial = $run('Auto Manufacturers', 1.0);
        $this->assertEqualsWithDelta($run('Auto Manufacturers', 0.0)->fixedCosts, $industrial->fixedCosts, 1e-6);

        // The share the market prices against covers the committed base as well as the unit costs.
        $share = (float) ($founding->stock->getEarningsMomentumZ()[FinancialConstants::STATE_EXTRACTION_COST_SHARE] ?? 0.0);
        $this->assertGreaterThan($founding->fixedCosts / $founding->actualRevenue, $share);
    }

    /**
     * Each report rolls the firm's long-run return toward its trailing return over LONG_RUN_RETURN_EMA_YEARS, so the
     * valuation's anchor is a measured level, not the lore baseline.
     */
    public function testEachReportRollsTheLongRunReturnTowardTheTrailingOne(): void
    {
        $stock = $this->buildMatureIndustrial('LRUN');
        $stock->setLongRunReturn('0.0500');
        $macroState = new MacroStateDTO(corporateTaxRate: 0.21, policyRateEma: 0.04, yield5yEma: 0.04);

        $this->earningsEngine->calculate($stock, $macroState, EarningsEngine::resolveReportingTick('LRUN', 252));

        $trailing = Sectors::strategyFor($stock->getIndustry())->getTrueReturn($stock);
        $this->assertEqualsWithDelta(
            0.05 + ((1.0 - exp(-0.25 / 5.0)) * ($trailing - 0.05)),
            (float) $stock->getLongRunReturn(),
            1e-4,
        );
    }
}
