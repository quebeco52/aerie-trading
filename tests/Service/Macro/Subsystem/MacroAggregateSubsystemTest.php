<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro\Subsystem;

use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\OutputGapProbe;
use App\Service\Macro\Subsystem\Aggregate\ConsumerPrices;
use App\Service\Macro\Subsystem\Aggregate\DomesticDemand;
use App\Service\Macro\Subsystem\Aggregate\IndustryIndicators;
use App\Service\Macro\Subsystem\Aggregate\InventoryAndTiming;
use App\Service\Macro\Subsystem\Aggregate\MovingAverages;
use App\Service\Macro\Subsystem\Aggregate\NationalAccounts;
use App\Service\Macro\Subsystem\Aggregate\OpenEconomy;
use App\Service\Macro\Subsystem\Aggregate\OutputGap;
use App\Service\Macro\Subsystem\Aggregate\ProductivityGrowth;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\FixedIncome;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use App\Service\Model\Sector\SemiconductorBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\TestCase;

class MacroAggregateSubsystemTest extends TestCase
{
    private MathUtility $mathUtility;
    private MacroAggregateSubsystem $subsystem;

    protected function setUp(): void
    {
        // The rare demand disaster is the only probabilistic gate in this subsystem. Held shut so a
        // neutral tick stays neutral; its compensator still rides on every tick and cancels in the
        // state-vs-state differences these tests assert on.
        $this->mathUtility = new ScriptedDraws();
        $this->subsystem = MacroAggregateSubsystem::headless($this->mathUtility);
    }

    public function testTfpAndNaturalRateEvolveContinuously(): void
    {
        $state = new MacroState();
        $state->totalFactorProductivityIndex = 100.0;
        $state->naturalRate = MacroConstants::BASE_NATURAL_RATE;
        $state->outputGapEma = 0.01;

        $tfpGrowth = $this->subsystem->calculateTotalFactorProductivity($state, 0.25);
        $this->subsystem->calculateNaturalRate($state, $tfpGrowth, 0.25);

        $this->assertGreaterThan(0.0, $state->totalFactorProductivityIndex);
        $this->assertGreaterThan(0.0, $state->naturalRate);
    }

    /** HLW's r* has no gap term: a boom at trend growth leaves the natural rate where it was. */
    public function testTheNaturalRateIgnoresTheOutputGap(): void
    {
        $state = new MacroState();
        $state->naturalRate = MacroConstants::BASE_NATURAL_RATE;
        $state->outputGap = 0.03;
        $state->outputGapEma = 0.03;

        $this->subsystem->calculateNaturalRate($state, MacroConstants::TFP_DRIFT, 0.25);

        $this->assertSame(MacroConstants::BASE_NATURAL_RATE, $state->naturalRate);
    }

    /** r* moves c points per point of trend growth (HLW c = 1.113), approached as a lag at the adjustment speed. */
    public function testTheNaturalRateLoadsOnTrendGrowthAtHlwsC(): void
    {
        $state = new MacroState();
        $state->naturalRate = MacroConstants::BASE_NATURAL_RATE;
        $dt = 0.1;

        $this->subsystem->calculateNaturalRate($state, MacroConstants::TFP_DRIFT + 0.01, $dt);

        $expectedStep = (1.0 - exp(-ProductivityGrowth::NATURAL_RATE_ADJUSTMENT_SPEED * $dt)) * ProductivityGrowth::NATURAL_RATE_GROWTH_LOADING * 0.01;
        $this->assertEqualsWithDelta(MacroConstants::BASE_NATURAL_RATE + $expectedStep, $state->naturalRate, 1e-15);
    }

    public function testOutputGapAndInflationRespondToShocks(): void
    {
        $state = new MacroState();
        $state->outputGap = 0.0;
        $state->inflation = 0.02;
        $state->policyRate = 0.02;
        $state->wageGrowth = 0.035;

        $newGap = $this->subsystem->calculateOutputGap($state, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
        $newInflation = $this->subsystem->calculateInflation($state, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, 0.25);

        $this->assertGreaterThan(-0.12, $newGap);
        $this->assertLessThan(0.10, $newGap);
        $this->assertGreaterThan(-0.02, $newInflation);
    }

    public function testConvexPhillipsCurveAcceleratesNearCapacity(): void
    {
        $expansionPressureLow = MacroTransmission::calculateConvexPhillipsCurve(0.02, ConsumerPrices::PHILLIPS_MAX_CAPACITY, ConsumerPrices::PHILLIPS_CONVEX_KAPPA, ConsumerPrices::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR);
        $expansionPressureHigh = MacroTransmission::calculateConvexPhillipsCurve(0.06, ConsumerPrices::PHILLIPS_MAX_CAPACITY, ConsumerPrices::PHILLIPS_CONVEX_KAPPA, ConsumerPrices::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR);

        // Near capacity (y=0.06), pressure must be more than 3x higher than at y=0.02 due to non-linear convexity
        $this->assertGreaterThan(3.0 * $expansionPressureLow, $expansionPressureHigh);

        // During contractions (y=-0.04), downward nominal rigidity flattens deflation pressure
        $recessionPressure = MacroTransmission::calculateConvexPhillipsCurve(-0.04, ConsumerPrices::PHILLIPS_MAX_CAPACITY, ConsumerPrices::PHILLIPS_CONVEX_KAPPA, ConsumerPrices::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR);
        $this->assertLessThan(0.0, $recessionPressure);
        $this->assertGreaterThan(-0.01, $recessionPressure, 'Downward rigidity must prevent runaway deflationary pressure');
    }

    public function testSectoralInflationDisaggregation(): void
    {
        $state = new MacroState();
        $state->outputGap = 0.03;
        $state->laborTightness = 1.60; // Hot labor market
        $state->wageGrowth = 0.055;    // Elevated wage growth
        $state->supplyChainPressureIndex = 2.0; // Supply chain pressure building over its quarter average
        $state->inflation = 0.02;

        $newInflation = $this->subsystem->calculateInflation($state, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, 0.25);

        $this->assertGreaterThan(MacroConstants::TARGET_INFLATION, $state->supercoreInflation, 'Hot labor market must push supercore services inflation up');
        $this->assertGreaterThan(MacroConstants::TARGET_INFLATION, $state->coreGoodsInflation, 'Rising supply-chain pressure must push core goods inflation up');
        $this->assertGreaterThan(MacroConstants::TARGET_INFLATION, $newInflation, 'Headline inflation must rise above target');
    }

    public function testMetzlerInventoryCycleDynamics(): void
    {
        $state = new MacroState();
        $state->outputGap = -0.02;
        $state->outputGapEma = 0.02; // Sharp deceleration from +2% to -2%
        $state->inventoryStockGap = 0.0;

        $updatedGap = $this->subsystem->calculateOutputGap($state, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);

        // Decelerating demand causes involuntary inventory accumulation (+gap)
        $this->assertGreaterThan(0.0, $state->inventoryStockGap, 'Demand slowdown must induce involuntary inventory overhang');
        $this->assertLessThan(0.0, $updatedGap, 'Output gap should reflect negative shock and inventory liquidation drag');
    }

    public function testSectoralInflationShowsDifferentiation(): void
    {
        // 1. Wage shock scenario: high wage growth, neutral freight
        $stateWage = new MacroState();
        $stateWage->wageGrowth = 0.06; // 6% wage growth
        $stateWage->freightRateIndexEma = 100.0;
        $stateWage->industrialMetalsIndexEma = 100.0;
        $stateWage->inflation = 0.02;

        $this->subsystem->calculateInflation($stateWage, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, 0.25);

        // Supercore Services must absorb wage-push inflation more heavily than Core Goods
        $this->assertGreaterThan($stateWage->coreGoodsInflation, $stateWage->supercoreInflation, 'Wage surge must drive supercore services higher than core goods');

        // 2. Supply chain shock scenario: neutral wage growth, high freight
        $stateSupply = new MacroState();
        $stateSupply->wageGrowth = 0.035; // Neutral wage growth
        // Freight doubled, its quarter average still catching up
        $stateSupply->freightRateIndex = 2.0 * MacroConstants::FREIGHT_BASELINE;
        $stateSupply->freightRateIndexEma = MacroConstants::FREIGHT_BASELINE;
        $stateSupply->inflation = 0.02;

        $this->subsystem->calculateInflation($stateSupply, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, 0.25);

        // Core Goods must absorb supply chain frictions more heavily than Supercore Services
        $this->assertGreaterThan($stateSupply->supercoreInflation, $stateSupply->coreGoodsInflation, 'Supply chain bottleneck must drive core goods higher than supercore services');
    }

    /**
     * Services repricing on their own schedules sits outside the sticky services rate: it passes into measured services
     * inflation whole, fades at its own reversion rather than the sticky rate's, and reaches headline at the services
     * weight.
     */
    public function testServicesRepricingSitsOutsideTheStickyRate(): void
    {
        $dt = 0.25;
        $shock = 0.004;
        $calm = new MacroState();
        $calm->inflation = MacroConstants::TARGET_INFLATION;
        $calm->inflationEma = MacroConstants::TARGET_INFLATION;
        $calm->wageGrowth = MacroConstants::TFP_DRIFT + MacroConstants::TARGET_INFLATION;
        $repriced = clone $calm;
        $repriced->servicesPriceShock = $shock;
        $repriced->supercoreInflation += $shock;

        $this->subsystem->calculateServicesRepricing($calm, $dt);
        $this->subsystem->calculateServicesRepricing($repriced, $dt);
        $calmHeadline = $this->subsystem->calculateInflation($calm, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, $dt);
        $repricedHeadline = $this->subsystem->calculateInflation($repriced, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, $dt);

        $faded = $shock * exp(-ConsumerPrices::SERVICES_PRICE_SHOCK_REVERSION * $dt);
        $this->assertEqualsWithDelta($faded, $repriced->servicesPriceShock, 1e-12, 'The repricing fades at its own reversion.');
        $this->assertEqualsWithDelta($faded, $repriced->supercoreInflation - $calm->supercoreInflation, 1e-12, 'Services inflation carries the repricing whole, over an unchanged sticky rate.');
        $this->assertEqualsWithDelta(ConsumerPrices::INFLATION_WEIGHT_SUPERCORE * $faded, $repricedHeadline - $calmHeadline, 1e-12, 'Headline carries it at the services weight.');
    }

    public function testCalculateCapacityUtilization(): void
    {
        $stateNormal = new MacroState();
        $stateNormal->outputGap = 0.0;
        $stateNormal->capitalStockOverhang = 0.0;

        $this->subsystem->calculateCapacityUtilization($stateNormal);
        $this->assertEqualsWithDelta(MacroConstants::CU_BASELINE, $stateNormal->capacityUtilizationRate, 0.0001);

        $stateBoom = new MacroState();
        $stateBoom->outputGap = 0.03;
        $stateBoom->capitalStockOverhang = 0.0;

        $this->subsystem->calculateCapacityUtilization($stateBoom);
        $this->assertGreaterThan(MacroConstants::CU_BASELINE, $stateBoom->capacityUtilizationRate);

        $stateOverhang = new MacroState();
        $stateOverhang->outputGap = 0.0;
        $stateOverhang->capitalStockOverhang = 0.15;

        $this->subsystem->calculateCapacityUtilization($stateOverhang);
        $this->assertLessThan(MacroConstants::CU_BASELINE, $stateOverhang->capacityUtilizationRate);
    }

    /**
     * The subsystem publishes utilization as a fraction and the sector models read it through
     * MacroTransmission::calculateCapacityUtilizationShift(). Both ends must agree on that scale.
     *
     * They did not: the helper divided the gap by 100 a second time, which is correct only for a rate
     * quoted in whole points. Fed with the fraction the engine actually publishes, every industrial
     * model's utilization channel was attenuated a hundredfold, and the semiconductor shortage and glut
     * gates -- set at a three-point gap -- sat two orders of magnitude beyond anything reachable inside
     * the [0.60, 0.92] clamp. Nothing failed, because each side was self-consistent on its own.
     */
    public function testCapacityUtilizationShiftReadsTheSubsystemsOwnScale(): void
    {
        $boom = new MacroState();
        $boom->outputGap = 0.03;
        $boom->capitalStockOverhang = 0.0;
        $this->subsystem->calculateCapacityUtilization($boom);

        $gap = $boom->capacityUtilizationRate - MacroConstants::CU_BASELINE;
        $this->assertEqualsWithDelta(
            $gap,
            MacroTransmission::calculateCapacityUtilizationShift($boom->capacityUtilizationRate, MacroConstants::CU_BASELINE, 1.0),
            0.0001,
            'At unit sensitivity the shift is the utilization gap itself, in the units the subsystem publishes',
        );

        // A three-point gap is what the semiconductor fab-shortage gate is calibrated to, and a 3% output
        // gap has to clear it -- otherwise the gate is unreachable however hot the economy runs.
        $this->assertGreaterThan(
            SemiconductorBusinessModel::BOOM_CAPACITY_UTILIZATION_THRESHOLD,
            $gap,
            'A boom must be able to trip the fab shortage gate',
        );

        $glut = new MacroState();
        $glut->outputGap = -0.03;
        $glut->capitalStockOverhang = 0.15;
        $this->subsystem->calculateCapacityUtilization($glut);

        $this->assertLessThan(
            SemiconductorBusinessModel::GLUT_CAPACITY_UTILIZATION_THRESHOLD,
            $glut->capacityUtilizationRate - MacroConstants::CU_BASELINE,
            'A slump with idle plant must be able to trip the fab glut gate',
        );
    }

    /**
     * Energy reaches headline as a step in the price level, at its full transmission and undiluted by the basket
     * weight, and a price that stays high adds no further inflation once the lag has caught up.
     */
    public function testCommodityCostPushPassesThroughToHeadlineInflationWithoutAttenuation(): void
    {
        $stateNormal = new MacroState();
        $stateNormal->inflation = 0.02;
        $stateNormal->inflationEma = 0.02;
        $stateNormal->outputGap = 0.0;
        $stateNormal->wageGrowth = MacroConstants::TFP_DRIFT + MacroConstants::TARGET_INFLATION;
        $stateNormal->energyPriceShock = 0.0;
        $stateNormal->agriculturalCommodityIndex = 100.0;

        $stateEnergy = clone $stateNormal;
        $stateEnergy->energyPriceShock = 100.0; // 100% price surge (doubling)

        $mathMock = new ScriptedDraws();

        $subsystemWithMock = MacroAggregateSubsystem::headless($mathMock);

        // The energy lag closes the share 1 - exp(-dt / tau) of its remaining gap each step, so the price level climbs
        // by T w, then T w (1 - w), and so on: steps that shrink geometrically and sum to the full transmission T.
        $dt = 0.25;
        $transmission = MacroConstants::ENERGY_COST_PUSH_TRANSMISSION;
        $closed = 1.0 - exp(-$dt / ConsumerPrices::ENERGY_COST_PUSH_LAG_YEARS);
        $infNormal = $subsystemWithMock->calculateInflation($stateNormal, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, $dt);
        $infEnergy = $subsystemWithMock->calculateInflation($stateEnergy, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, $dt);

        $this->assertEqualsWithDelta($transmission * $closed, ($infEnergy - $infNormal) * $dt, 1e-9, 'Energy shock must lift the price level by its full transmission, undiluted by basket weight.');

        $infNormalHeld = $subsystemWithMock->calculateInflation($stateNormal, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, $dt);
        $infEnergyHeld = $subsystemWithMock->calculateInflation($stateEnergy, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, $dt);

        $this->assertEqualsWithDelta($transmission * $closed * (1.0 - $closed), ($infEnergyHeld - $infNormalHeld) * $dt, 1e-9, 'Energy held high is a price level, not a standing inflation rate.');
    }

    public function testCalculateManufacturingPmi(): void
    {
        $dt = 0.25;

        // Expansion: high capacity, positive gap momentum, inventory deficit
        $stateBoom = new MacroState();
        $stateBoom->capacityUtilizationRate = 0.82;
        $stateBoom->outputGap = 0.03;
        $stateBoom->outputGapEma = 0.01;
        $stateBoom->inventoryStockGap = -0.02;
        $stateBoom->sloosTighteningIndexEma = 0.0;
        $stateBoom->manufacturingPmi = 50.0;

        $this->subsystem->calculateManufacturingPmi($stateBoom, $dt);
        $this->assertGreaterThan(50.0, $stateBoom->manufacturingPmi, 'Expansionary signals must push PMI above neutral 50');

        // Contraction: low capacity, deceleration, inventory overhang
        $stateBust = new MacroState();
        $stateBust->capacityUtilizationRate = 0.74;
        $stateBust->outputGap = -0.02;
        $stateBust->outputGapEma = 0.01;
        $stateBust->inventoryStockGap = 0.03;
        $stateBust->sloosTighteningIndexEma = 0.25;
        $stateBust->manufacturingPmi = 50.0;

        $this->subsystem->calculateManufacturingPmi($stateBust, $dt);
        $this->assertLessThan(50.0, $stateBust->manufacturingPmi, 'Contractionary signals must depress PMI below neutral 50');
    }

    /**
     * Nine industrial models read the index against 50. A one-sided bank-standards leg, which is no ISM component, took
     * ~0.9 points off it at rest, so it settled near 49 and every one of them booked a standing contraction.
     */
    public function testPmiRestsAtFiftyWhenActivityDoesWhateverBanksAreDoing(): void
    {
        $state = new MacroState();
        $state->capacityUtilizationRate = MacroConstants::CU_BASELINE;
        $state->outputGap = 0.0;
        $state->outputGapEma = 0.0;
        $state->inventoryStockGap = 0.0;
        $state->sloosTighteningIndexEma = 0.25;
        $state->manufacturingPmi = MacroConstants::PMI_BASELINE;

        $this->subsystem->calculateManufacturingPmi($state, 0.25);

        $this->assertEqualsWithDelta(MacroConstants::PMI_BASELINE, $state->manufacturingPmi, 1e-12);
    }

    /**
     * A diffusion index measures change, not level. Two quarters into a V-shaped recovery the output gap is
     * still negative and capacity utilization still slack, yet firms report improvement, so the index reads in
     * the high 50s (1983, 2009-2010). The CU-level weighting that came before held it in the mid-40s for a year
     * after the trough, lagging the cycle it is meant to lead.
     */
    public function testPmiLeadsTheRecoveryWhileTheLevelIsStillBelowTrend(): void
    {
        $recovery = new MacroState();
        $recovery->capacityUtilizationRate = 0.77;
        $recovery->outputGap = -0.009;
        $recovery->outputGapEma = -0.018; // 3.6% annualized real growth over potential at the quarter EMA horizon
        $recovery->inventoryStockGap = 0.0;
        $recovery->sloosTighteningIndexEma = 0.10;
        $recovery->manufacturingPmi = 44.0;

        $this->subsystem->calculateManufacturingPmi($recovery, 0.25);
        $this->assertGreaterThan(48.0, $recovery->manufacturingPmi, 'one quarter into a fast recovery the index is already off its lows');

        for ($i = 0; $i < 8; $i++) {
            $this->subsystem->calculateManufacturingPmi($recovery, 0.25);
        }
        $this->assertGreaterThan(55.0, $recovery->manufacturingPmi, 'a sustained 3.6% growth surplus reads in the high 50s despite slack capacity');
        $this->assertLessThan(IndustryIndicators::MAX_PMI, $recovery->manufacturingPmi);

        $slowdown = new MacroState();
        $slowdown->capacityUtilizationRate = 0.81; // tight capacity, but growth has stalled
        $slowdown->outputGap = 0.015;
        $slowdown->outputGapEma = 0.020; // -2% annualized growth shortfall
        $slowdown->inventoryStockGap = 0.0;
        $slowdown->sloosTighteningIndexEma = 0.0;
        $slowdown->manufacturingPmi = 50.0;

        for ($i = 0; $i < 8; $i++) {
            $this->subsystem->calculateManufacturingPmi($slowdown, 0.25);
        }
        $this->assertLessThan(48.0, $slowdown->manufacturingPmi, 'a stalling boom reads contractionary while the level of activity is still high (2022)');
    }

    public function testCalculateProducerPriceInflation(): void
    {
        $dt = 0.25;
        $tfp = MacroConstants::TFP_DRIFT;

        $state = new MacroState();
        $state->industrialMetalsIndex = 140.0;
        $state->energyPriceIndex = 160.0;
        $state->agriculturalCommodityIndex = 120.0;
        $state->supplyChainPressureIndex = 2.0;
        $state->wageGrowth = 0.055;
        $state->outputGap = 0.02;

        $this->subsystem->calculateProducerPriceInflation($state, $tfp, $dt);
        $this->assertGreaterThan(MacroConstants::TARGET_INFLATION, $state->producerPriceInflation, 'Upstream commodity surges and supply frictions must drive PPI above CPI target');
    }

    public function testAHighTermPremiumEraTightensBusinessBorrowingAgainstAStructuralNeutral(): void
    {
        $baseline = new MacroState();
        $baseline->outputGap = 0.0;
        $baseline->outputGapEma = 0.0;
        $baseline->policyRate = MacroConstants::BASE_NATURAL_RATE + MacroConstants::TARGET_INFLATION;
        $baseline->inflation = MacroConstants::TARGET_INFLATION;
        $scale5y = FixedIncome::calculateTermPremiumDurationScale(5.0, MacroConstants::TERM_PREMIUM_DURATION_HORIZON_YEARS);
        $neutral5y = $baseline->policyRate + MacroConstants::NS_BASE_TERM_PREMIUM * $scale5y;

        $highEra = clone $baseline;
        $highEra->termPremiumRegime = 0.020;
        $fiveYearInHighEra = $baseline->policyRate + 0.020 * $scale5y;

        $gapBaseline = $this->subsystem->calculateOutputGap($baseline, $neutral5y, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
        $gapHighEra = $this->subsystem->calculateOutputGap($highEra, $fiveYearInHighEra, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);

        $this->assertLessThan($gapBaseline, $gapHighEra, 'The neutral is structural: a premium era that lifts the five-year is a real tightening of business borrowing, which the Taylor rule long-rate offset, not the IS curve, is there to lean against.');
    }

    public function testGovernmentSpendingAboveBaselineLiftsOutputGapDrift(): void
    {
        $baseline = new MacroState();
        $baseline->outputGap = 0.0;
        $baseline->outputGapEma = 0.0;
        $baseline->governmentSpendingIndexEma = MacroConstants::GOVT_SPENDING_BASELINE;

        $stimulus = clone $baseline;
        $stimulus->governmentSpendingIndexEma = MacroConstants::GOVT_SPENDING_BASELINE * 1.10;

        $gapBaseline = $this->subsystem->calculateOutputGap($baseline, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
        $gapStimulus = $this->subsystem->calculateOutputGap($stimulus, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);

        // Domestic demand reaches GDP on its share; the finance the District lives on does not answer purchases.
        $expectedImpulse = OutputGap::DOMESTIC_GAP_WEIGHT * DomesticDemand::KALDOR_GOVT_SPENDING_MULTIPLIER * 0.10 * 0.25;
        $this->assertEqualsWithDelta(
            $expectedImpulse,
            $gapStimulus - $gapBaseline,
            1e-9,
            'A 10% public spending increase must add the calibrated demand impulse to the output gap drift.',
        );
    }

    /**
     * The Kaldor-Kalecki capital term is the slow half of the phase space, and read through one linear
     * coefficient it behaves as a spring: whatever a slump takes out of the capital stock is handed back as
     * pent-up demand on the way up. Measured over 960 simulated years, as busts deepened the overhang's
     * minimum doubled (-0.84% to -1.72%) while its maximum barely moved, so a one-sided drag on the downside
     * came back as a BIGGER boom and the gap's skew moved the wrong way. Investment is irreversible: idle
     * plant stops orders immediately, scrapped plant does not start them (Bertola & Caballero 1994).
     */
    public function testACapitalShortfallReboundsMoreSlowlyThanAnExcessDrags(): void
    {
        $overhang = 0.020;

        // Built before any call: calculateOutputGap mutates the inventory and demand disturbance it is
        // handed, so a state cloned afterwards would not be a clean control.
        $neutral = new MacroState();
        $neutral->outputGap = 0.0;
        $neutral->outputGapEma = 0.0;
        $neutral->capitalStockOverhang = 0.0;

        $excess = clone $neutral;
        $excess->capitalStockOverhang = $overhang;

        $shortfall = clone $neutral;
        $shortfall->capitalStockOverhang = -$overhang;

        $gapNeutral = $this->subsystem->calculateOutputGap($neutral, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
        $gapExcess = $this->subsystem->calculateOutputGap($excess, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
        $gapShortfall = $this->subsystem->calculateOutputGap($shortfall, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);

        $this->assertEqualsWithDelta(
            -OutputGap::DOMESTIC_GAP_WEIGHT * DomesticDemand::KALDOR_CAPITAL_DRAG * $overhang * 0.25,
            $gapExcess - $gapNeutral,
            1e-9,
            'Excess capacity must subtract its calibrated drag from the output gap drift.',
        );

        $this->assertEqualsWithDelta(
            OutputGap::DOMESTIC_GAP_WEIGHT * DomesticDemand::KALDOR_CAPITAL_REBOUND_DRAG * $overhang * 0.25,
            $gapShortfall - $gapNeutral,
            1e-9,
            'A capital shortfall must return demand at its own, slower rate.',
        );

        $this->assertLessThan(
            abs($gapExcess - $gapNeutral),
            abs($gapShortfall - $gapNeutral),
            'An equal and opposite shortfall must not hand back as much demand as the excess took away: that symmetry is the spring that pays a deep bust straight back as a bigger boom.',
        );
    }

    public function testFarmPriceCollapseLowersFoodCostPushBelowZero(): void
    {
        $state = new MacroState();
        $state->agriculturalCommodityIndex = MacroConstants::AGRI_BASELINE * 0.80;
        $state->agriCostPushLag = 0.0;

        for ($i = 0; $i < 8; $i++) {
            $this->subsystem->calculateInflation($state, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, 0.25);
        }

        $this->assertLessThan(0.0, $state->agriCostPushLag, 'Falling farm prices must pass through as a food-CPI dividend, symmetric to a spike.');
        $this->assertEqualsWithDelta(
            -0.20 * ConsumerPrices::AGRI_COST_PUSH_TRANSMISSION,
            $state->agriCostPushLag,
            0.0005,
            'After two years the distributed lag must have converged to the full symmetric pass-through.',
        );
    }

    /** The board's capitalisation in the tests below. Deliberately an absurd number: see gapWithEquityAt(). */
    private const TEST_MARKET_CAP = 8.6e11;

    /**
     * One step of the output gap with the board worth a given multiple of what households are used to.
     *
     * The capitalisation is arbitrary on purpose. The effect reads the ratio against its own trend, so a
     * constant scale divides out and only the multiple can matter; if a test here ever starts caring what
     * that number is, the scale has leaked back into the channel.
     */
    private function gapWithEquityAt(float $multipleOfTrend, float $trend = 1.0): float
    {
        $state = new MacroState();
        $state->outputGap = 0.0;
        $state->nominalGdpIndex = 1.0;
        $state->equityWealthTrend = self::TEST_MARKET_CAP * $trend;
        $state->equityMarketCap = self::TEST_MARKET_CAP * $multipleOfTrend;
        $state->equityMarketCapEma = $state->equityMarketCap;

        return $this->subsystem->calculateOutputGap($state, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
    }

    /**
     * Modigliani (1971) / Carroll, Otsuka & Slacalek (2011): households hold the board through their
     * portfolios and pensions, so a bull market spends and a crash saves. Until this channel existed the
     * output gap counted only the houses, and the equity market could halve without a household noticing.
     */
    public function testEquityWealthMovesAggregateDemandInTheDirectionOfTheMarket(): void
    {
        $atTrend = $this->gapWithEquityAt(1.00);

        $this->assertLessThan($atTrend, $this->gapWithEquityAt(0.65), 'A crash must subtract from demand.');
        $this->assertGreaterThan($atTrend, $this->gapWithEquityAt(1.60), 'A bull market must add to it.');

        // Housing is the larger of the two wealth channels, and the elasticities have to keep saying so.
        $this->assertLessThan(
            DomesticDemand::KALDOR_WEALTH_EFFECT_ELASTICITY,
            DomesticDemand::KALDOR_EQUITY_WEALTH_ELASTICITY,
            'The MPC out of financial wealth is the smaller one.',
        );
    }

    /**
     * The coefficients are MPCs on stocks, entered as drifts at the demand equation's own pull (Mian, Rao & Sufi 2013;
     * Chodorow-Reich, Nenov & Simsek 2021). Held, a wealth gain settles domestic demand where that pull balances it:
     * the MPC on the stock, before policy answers any of it. A held state holds no sales surprise, so inventories add
     * nothing. Measured in slack, below the capacity ceiling.
     */
    public function testAHeldWealthGainSettlesSpendingAtTheMpcOnTheStock(): void
    {
        $settle = function (float $housing, float $equity): float {
            $state = $this->neutralBorrowingState();
            $state->creditCrisisDrag = 0.06;
            $state->residentialWealthTrend = 100.0;
            $state->residentialPropertyIndexEma = 100.0 * (1.0 + $housing);
            $state->equityMarketCapEma = self::TEST_MARKET_CAP * (1.0 + $equity);
            $state->nominalGdpIndex = 1.0;
            $state->equityWealthTrend = self::TEST_MARKET_CAP;
            for ($i = 0; $i < 3000; $i++) {
                $state->outputGap = $this->subsystem->calculateOutputGap($state, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.01, 1.0);
                // A held state holds no sales surprise: both averages sit on their series.
                $state->outputGapEma = $state->outputGap;
                $state->domesticDemandGapEma = OutputGap::domesticDemandGap($state);
            }
            $this->assertLessThan(0.0, $state->outputGap, 'Measured below the capacity ceiling.');

            return $state->outputGap;
        };
        $base = $settle(0.0, 0.0);
        $amplification = 1.0;

        $housingLevel = OutputGap::DOMESTIC_GAP_WEIGHT * DomesticDemand::HOUSING_WEALTH_MPC * DomesticDemand::HOUSING_WEALTH_TO_GDP * 0.10;
        $this->assertEqualsWithDelta($amplification * $housingLevel, $settle(0.10, 0.0) - $base, 0.005 * $housingLevel, 'Ten percent on the houses is ~0.6% of GDP of spending.');

        // Households own a fifth of the board; the rest of the world spends its gains at home.
        $equityLevel = DomesticDemand::EQUITY_WEALTH_MPC * DomesticDemand::DISTRICT_HOUSEHOLD_EQUITY_SHARE * SovereignFundSubsystem::MARKET_CAP_TO_GDP * 0.10;
        $this->assertEqualsWithDelta($amplification * $equityLevel, $settle(0.0, 0.10) - $base, 0.005 * $equityLevel, 'Ten percent on the board is ~0.1% of GDP of District spending.');
    }

    /**
     * The gap is a deviation from potential, so a valuation households have lived with for years cannot go
     * on buying extra output. Measured against a fixed opening instead, any lasting difference between how
     * fast the board compounds and how fast the economy does would accumulate into a permanent demand drift.
     */
    public function testAValuationHouseholdsHaveGotUsedToStopsMovingDemand(): void
    {
        $state = new MacroState();
        $state->nominalGdpIndex = 1.0;
        $state->equityWealthTrend = self::TEST_MARKET_CAP;
        $state->equityMarketCap = self::TEST_MARKET_CAP * 1.60;
        $state->equityMarketCapEma = $state->equityMarketCap;

        // Four horizons of living with it. Nominal GDP is held flat so only the trend can close the gap.
        for ($i = 0; $i < 4 * 12; $i++) {
            $this->subsystem->updateExponentialMovingAverages($state, MovingAverages::EQUITY_WEALTH_TREND_HORIZON_YEARS / 12.0);
            $state->nominalGdpIndex = 1.0;
        }

        $this->assertEqualsWithDelta(1.60, $state->equityWealthTrend / self::TEST_MARKET_CAP, 0.05, 'The trend settles on the level it has been shown.');

        // Stated on the gap itself, holding every other series still: what the channel reads is the ratio's
        // distance from its trend and nothing else, so a board 60% richer than households are used to and a
        // board exactly as rich as they are used to must produce the very same demand.
        $this->assertEqualsWithDelta(
            $this->gapWithEquityAt(1.00),
            $this->gapWithEquityAt(1.60, 1.60),
            1e-12,
            'Once it is normal, a 60% richer board moves demand no more than an ordinary one.',
        );
        $this->assertNotEqualsWithDelta(
            $this->gapWithEquityAt(1.00),
            $this->gapWithEquityAt(1.60),
            1e-6,
            'While it is still news, it must move demand.',
        );
    }

    /** A house price index and the level households have got used to, with every other series held still. */
    private function gapWithHousingAt(float $index, float $trend): float
    {
        $state = $this->neutralBorrowingState();
        $state->residentialPropertyIndex = $index;
        $state->residentialPropertyIndexEma = $index;
        $state->residentialWealthTrend = $trend;

        return $this->subsystem->calculateOutputGap($state, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
    }

    /**
     * The same argument as the equity trend above, and the reason housing needed it: measured against a fixed
     * opening index instead, a district whose houses outpace its output collects a standing addition to demand
     * for as long as the level persists -- 0.7pp a year at the end of a live 19-year run -- which is a drift,
     * not the wealth effect Mian, Rao & Sufi (2013) measured out of housing wealth CHANGES.
     */
    public function testAHousePriceLevelHouseholdsHaveGotUsedToStopsMovingDemand(): void
    {
        $this->assertGreaterThan(
            $this->gapWithHousingAt(100.0, 100.0),
            $this->gapWithHousingAt(130.0, 100.0),
            'A 30% dearer housing stock must move demand while it is still news.',
        );

        $state = $this->neutralBorrowingState();
        $state->residentialPropertyIndex = 130.0;
        $state->residentialPropertyIndexEma = 130.0;

        // Four horizons of living with it.
        for ($i = 0; $i < 4 * 12; $i++) {
            $this->subsystem->updateExponentialMovingAverages($state, MovingAverages::RESIDENTIAL_WEALTH_TREND_HORIZON_YEARS / 12.0);
        }

        $this->assertEqualsWithDelta(130.0, $state->residentialWealthTrend, 1.0, 'The trend settles on the level it has been shown.');
        $this->assertEqualsWithDelta(
            $this->gapWithHousingAt(100.0, 100.0),
            $this->gapWithHousingAt(130.0, 130.0),
            1e-12,
            'Once it is normal, a 30% dearer housing stock moves demand no more than an ordinary one.',
        );
    }

    /**
     * A caller that never reports a market -- the simulate command, the headless harness, every unit test
     * written before this channel existed -- must be left exactly where it was.
     */
    public function testAnUnreportedMarketContributesNothing(): void
    {
        $unreported = new MacroState();
        $unreported->outputGap = 0.0;
        $unreported->nominalGdpIndex = 1.0;

        $this->assertSame(0.0, $unreported->equityMarketCap, 'Zero is the sentinel for a market never reported.');
        $this->assertSame(0.0, $unreported->equityWealthTrend, 'And nothing has been got used to yet.');

        // Silent, not merely small: it has to read exactly as a board sitting on its own trend does.
        $this->assertEqualsWithDelta(
            $this->gapWithEquityAt(1.00),
            $this->subsystem->calculateOutputGap($unreported, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0),
            1e-12,
            'An unreported market must move demand exactly as much as a board at its trend: not at all.',
        );
    }

    /**
     * TFP_DRIFT is a promise about the productivity index, and the index has to keep it.
     *
     * The index is a log random walk: a drift scaled by dt and an innovation scaled by sqrt(dt). Those two
     * scalings are the trap. A bound written in annual rate units
     * is narrower than one standard deviation of the innovation by a factor of sqrt(1/dt), so clipping the
     * increment rather than the rate clips nearly every step, and realized growth stops answering to
     * TFP_DRIFT and settles on the midpoint of the bounds instead. That is not a rounding error: at the
     * production 3600 ticks/year it delivered 1.02%/yr against the 1.50% asked for, cut the index's
     * volatility from 2.2% to 0.07%, leaving a straight line where a stochastic process belongs.
     *
     * So both moments are asserted at two timesteps an order of magnitude apart: what the index delivers
     * must be what the constants say, and must not depend on how finely the clock is ticked.
     */
    public function testTheProductivityIndexDeliversTheDriftAndVolatilityItIsParameterisedWith(): void
    {
        $expectedGrowth = MacroConstants::TFP_DRIFT;

        foreach ([[3600, 4, 80], [252, 10, 300]] as [$ticksPerYear, $seeds, $years]) {
            $annualGrowth = [];
            for ($seed = 1; $seed <= $seeds; $seed++) {
                mt_srand($seed * 7919);
                $subsystem = MacroAggregateSubsystem::headless(new MathUtility());
                $state = new MacroState();
                $state->totalFactorProductivityIndex = 100.0;
                $state->outputGapEma = 0.0;

                for ($year = 0; $year < $years; $year++) {
                    $opening = $state->totalFactorProductivityIndex;
                    for ($tick = 0; $tick < $ticksPerYear; $tick++) {
                        $subsystem->calculateTotalFactorProductivity($state, 1.0 / $ticksPerYear);
                    }
                    $annualGrowth[] = log($state->totalFactorProductivityIndex / $opening);
                }
            }

            $mean = array_sum($annualGrowth) / count($annualGrowth);
            $realizedVolatility = sqrt(
                array_sum(array_map(static fn(float $g): float => ($g - $mean) ** 2, $annualGrowth))
                    / count($annualGrowth),
            );

            $this->assertEqualsWithDelta(
                $expectedGrowth,
                $mean,
                0.0035,
                sprintf(
                    'At %d ticks/year the index grew %.3f%%/yr, not the %.3f%% it is parameterized for.',
                    $ticksPerYear,
                    $mean * 100,
                    $expectedGrowth * 100,
                ),
            );

            $this->assertEqualsWithDelta(
                ProductivityGrowth::TFP_VOLATILITY,
                $realizedVolatility,
                0.006,
                sprintf(
                    'At %d ticks/year the index realized %.4f volatility against a TFP_VOLATILITY of %.4f.',
                    $ticksPerYear,
                    $realizedVolatility,
                    ProductivityGrowth::TFP_VOLATILITY,
                ),
            );
        }
    }

    /**
     * A demand disturbance has to outlive the tick that drew it. The gap used to take an independent
     * N(0, sigma) draw on its own level every tick, so at 3600 ticks a year the top of an expansion was a
     * run of unrelated draws and the realized cycle ran at 5.5 years against a deterministic 8.8.
     */
    public function testDemandDisturbanceDecaysAtItsReversionSpeedRatherThanPerTick(): void
    {
        // One unit innovation on the first tick, silence afterwards; the demand-disaster gate stays shut.
        $draws = new ScriptedDraws(generateStandardNormal: [1.0]);
        $subsystem = MacroAggregateSubsystem::headless($draws);

        // The disaster jump's compensator rides on every tick regardless of the innovation, so measure the
        // impulse against a control run that takes the same ticks without it. The disturbance is linear, so
        // the difference between the two is the impulse alone and decays at the reversion speed.
        $control = MacroAggregateSubsystem::headless(new ScriptedDraws());

        $state = new MacroState();
        $state->outputGap = 0.0;
        $base = new MacroState();
        $base->outputGap = 0.0;
        $dt = 1.0 / 3600.0;

        $state->outputGap = $subsystem->calculateOutputGap($state, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $base->outputGap = $control->calculateOutputGap($base, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $impulse = $state->demandShock - $base->demandShock;

        $this->assertGreaterThan(0.0, $impulse, 'The innovation must land on the disturbance.');
        $this->assertEqualsWithDelta(
            OutputGap::DEMAND_SHOCK_SIGMA * sqrt($dt),
            $impulse,
            1e-9,
            'A unit own innovation scales by its own sigma * sqrt(dt).',
        );

        // Half a reversion half-life later the disturbance must still be most of the way there.
        $halfLifeTicks = (int) round((log(2.0) / OutputGap::DEMAND_SHOCK_REVERSION) * 3600.0);
        for ($i = 0; $i < $halfLifeTicks; $i++) {
            $state->outputGap = $subsystem->calculateOutputGap($state, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
            $base->outputGap = $control->calculateOutputGap($base, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        }

        $this->assertEqualsWithDelta(
            0.5 * $impulse,
            $state->demandShock - $base->demandShock,
            0.02 * $impulse,
            'After one half-life the disturbance must retain half the impulse, not have been redrawn away.',
        );
    }

    /**
     * The mainland's cycle is a level part of output beside domestic demand (Kose, Otrok & Whiteman 2003): a mainland
     * quarter moves the district's gap by the loading in the same tick, and domestic demand, which the stabilisers and
     * momentum answer, is as it was.
     */
    public function testTheMainlandsCycleMovesTheGapAtItsLoadingBesideDomesticDemand(): void
    {
        $dt = 1.0 / 360.0;
        $calm = new MacroState();
        $calm->outputGap = 0.0;
        $slumping = clone $calm;
        $slumping->foreignOutputGap = -0.02;

        $calmGap = $this->subsystem->calculateOutputGap($calm, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $slumpGap = $this->subsystem->calculateOutputGap($slumping, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);

        $this->assertEqualsWithDelta(OutputGap::MAINLAND_CYCLE_LOADING * -0.02, $slumpGap - $calmGap, 1e-15, 'Half the mainland\'s slump, at once.');
        $this->assertSame(OutputGap::MAINLAND_CYCLE_LOADING * -0.02, $slumping->mainlandCycleGap);
        $calm->outputGap = $calmGap;
        $slumping->outputGap = $slumpGap;
        $this->assertEqualsWithDelta(OutputGap::domesticDemandGap($calm), OutputGap::domesticDemandGap($slumping), 1e-15, 'Domestic demand is untouched.');
    }

    /**
     * The district's gap leaves the mainland's slump as the mainland recovers, not before: nothing pulls the part back.
     */
    public function testTheMainlandsPartStaysWhileTheMainlandDoes(): void
    {
        $dt = 1.0 / 360.0;
        $calm = new MacroState();
        $calm->outputGap = 0.0;
        $slumping = clone $calm;
        $slumping->foreignOutputGap = -0.02;
        for ($tick = 0; $tick < 360; $tick++) {
            $calm->outputGap = $this->subsystem->calculateOutputGap($calm, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
            $slumping->outputGap = $this->subsystem->calculateOutputGap($slumping, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        }
        $this->assertEqualsWithDelta(OutputGap::MAINLAND_CYCLE_LOADING * -0.02, $slumping->outputGap - $calm->outputGap, 1e-9, 'A year on, the same part.');

        $slumping->foreignOutputGap = 0.0;
        $recovered = $this->subsystem->calculateOutputGap($slumping, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $calmNext = $this->subsystem->calculateOutputGap($calm, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $this->assertEqualsWithDelta($calmNext, $recovered, 1e-9, 'and it goes with the mainland\'s recovery.');
    }

    /** The disturbance is a demand impulse in gap-drift units, so it moves the gap alongside the other Kaldor terms. */
    public function testDemandDisturbanceEntersTheGapAsADriftImpulse(): void
    {
        $neutral = new MacroState();
        $neutral->outputGap = 0.0;
        $neutral->inflation = MacroConstants::TARGET_INFLATION;
        $neutral->policyRate = MacroConstants::BASE_NATURAL_RATE + MacroConstants::TARGET_INFLATION;

        $shocked = clone $neutral;
        $shocked->demandShock = 0.01;

        $dt = 0.25;
        $baseGap = $this->subsystem->calculateOutputGap($neutral, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $shockedGap = $this->subsystem->calculateOutputGap($shocked, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);

        // The disturbance is stepped before the drift reads it, so the tick applies the already-decayed level.
        $applied = 0.01 * (1.0 - (OutputGap::DEMAND_SHOCK_REVERSION * $dt));
        $this->assertEqualsWithDelta(OutputGap::DOMESTIC_GAP_WEIGHT * $applied * $dt, $shockedGap - $baseGap, 1e-9);
    }

    /**
     * The IS curve deflates the borrowing cost by EXPECTED inflation, not current headline. A commodity spike
     * that lifts headline while expectations hold is a supply shock, and must not read as a real-rate cut.
     */
    public function testAHeadlineSpikeWithAnchoredExpectationsDoesNotEaseTheRealBorrowingRate(): void
    {
        $calm = $this->neutralBorrowingState();
        $spiked = $this->neutralBorrowingState();
        $spiked->inflation = 0.06;

        $gapCalm = $this->subsystem->calculateOutputGap($calm, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
        $gapSpiked = $this->subsystem->calculateOutputGap($spiked, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);

        $this->assertEqualsWithDelta($gapCalm, $gapSpiked, 1e-12, 'Headline inflation has no place in the ex-ante real rate.');
    }

    /**
     * A rise in short-horizon expectations lowers the real policy leg and lifts demand, as easing does; the fixed-rate
     * leg is unmoved.
     */
    public function testHigherExpectedInflationLowersTheRealPolicyLegOnly(): void
    {
        // Two fresh states: one call mutates the demand disturbance it is handed, so reusing a single
        // state would carry that mutation into the second leg instead of differencing cleanly against it.
        $anchored = $this->neutralBorrowingState();
        $unanchored = $this->neutralBorrowingState();

        $gapAnchored = $this->subsystem->calculateOutputGap($anchored, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
        $gapUnanchored = $this->subsystem->calculateOutputGap($unanchored, 0.035, MacroConstants::BASE_NATURAL_RATE, 0.03, 0.25, 1.0);

        // One percentage point of expected inflation on the policy leg is real easing below the stance borrowers are
        // used to, through the drag at the easing share over a quarter, of which a one-quarter step has passed w^2
        // through the two Pascal stages.
        $expectedLift = OutputGap::DOMESTIC_GAP_WEIGHT * FinancialConstants::RATE_EASING_SLOPE_SHARE * DomesticDemand::KALDOR_MONETARY_DRAG * DomesticDemand::BORROWING_POLICY_WEIGHT * 0.01 * $this->pascalPassThrough(0.25) * 0.25;
        $this->assertEqualsWithDelta($expectedLift, $gapUnanchored - $gapAnchored, 1e-9);
    }

    /**
     * The mirror of the case above on the restrictive side: from a stance already restrictive, lower expected inflation
     * raises the real policy leg further, and drags at the same slope.
     */
    public function testLowerExpectedInflationTightensAtTheSameSlope(): void
    {
        $anchored = $this->neutralBorrowingState();
        $tightened = $this->neutralBorrowingState();

        $gapAnchored = $this->subsystem->calculateOutputGap($anchored, 0.035, MacroConstants::BASE_NATURAL_RATE, 0.01, 0.25, 1.0);
        $gapTightened = $this->subsystem->calculateOutputGap($tightened, 0.035, MacroConstants::BASE_NATURAL_RATE, 0.0, 0.25, 1.0);
        $this->assertGreaterThan(0.0, $anchored->monetaryStanceTransmitted, 'Both legs sit on the restrictive side.');

        $expectedDrag = OutputGap::DOMESTIC_GAP_WEIGHT * DomesticDemand::KALDOR_MONETARY_DRAG * DomesticDemand::BORROWING_POLICY_WEIGHT * 0.01 * $this->pascalPassThrough(0.25) * 0.25;
        $this->assertEqualsWithDelta(-$expectedDrag, $gapTightened - $gapAnchored, 1e-9);
    }

    /**
     * Easing pushes on a string (Tenreyro & Thwaites 2016): from neutral on both legs, the stance borrowers are used to
     * here, an equal real-rate move lifts demand by the easing share of what it cuts.
     */
    public function testAnEqualEasingLiftsDemandByTheEasingShareOfWhatTighteningCuts(): void
    {
        $neutral = $this->neutralBorrowingState();
        $tight = $this->neutralBorrowingState();
        $easy = $this->neutralBorrowingState();
        // The five-year at its neutral, term premium included, so the fixed-rate leg adds no stance of its own.
        $neutral5y = $neutral->policyRate + (MacroConstants::NS_BASE_TERM_PREMIUM * FixedIncome::calculateTermPremiumDurationScale(5.0, MacroConstants::TERM_PREMIUM_DURATION_HORIZON_YEARS));

        $gapNeutral = $this->subsystem->calculateOutputGap($neutral, $neutral5y, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
        $gapTight = $this->subsystem->calculateOutputGap($tight, $neutral5y, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION - 0.01, 0.25, 1.0);
        $gapEasy = $this->subsystem->calculateOutputGap($easy, $neutral5y, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION + 0.01, 0.25, 1.0);
        $this->assertEqualsWithDelta(0.0, $neutral->monetaryStanceStage1, 1e-15, 'Both legs sit at neutral.');

        $this->assertLessThan($gapNeutral, $gapTight);
        $this->assertGreaterThan($gapNeutral, $gapEasy);
        $this->assertEqualsWithDelta(FinancialConstants::RATE_EASING_SLOPE_SHARE * ($gapNeutral - $gapTight), $gapEasy - $gapNeutral, 1e-12);
    }

    /**
     * Gilchrist & Zakrajsek (2012) premium, Barnichon, Matthes & Ziegenbein (2022) asymmetry: a premium over its trend
     * drags at the one credit slope, one under it lifts nothing more than calm credit does, and a premium at its trend
     * is calm credit.
     */
    public function testAPremiumOverItsTrendCutsDemandAndOneUnderItLiftsNothing(): void
    {
        $neutral = $this->neutralBorrowingState();
        $neutral->excessBondPremium = 0.004;
        $neutral->excessBondPremiumTrend = 0.004;
        $adverse = $this->neutralBorrowingState();
        $adverse->excessBondPremium = 0.014;
        $adverse->excessBondPremiumTrend = 0.004;
        $favorable = $this->neutralBorrowingState();
        $favorable->excessBondPremium = -0.006;
        $favorable->excessBondPremiumTrend = 0.004;
        $base = $this->neutralBorrowingState();

        $run = fn(MacroState $state): float => $this->subsystem->calculateOutputGap($state, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
        $gapNeutral = $run($neutral);

        $this->assertEqualsWithDelta($run($base), $gapNeutral, 1e-12, 'A premium at its trend is no credit shock.');
        $this->assertEqualsWithDelta(-OutputGap::DOMESTIC_GAP_WEIGHT * DomesticDemand::KALDOR_CREDIT_PREMIUM_DRAG * 0.01 * 0.25, $run($adverse) - $gapNeutral, 1e-12);
        $this->assertEqualsWithDelta(0.0, $run($favorable) - $gapNeutral, 1e-12, 'Easy credit does not lift demand.');
    }

    /**
     * Calm credit, an average catastrophe season and baseline uncertainty hand back the toll each drag's noise takes on
     * average, so on a calm day the three lift demand by exactly their compensators.
     */
    public function testACalmEconomyGetsItsDragsAverageTollBack(): void
    {
        $probe = new OutputGapProbe();
        $probe->enable();
        $state = $this->neutralBorrowingState();
        $state->catastropheLossIndexEma = 1.0;
        $state->policyUncertaintyIndexEma = MacroConstants::EPU_BASELINE;
        $dt = 0.25;
        $this->probedSubsystem($probe, 0.0)->calculateOutputGap($state, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $contributions = $this->openWindow($probe)['contributions'];

        $weight = OutputGap::DOMESTIC_GAP_WEIGHT * $dt;
        $this->assertEqualsWithDelta($weight * DomesticDemand::KALDOR_CREDIT_PREMIUM_DRAG * DomesticDemand::CREDIT_PREMIUM_NOISE_ADVERSE_MEAN, $contributions['creditPremiumDrag'], 1e-15);
        $this->assertEqualsWithDelta($weight * DomesticDemand::KALDOR_CATASTROPHE_DRAG * DomesticDemand::CATASTROPHE_ADVERSE_BURDEN_MEAN, $contributions['catastropheSupplyDrag'], 1e-15);
        $this->assertEqualsWithDelta($weight * DomesticDemand::KALDOR_EPU_DRAG * DomesticDemand::EPU_NOISE_ADVERSE_LOG_MEAN, $contributions['policyUncertaintyDrag'], 1e-15);
    }

    /**
     * Capital the banks must hold but have not built is credit premium: a 1pp shortfall drags as 0.22pp of premium
     * does.
     */
    public function testUnbuiltCapitalDragsDemandAsPremium(): void
    {
        $built = $this->neutralBorrowingState();
        $short = $this->neutralBorrowingState();
        $short->bankCapitalRequirement = $short->bankCapitalBuilt + 0.01;
        $over = $this->neutralBorrowingState();
        $over->bankCapitalBuilt = $over->bankCapitalRequirement + 0.01;

        $run = fn(MacroState $state): float => $this->subsystem->calculateOutputGap($state, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
        $gapBuilt = $run($built);

        $this->assertEqualsWithDelta(
            -OutputGap::DOMESTIC_GAP_WEIGHT * DomesticDemand::KALDOR_CREDIT_PREMIUM_DRAG * DomesticDemand::CAPITAL_SHORTFALL_PREMIUM_EQUIVALENT * 0.01 * 0.25,
            $run($short) - $gapBuilt,
            1e-12,
        );
        $this->assertEqualsWithDelta(0.0, $run($over) - $gapBuilt, 1e-12, 'Capital held beyond the requirement rations nothing.');
    }

    /**
     * The fixed-rate leg is the real five-year: the breakeven deflates it, so a higher breakeven at the same nominal
     * yield is easier money, lifting demand at the easing share from a stance already easier than borrowers are used
     * to.
     */
    public function testAHigherBreakevenAtTheSameNominalFiveYearIsEasierMoney(): void
    {
        $anchored = $this->neutralBorrowingState();
        $repriced = $this->neutralBorrowingState();
        $repriced->tipsBreakeven = 0.03;

        $gapAnchored = $this->subsystem->calculateOutputGap($anchored, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
        $gapRepriced = $this->subsystem->calculateOutputGap($repriced, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);

        $this->assertLessThan(0.0, $anchored->monetaryStanceStage1, 'The anchored stance already sits on the easy side.');
        $expectedLift = OutputGap::DOMESTIC_GAP_WEIGHT * FinancialConstants::RATE_EASING_SLOPE_SHARE * DomesticDemand::KALDOR_MONETARY_DRAG * DomesticDemand::BORROWING_YIELD5Y_WEIGHT * 0.01 * $this->pascalPassThrough(0.25) * 0.25;
        $this->assertEqualsWithDelta($expectedLift, $gapRepriced - $gapAnchored, 1e-9);
    }

    /**
     * A rate move reaches spending through a second-order Pascal lag (Solow 1960): nothing on the tick, 1 - 2/e of it
     * one lag constant later (a single stage would pass 1 - 1/e), 1 - 3/e^2 after two, and the same at any step size.
     */
    public function testARealRateStepReachesDemandThroughAPascalLag(): void
    {
        $tau = DomesticDemand::MONETARY_TRANSMISSION_LAG_YEARS;
        foreach ([1.0 / 360.0, 4.0 / 360.0] as $dt) {
            $state = $this->neutralBorrowingState();
            $state->policyRate += 0.01;
            $this->subsystem->calculateOutputGap($state, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
            $stance = $state->monetaryStanceStage1 / (1.0 - exp(-$dt / $tau));
            $this->assertLessThan(0.01 * $stance, $state->monetaryStanceTransmitted, 'Nothing reaches demand on the tick.');

            $passed = [];
            for ($i = 1; $i < (int) round(2.0 * $tau / $dt); $i++) {
                $state->outputGap = 0.0;
                $this->subsystem->calculateOutputGap($state, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
                $passed[$i + 1] = $state->monetaryStanceTransmitted / $stance;
            }
            $this->assertEqualsWithDelta(1.0 - (2.0 / M_E), $passed[(int) round($tau / $dt)], 0.01, "One lag constant in at dt {$dt}.");
            $this->assertEqualsWithDelta(1.0 - (3.0 / M_E ** 2), end($passed), 0.01, "Two lag constants in at dt {$dt}.");
        }
    }

    /** Share of a stance step that has passed both Pascal stages after one step of $dt from rest: w^2. */
    private function pascalPassThrough(float $dt): float
    {
        return (1.0 - exp(-$dt / DomesticDemand::MONETARY_TRANSMISSION_LAG_YEARS)) ** 2;
    }

    /**
     * Demand answers a change in the tax stance, not its level: a rate held for a decade is in potential (Blanchard
     * 1990).
     */
    public function testTheTaxChannelReadsTheRateAgainstItsTrend(): void
    {
        $neutral = $this->neutralBorrowingState();
        $heldHigh = $this->neutralBorrowingState();
        $heldHigh->corporateTaxRate = MacroConstants::PUBLIC_PURCHASES_TO_GDP + 0.04;
        $heldHigh->corporateTaxRateTrend = $heldHigh->corporateTaxRate;
        $freshRise = $this->neutralBorrowingState();
        $freshRise->corporateTaxRate = MacroConstants::PUBLIC_PURCHASES_TO_GDP + 0.04;

        $gap = fn(MacroState $state): float => $this->subsystem->calculateOutputGap($state, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
        $gapNeutral = $gap($neutral);

        $this->assertEqualsWithDelta($gapNeutral, $gap($heldHigh), 1e-12, 'A stance the trend has caught up with leaves the gap alone.');
        $this->assertLessThan($gapNeutral, $gap($freshRise), 'A rise the trend has not caught up with costs demand.');
    }

    /** A state with every demand channel at its baseline, so only the borrowing-cost terms can move the gap. */
    /**
     * The overhang is kept in the inventory-to-sales ratio its readers take it in: a miss worth a share of output, over
     * the ratio.
     */
    public function testADemandMissBuildsAnOverhangInTheInventoryToSalesRatio(): void
    {
        $state = $this->neutralBorrowingState();
        $state->outputGap = -0.01 * OutputGap::DOMESTIC_GAP_WEIGHT;
        $state->domesticDemandGapEma = 0.0;

        $this->subsystem->calculateOutputGap($state, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);

        $speed = InventoryAndTiming::INVENTORY_ADJUSTMENT_SPEED;
        $built = InventoryAndTiming::INVENTORY_SURPRISE_SENSITIVITY * 0.01 / InventoryAndTiming::INVENTORY_TO_ANNUAL_SALES;
        $this->assertEqualsWithDelta($built * (1.0 - exp(-$speed * 0.25)) / $speed, $state->inventoryStockGap, 1e-12);
    }

    /**
     * Output is final sales plus the change in inventories: the stock built over a step, out of a year's domestic sales
     * on domestic demand's weight, is the step's inventory investment, and the gap moves by its change while final
     * sales stand where they were.
     */
    public function testInventoryInvestmentReachesGdpAsTheStocksChange(): void
    {
        $dt = 1.0 / 360.0;
        $step = function (float $stock, float $costShock) use ($dt): array {
            $probe = new OutputGapProbe();
            $probe->enable();
            $state = $this->neutralBorrowingState();
            $state->inventoryStockGap = $stock;
            $state->inventoryCostShock = $costShock;
            $state->inventoryInvestmentGap = 0.001;
            $state->outputGap = 0.001;
            $state->outputGap = $this->probedSubsystem($probe, 0.0)->calculateOutputGap($state, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);

            return [$state, $this->openWindow($probe)['contributions']['inventoryInvestment']];
        };
        [$quiet] = $step(0.0, 0.0);
        [$state, $contribution] = $step(0.04, 0.004);

        $built = OutputGap::DOMESTIC_GAP_WEIGHT * InventoryAndTiming::INVENTORY_TO_ANNUAL_SALES * ($state->inventoryStockGap - 0.04);
        $this->assertEqualsWithDelta($built / $dt, $state->inventoryInvestmentGap, 1e-15, 'Investment is the stock built over the step.');
        $this->assertEqualsWithDelta($state->inventoryInvestmentGap - 0.001, $contribution, 1e-15, 'The gap moves by investment\'s change.');
        $this->assertEqualsWithDelta($state->inventoryInvestmentGap - $quiet->inventoryInvestmentGap, $state->outputGap - $quiet->outputGap, 1e-15, 'GDP differs by the investment.');
        $this->assertEqualsWithDelta(OutputGap::domesticDemandGap($quiet), OutputGap::domesticDemandGap($state), 1e-15, 'Final sales do not.');
    }

    /**
     * Lovell (1961): the stock firms want follows expected sales. Held below trend, sales leave stock nobody wants, and
     * it is sold down until the stock is the target again: the slump's net inventory investment is the fall in the
     * target, out of a year's domestic sales on domestic demand's weight. The target's own move is no investment, so
     * the summed flow is the stock's change, the overhang plus the target it is read against.
     */
    public function testALastingSlumpSellsDownTheStockNoLongerWanted(): void
    {
        $state = $this->neutralBorrowingState();
        $state->domesticDemandGapEma = 0.0;
        $sales = -0.03;
        $dt = 1.0 / 72.0;
        $sold = 0.0;
        for ($tick = 0; $tick < 72 * 25; ++$tick) {
            // Sales held at -3%: GDP is them on their weight plus the inventory investment beside them.
            $state->outputGap = (OutputGap::DOMESTIC_GAP_WEIGHT * $sales) + $state->inventoryInvestmentGap;
            $this->subsystem->calculateOutputGap($state, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
            $sold += $state->inventoryInvestmentGap * $dt;
            $this->subsystem->updateExponentialMovingAverages($state, $dt);
        }

        $stock = OutputGap::DOMESTIC_GAP_WEIGHT * InventoryAndTiming::INVENTORY_TO_ANNUAL_SALES;
        $this->assertEqualsWithDelta($stock * ($state->inventoryStockGap + $state->domesticDemandGapEma), $sold, 1e-12, 'The summed flow is the stock\'s change.');
        $this->assertEqualsWithDelta($stock * $sales, $sold, 1e-5, 'Twenty-five years on, the stock is the lower target.');
        $this->assertEqualsWithDelta(0.0, $state->inventoryStockGap, 1e-5, 'Nothing is left over the target.');
    }

    /**
     * Purchases moved between quarters are spending in the quarter they land in: the stock of them brought forward
     * changes by the step's flow, the gap moves by the flow's change, and the demand the demand equation runs on does
     * not move.
     */
    public function testSpendingTimingReachesGdpAsTheStocksChange(): void
    {
        $dt = 1.0 / 360.0;
        $step = function (float $pulledForward, float $timingShock) use ($dt): array {
            $probe = new OutputGapProbe();
            $probe->enable();
            $state = $this->neutralBorrowingState();
            $state->spendingPulledForward = $pulledForward;
            $state->spendingTimingShock = $timingShock;
            $state->spendingTimingGap = 0.001;
            $state->outputGap = 0.001;
            $state->outputGap = $this->probedSubsystem($probe, 0.0)->calculateOutputGap($state, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);

            return [$state, $this->openWindow($probe)['contributions']['spendingTiming']];
        };
        [$quiet] = $step(0.0, 0.0);
        [$state, $contribution] = $step(0.002, 0.006);

        $this->assertEqualsWithDelta(($state->spendingPulledForward - 0.002) / $dt, $state->spendingTimingGap, 1e-15, 'The flow is the stock\'s change.');
        $this->assertEqualsWithDelta($state->spendingTimingGap - 0.001, $contribution, 1e-15, 'The gap moves by the flow\'s change.');
        $this->assertEqualsWithDelta($state->spendingTimingGap - $quiet->spendingTimingGap, $state->outputGap - $quiet->outputGap, 1e-15, 'GDP differs by the timing.');
        $this->assertEqualsWithDelta(OutputGap::domesticDemandGap($quiet), OutputGap::domesticDemandGap($state), 1e-15, 'Underlying demand does not.');
    }

    /**
     * What is bought early is not bought later: spending brought forward lifts GDP while it lands and leaves it below
     * trend while the quarters it came from go without, and once it is paid back it has added nothing.
     */
    public function testSpendingBroughtForwardIsNotSpentAgain(): void
    {
        $dt = 0.02;
        $shock = 0.01;
        $pulledForward = 0.0;
        $spent = 0.0;
        $path = [];
        for ($step = 0; $step < 1500; ++$step) {
            $shock = StochasticProcesses::calculateOrnsteinUhlenbeckStep($shock, InventoryAndTiming::SPENDING_TIMING_SHOCK_REVERSION, InventoryAndTiming::SPENDING_TIMING_SHOCK_SD, $dt, 0.0);
            $next = InventoryAndTiming::spendingPulledForwardAfter($pulledForward, $shock, $dt);
            $flow = ($next - $pulledForward) / $dt;
            $pulledForward = $next;
            $spent += $flow * $dt;
            $path[] = $flow;
        }

        $this->assertGreaterThan(0.0, $path[0], 'Purchases brought forward land as spending.');
        $this->assertLessThan(0.0, min($path), 'The quarters they came from go without.');
        $this->assertEqualsWithDelta(0.0, $spent, 1e-9, 'Thirty years on, the timing has added no spending.');
    }

    /**
     * Production run ahead of plan goes into stock and is worked off: GDP rises while it runs and falls below trend as
     * the stock is sold down, and once the stock is back the output it added has been taken back.
     */
    public function testProductionOverPlanLeavesNoLastingOutput(): void
    {
        $dt = 0.05;
        $shock = 0.01;
        $stock = 0.0;
        $cumulative = 0.0;
        $path = [];
        for ($step = 0; $step < 600; ++$step) {
            $shock = StochasticProcesses::calculateOrnsteinUhlenbeckStep($shock, InventoryAndTiming::INVENTORY_COST_SHOCK_REVERSION, InventoryAndTiming::INVENTORY_COST_SHOCK_SD, $dt, 0.0);
            $next = FirmEconomics::calculateInventoryCycleStep($stock, 0.0, 0.0, InventoryAndTiming::INVENTORY_ADJUSTMENT_SPEED, InventoryAndTiming::INVENTORY_SURPRISE_SENSITIVITY, $dt, InventoryAndTiming::INVENTORY_TO_ANNUAL_SALES, $shock);
            $investment = InventoryAndTiming::inventoryInvestmentAt($next - $stock, $dt);
            $stock = $next;
            $cumulative += $investment * $dt;
            $path[] = $investment;
        }

        $this->assertGreaterThan(0.0, $path[0], 'Production over plan is investment in stock.');
        $this->assertLessThan(0.0, min($path), 'The stock is then sold down below trend production.');
        $this->assertEqualsWithDelta(0.0, $cumulative, 1e-6, 'Thirty years on, the output the shock added has been taken back.');
    }

    private function neutralBorrowingState(): MacroState
    {
        $state = new MacroState();
        $state->outputGap = 0.0;
        $state->outputGapEma = 0.0;
        $state->inflation = MacroConstants::TARGET_INFLATION;
        $state->tipsBreakeven = MacroConstants::TARGET_INFLATION;
        $state->policyRate = MacroConstants::BASE_NATURAL_RATE + MacroConstants::TARGET_INFLATION;
        $state->naturalRate = MacroConstants::BASE_NATURAL_RATE;
        $state->corporateTaxRate = MacroConstants::PUBLIC_PURCHASES_TO_GDP;
        $state->corporateTaxRateTrend = MacroConstants::PUBLIC_PURCHASES_TO_GDP;
        $state->macroCreditSpreadEma = MacroConstants::BASE_CREDIT_SPREAD;
        $state->interbankLiquiditySpreadEma = MacroConstants::INTERBANK_BASELINE_SPREAD;
        $state->governmentSpendingIndexEma = MacroConstants::GOVT_SPENDING_BASELINE;
        $state->residentialPropertyIndexEma = AssetMarketSubsystem::RESIDENTIAL_BASELINE;
        $state->exchangeRateIndexEma = AssetMarketSubsystem::EXCHANGE_RATE_BASELINE;
        $state->exchangeRateTrend = AssetMarketSubsystem::EXCHANGE_RATE_BASELINE;
        $state->energyPriceIndexEma = MacroConstants::ENERGY_BASELINE;
        $state->freightRateIndexEma = MacroConstants::FREIGHT_BASELINE;
        $state->capitalStockOverhang = 0.0;
        $state->inventoryStockGap = 0.0;
        $state->demandShock = 0.0;

        return $state;
    }

    // --- Ten-Year Breakeven ---

    /** Core on target and nothing passing through: the ten-year prices the target, where the US fit is anchored. */
    public function testTheBreakevenPricesTheTargetWhenCoreIsOnTargetAndNothingPassesThrough(): void
    {
        $this->assertEqualsWithDelta(MacroConstants::TARGET_INFLATION, $this->breakeven($this->onTargetInflationState()), 1e-15);
    }

    /**
     * The ten-year moves by the fitted loadings: per point of core over target, and per point of headline over core.
     */
    public function testTheBreakevenMovesByItsLoadingsOnCoreAndOnHeadlineOverCore(): void
    {
        $base = $this->onTargetInflationState();
        $core = $this->onTargetInflationState();
        $core->supercoreInflationEma += 0.01;
        $core->coreGoodsInflationEma += 0.01;
        $commodity = $this->onTargetInflationState();
        $commodity->noncorePassThroughEma = 0.01;

        $this->assertEqualsWithDelta(ConsumerPrices::BREAKEVEN_CORE_LOADING * 0.01, $this->breakeven($core) - $this->breakeven($base), 1e-15);
        $this->assertEqualsWithDelta(ConsumerPrices::BREAKEVEN_NONCORE_LOADING * 0.01, $this->breakeven($commodity) - $this->breakeven($base), 1e-15);
    }

    /**
     * The learned endpoint moves the ten-year by its loading, and core is read over that endpoint: with core on it the
     * breakeven takes the endpoint's loading alone, with core still on target the core loading comes off it.
     */
    public function testTheBreakevenPricesTheLearnedEndpointAndReadsCoreOverIt(): void
    {
        $onEndpoint = $this->onTargetInflationState();
        $onEndpoint->inflationEndpointGap = 0.01;
        $onEndpoint->supercoreInflationEma += 0.01;
        $onEndpoint->coreGoodsInflationEma += 0.01;
        $this->assertEqualsWithDelta(ConsumerPrices::BREAKEVEN_ENDPOINT_LOADING * 0.01, $this->breakeven($onEndpoint) - MacroConstants::TARGET_INFLATION, 1e-15);

        $coreOnTarget = $this->onTargetInflationState();
        $coreOnTarget->inflationEndpointGap = 0.01;
        $this->assertEqualsWithDelta((ConsumerPrices::BREAKEVEN_ENDPOINT_LOADING - ConsumerPrices::BREAKEVEN_CORE_LOADING) * 0.01, $this->breakeven($coreOnTarget) - MacroConstants::TARGET_INFLATION, 1e-15);
    }

    /**
     * A commodity spike is priced as passing. The pass-through adds itself to headline one for one, and the ten-year
     * takes some of it, less than the learned endpoint's own loading: a spike is not a new inflation regime.
     */
    public function testAnEnergySpikeReachesTheTenYearLessThanANewRegimeWould(): void
    {
        $spike = $this->onTargetInflationState();
        $spike->noncorePassThroughEma = 0.015;

        $share = ($this->breakeven($spike) - MacroConstants::TARGET_INFLATION) / $spike->noncorePassThroughEma;

        $this->assertGreaterThan(0.0, $share);
        $this->assertLessThan(ConsumerPrices::BREAKEVEN_ENDPOINT_LOADING, $share);
    }

    /**
     * The gap adds nothing once inflation is held (CBO gap 0.02, se 0.02), and neither headline noise nor equity panic
     * is an expectation.
     */
    public function testTheBreakevenIgnoresTheGapHeadlineNoiseAndEquityVolatility(): void
    {
        $hot = $this->onTargetInflationState();
        $hot->outputGap = 0.04;
        $hot->outputGapEma = 0.04;
        $hot->inflation = 0.05;
        $hot->inflationEma = 0.045;
        $hot->marketVolatilityEma = 0.45;

        $this->assertSame($this->breakeven($this->onTargetInflationState()), $this->breakeven($hot));
    }

    /**
     * The quote dips with the excess bond premium by the fitted -0.356 a point, the TIPS liquidity squeeze; the
     * expected inflation the economy reads does not move with it.
     */
    public function testTheQuotedBreakevenDipsWithTheBondPremiumButExpectationsDoNot(): void
    {
        $squeezed = $this->onTargetInflationState();
        $squeezed->excessBondPremium = 0.02;

        $this->assertSame($this->breakeven($this->onTargetInflationState()), $this->breakeven($squeezed));
        $this->assertEqualsWithDelta(0.02 - 0.00712, MacroAggregateSubsystem::quotedTipsBreakeven(0.02, 0.02), 1e-15);
        $this->assertSame(0.021, MacroAggregateSubsystem::quotedTipsBreakeven(0.021, 0.0), 'with no premium the quote is the expectation');
        $this->assertGreaterThan(0.02, MacroAggregateSubsystem::quotedTipsBreakeven(0.02, -0.005), 'a calm credit market prices TIPS rich');
    }

    private function breakeven(MacroState $state): float
    {
        return $this->subsystem->calculateTipsBreakeven($state, MacroConstants::TARGET_INFLATION);
    }

    /** Core at target and no energy or food pass-through. */
    private function onTargetInflationState(): MacroState
    {
        $state = new MacroState();
        $state->supercoreInflationEma = MacroConstants::TARGET_INFLATION;
        $state->coreGoodsInflationEma = MacroConstants::TARGET_INFLATION;
        $state->noncorePassThroughEma = 0.0;

        return $state;
    }

    /**
     * Baker, Bloom & Davis (2016): spikes in policy uncertainty cost output (Bloom 2009 wait-and-see), but calm does
     * not stimulate.
     */
    public function testPolicyUncertaintyDragsDemandInLogs(): void
    {
        $neutral = new MacroState();
        $doubled = new MacroState();
        $doubled->policyUncertaintyIndexEma = 2.0 * MacroConstants::EPU_BASELINE;
        $halved = new MacroState();
        $halved->policyUncertaintyIndexEma = 0.5 * MacroConstants::EPU_BASELINE;

        $dt = 0.25;
        $gapNeutral = $this->subsystem->calculateOutputGap($neutral, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $gapDoubled = $this->subsystem->calculateOutputGap($doubled, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $gapHalved = $this->subsystem->calculateOutputGap($halved, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);

        $expected = OutputGap::DOMESTIC_GAP_WEIGHT * DomesticDemand::KALDOR_EPU_DRAG * log(2.0) * $dt;
        $this->assertEqualsWithDelta(-$expected, $gapDoubled - $gapNeutral, 1e-9, 'A doubling of the index takes the drag off demand over the quarter.');
        $this->assertEqualsWithDelta(0.0, $gapHalved - $gapNeutral, 1e-9, 'A halving does not stimulate: uncertainty drag is one-sided.');
    }

    /** Noy (2009): destruction is a supply loss on impact; an average year is no drag at all. */
    public function testCatastropheLossesDragOutputOnlyAboveAnAverageYear(): void
    {
        $average = new MacroState();
        $quiet = new MacroState();
        $quiet->catastropheLossIndexEma = 0.2;
        $stormy = new MacroState();
        $stormy->catastropheLossIndexEma = 3.0;

        $dt = 0.25;
        $gapAverage = $this->subsystem->calculateOutputGap($average, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $gapQuiet = $this->subsystem->calculateOutputGap($quiet, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $gapStormy = $this->subsystem->calculateOutputGap($stormy, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);

        $this->assertEqualsWithDelta($gapAverage, $gapQuiet, 1e-12, 'A quiet season is not a boom.');
        $this->assertEqualsWithDelta(-OutputGap::DOMESTIC_GAP_WEIGHT * DomesticDemand::KALDOR_CATASTROPHE_DRAG * 2.0 * $dt, $gapStormy - $gapAverage, 1e-9);
    }

    /**
     * Senhadji & Montenegro (1999): exports follow partner income by partial adjustment, a year bringing the short-run
     * elasticity (1 - lambda of the long-run one) and a held boom the whole long-run response. They are a level part of
     * the gap.
     */
    public function testAMainlandBoomReachesExportsByPartialAdjustment(): void
    {
        $home = new MacroState();
        $abroad = new MacroState();
        $abroad->foreignOutputGapEma = 0.03;

        $dt = 0.25;
        $gapHome = $this->subsystem->calculateOutputGap($home, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $gapAbroad = $this->subsystem->calculateOutputGap($abroad, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);

        // The mainland's demand buys the civilian exports, of which the District's own value added is output; the arms
        // makers sell into allied budgets instead.
        $longRun = (OpenEconomy::DISTRICT_EXPORT_SHARE - OpenEconomy::DISTRICT_DEFENSE_EXPORT_SHARE) * (1.0 - OpenEconomy::FOREIGN_VALUE_ADDED_SHARE_OF_EXPORTS) * OpenEconomy::EXPORT_DEMAND_ELASTICITY * 0.03;
        $firstQuarter = 1.0 - exp(-$dt / OpenEconomy::exportDemandAdjustmentYears());
        $this->assertEqualsWithDelta($firstQuarter * $longRun, $abroad->netExportGap, 1e-12, 'A quarter brings the first slice of the response, not all of it.');
        $this->assertEqualsWithDelta($firstQuarter * $longRun, $gapAbroad - $gapHome, 1e-9, 'The export level reaches the gap whole, not at a rate.');

        $home->outputGap = $gapHome;
        $abroad->outputGap = $gapAbroad;
        for ($i = 1; $i < 4; $i++) {
            $home->outputGap = $gapHome = $this->subsystem->calculateOutputGap($home, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
            $abroad->outputGap = $gapAbroad = $this->subsystem->calculateOutputGap($abroad, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        }
        $this->assertEqualsWithDelta((1.0 - OpenEconomy::EXPORT_DEMAND_PERSISTENCE) * $longRun, $abroad->netExportGap, 1e-12, 'A year brings the short-run elasticity.');

        // The domestic-demand equation does not pull the export level back: held, it builds to the long-run size and
        // stays.
        for ($i = 0; $i < 120; $i++) {
            $home->outputGap = $gapHome = $this->subsystem->calculateOutputGap($home, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
            $abroad->outputGap = $gapAbroad = $this->subsystem->calculateOutputGap($abroad, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        }
        $this->assertEqualsWithDelta($longRun, $abroad->netExportGap, 1e-6);
        $this->assertEqualsWithDelta($longRun, $gapAbroad - $gapHome, 1e-6);
    }

    /**
     * The UK equation's own numbers: 0.48 within the year, 1.43 in the long run, lambda 0.66 on last year's exports.
     */
    public function testExportPartialAdjustmentIsTheUkEquation(): void
    {
        $this->assertEqualsWithDelta(OpenEconomy::EXPORT_DEMAND_PERSISTENCE, exp(-1.0 / OpenEconomy::exportDemandAdjustmentYears()), 1e-12, 'A year of the lag leaves lambda of the gap to close.');
        $this->assertEqualsWithDelta(0.48, (1.0 - OpenEconomy::EXPORT_DEMAND_PERSISTENCE) * OpenEconomy::EXPORT_DEMAND_ELASTICITY, 0.01);
    }

    /**
     * The District fields no army: an allied build-up is its arms makers' order book, and reaches GDP as exports once
     * the backlog delivers it, at the makers' share of GDP and the procurement elasticity. The civilian export share
     * does not answer it.
     */
    public function testAnAlliedBuildUpReachesExportsThroughTheArmsBacklog(): void
    {
        $home = new MacroState();
        $armed = new MacroState();
        $armed->alliedDefenseSpendingIndexEma = 150.0;

        $dt = 0.25;
        $gapHome = $this->subsystem->calculateOutputGap($home, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $gapArmed = $this->subsystem->calculateOutputGap($armed, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);

        $delivered = OpenEconomy::DISTRICT_DEFENSE_EXPORT_SHARE * MacroConstants::ALLIED_PROCUREMENT_ELASTICITY * log(1.5);
        $firstQuarter = 1.0 - exp(-$dt / OpenEconomy::DEFENSE_DELIVERY_LAG_YEARS);
        $this->assertEqualsWithDelta($firstQuarter * $delivered, $armed->netExportGap, 1e-12, 'A quarter delivers the backlog\'s first slice, not the order.');
        $this->assertEqualsWithDelta($firstQuarter * $delivered, $gapArmed - $gapHome, 1e-9);

        $home->outputGap = $gapHome;
        $armed->outputGap = $gapArmed;
        for ($i = 0; $i < 60; $i++) {
            $home->outputGap = $gapHome = $this->subsystem->calculateOutputGap($home, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
            $armed->outputGap = $gapArmed = $this->subsystem->calculateOutputGap($armed, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        }
        $this->assertEqualsWithDelta($delivered, $armed->netExportGap, 1e-5, 'Held, the whole order book is delivered.');
        $this->assertEqualsWithDelta($delivered, $gapArmed - $gapHome, 1e-5, 'And it stays in the gap as a level.');
        $this->assertGreaterThan(0.005, $delivered, 'A half again allied budget is worth more than half a point of District GDP.');
    }

    /** The mainland's cycle does not buy arms, and an allied build-up does not buy the civilian exports. */
    public function testArmsExportsAnswerAlliedBudgetsNotTheMainlandCycle(): void
    {
        $mainlandBoom = OpenEconomy::netExportGapAt(0.03, 0.0, 0.0);
        $this->assertEqualsWithDelta((OpenEconomy::DISTRICT_EXPORT_SHARE - OpenEconomy::DISTRICT_DEFENSE_EXPORT_SHARE) * (1.0 - OpenEconomy::FOREIGN_VALUE_ADDED_SHARE_OF_EXPORTS) * OpenEconomy::EXPORT_DEMAND_ELASTICITY * 0.03, $mainlandBoom, 1e-15);

        $buildUp = OpenEconomy::netExportGapAt(0.0, 0.0, 0.2);
        $this->assertEqualsWithDelta(OpenEconomy::DISTRICT_DEFENSE_EXPORT_SHARE * MacroConstants::ALLIED_PROCUREMENT_ELASTICITY * 0.2, $buildUp, 1e-15);
        $this->assertEqualsWithDelta($mainlandBoom + $buildUp, OpenEconomy::netExportGapAt(0.03, 0.0, 0.2), 1e-15, 'The two export markets add.');
    }

    /**
     * The goods volume equations a port reads are the ones net exports are built from: a duty's whole effect on net
     * exports is the import volume it costs on goods for home use and the export volume partners' retaliation costs.
     */
    public function testGoodsVolumeEquationsAreTheNetExportTariffLegs(): void
    {
        $duty = log(1.12);
        $domesticContent = 1.0 - OpenEconomy::FOREIGN_VALUE_ADDED_SHARE_OF_EXPORTS;
        $retaliatedExportShare = ((OpenEconomy::DISTRICT_EXPORT_SHARE * OpenEconomy::GOODS_SHARE_OF_EXPORTS) - OpenEconomy::DISTRICT_DEFENSE_EXPORT_SHARE) * $domesticContent;
        $homeGoodsImportShare = OpenEconomy::DISTRICT_DOMESTIC_IMPORT_SHARE * OpenEconomy::GOODS_SHARE_OF_IMPORTS;

        $this->assertEqualsWithDelta(
            ($retaliatedExportShare * OpenEconomy::goodsExportVolumeGap(0.0, 0.0, $duty)) - ($homeGoodsImportShare * OpenEconomy::goodsImportVolumeGap(0.0, 0.0, $duty)),
            OpenEconomy::netExportGapAt(0.0, 0.0, 0.0, $duty),
            1e-15,
        );
        $this->assertLessThan(0.0, OpenEconomy::goodsImportVolumeGap(0.0, 0.0, $duty), 'A duty costs imports.');
        $this->assertLessThan(0.0, OpenEconomy::goodsExportVolumeGap(0.0, 0.0, $duty), 'Retaliation costs exports.');
        $this->assertGreaterThan(0.0, OpenEconomy::goodsImportVolumeGap(0.0, 0.1, 0.0), 'A dear currency draws imports in.');
        $this->assertLessThan(0.0, OpenEconomy::goodsExportVolumeGap(0.0, 0.1, 0.0), 'And prices exports out.');
    }

    /**
     * Drehmann, Juselius & Korinek (2018): borrowed income is spent as it is borrowed, and income repaid out of the
     * stock is not spent.
     */
    public function testNewHouseholdBorrowingMovesDemandBothWays(): void
    {
        $neutral = new MacroState();
        $borrowing = new MacroState();
        $borrowing->householdNewBorrowing = 0.03;
        $repaying = new MacroState();
        $repaying->householdNewBorrowing = -0.03;

        $dt = 0.25;
        $gapNeutral = $this->subsystem->calculateOutputGap($neutral, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $gapBorrowing = $this->subsystem->calculateOutputGap($borrowing, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $gapRepaying = $this->subsystem->calculateOutputGap($repaying, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);

        $this->assertGreaterThan(0.0, DomesticDemand::KALDOR_HOUSEHOLD_NEW_BORROWING);
        $this->assertEqualsWithDelta(OutputGap::DOMESTIC_GAP_WEIGHT * DomesticDemand::KALDOR_HOUSEHOLD_NEW_BORROWING * 0.03 * $dt, $gapBorrowing - $gapNeutral, 1e-9, 'Three points of income borrowed a year are spent.');
        $this->assertEqualsWithDelta(-OutputGap::DOMESTIC_GAP_WEIGHT * DomesticDemand::KALDOR_HOUSEHOLD_NEW_BORROWING * 0.03 * $dt, $gapRepaying - $gapNeutral, 1e-9, 'Paying the stock down is the same flow the other way.');
    }

    /**
     * Drehmann, Juselius & Korinek (2018): service below its average leaves income to spend; above it, repayment drags.
     */
    public function testHouseholdDebtServiceMovesDemandBothWaysAroundItsAverage(): void
    {
        $neutral = new MacroState();
        $carried = new MacroState();
        $carried->householdDebtServiceGap = -0.02;
        $repaying = new MacroState();
        $repaying->householdDebtServiceGap = 0.02;

        $dt = 0.25;
        $gapNeutral = $this->subsystem->calculateOutputGap($neutral, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $gapCarried = $this->subsystem->calculateOutputGap($carried, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $gapRepaying = $this->subsystem->calculateOutputGap($repaying, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);

        $this->assertEqualsWithDelta(OutputGap::DOMESTIC_GAP_WEIGHT * DomesticDemand::KALDOR_HOUSEHOLD_DEBT_SERVICE * 0.02 * $dt, $gapCarried - $gapNeutral, 1e-9, 'Two points of service below average leave a point a year of demand.');
        $this->assertEqualsWithDelta(-OutputGap::DOMESTIC_GAP_WEIGHT * DomesticDemand::KALDOR_HOUSEHOLD_DEBT_SERVICE * 0.02 * $dt, $gapRepaying - $gapNeutral, 1e-9, 'Two points over it take a point a year off demand.');
    }

    public function testCrisisDeleveragingDragsDemandOneForOneAndStandardsDoNot(): void
    {
        $neutral = new MacroState();
        $deleveraging = new MacroState();
        $deleveraging->creditCrisisDrag = 0.02;
        $tightStandards = new MacroState();
        $tightStandards->sloosTighteningIndexEma = 0.80;

        $dt = 0.25;
        $run = fn(MacroState $state): float => $this->subsystem->calculateOutputGap($state, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $gapNeutral = $run($neutral);

        $this->assertEqualsWithDelta(-OutputGap::DOMESTIC_GAP_WEIGHT * (1.0 - CreditFiscalSubsystem::CRISIS_HOUSEHOLD_REPAYMENT_SHARE) * 0.02 * $dt, $run($deleveraging) - $gapNeutral, 1e-9, 'Firms\' and banks\' share of the crisis drag enters the drift at face value; the households\' share is debt repaid.');
        $this->assertEqualsWithDelta(0.0, $run($tightStandards) - $gapNeutral, 1e-12, 'Lending standards are a survey reading; the premium they follow is what drags.');
    }

    /**
     * The aggregate demand disturbance and the output gap's own diffusion are separate innovations in
     * the estimated system (Smets & Wouters 2007); one draw serving both correlates them at unity. Production
     * over plan, a cost shock, is a third, and spending moved between quarters a fourth.
     */
    public function testDemandDisturbanceAndGapDiffusionDrawSeparateInnovations(): void
    {
        $dt = 0.25;
        // The demand-disaster gate stays shut so the tick is deterministic.
        $math = new ScriptedDraws();
        $subsystem = MacroAggregateSubsystem::headless($math);

        $run = function (float $demandDraw, float $diffusionDraw, float $inventoryDraw = 0.0, float $timingDraw = 0.0) use ($subsystem, $math, $dt): array {
            $math->script(generateStandardNormal: [$demandDraw, $diffusionDraw, $inventoryDraw, $timingDraw]);
            $drawn = $math->callCount('generateStandardNormal');
            $state = new MacroState();
            $state->outputGap = 0.0;
            $state->inflation = 0.02;
            $state->policyRate = 0.02;
            $state->wageGrowth = 0.035;
            $gap = $subsystem->calculateOutputGap($state, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);

            return [$gap, $state->demandShock, $math->callCount('generateStandardNormal') - $drawn, $state->inventoryCostShock, $state->spendingTimingShock];
        };

        [$neutralGap, $neutralShock, $calls] = $run(0.0, 0.0);
        // A shared draw fails here first: it consumes one innovation, not four.
        $this->assertSame(4, $calls, 'One innovation each for the demand disturbance, the gap diffusion, production over plan and spending timing');
        // With both innovations silent the disturbance still carries the disaster jump's compensator, which
        // is a deterministic function of dt and is therefore the baseline the other two runs are read against.

        // Second draw only: the gap moves by the full diffusion, the disturbance does not move at all.
        [$diffusionGap, $diffusionShock, , $diffusionCost] = $run(0.0, 1.0);
        $this->assertEqualsWithDelta($neutralShock, $diffusionShock, 1e-15, 'A diffusion innovation must not enter the demand disturbance');
        $this->assertSame(0.0, $diffusionCost, 'Nor production over plan.');
        $this->assertEqualsWithDelta(
            OutputGap::DOMESTIC_GAP_WEIGHT * OutputGap::OUTPUT_GAP_DIFFUSION_SIGMA * sqrt($dt),
            $diffusionGap - $neutralGap,
            1e-12,
        );

        // First draw only: the disturbance takes it whole and reaches the gap only through the drift.
        [$demandGap, $demandShock] = $run(1.0, 0.0);
        $expectedShock = OutputGap::DEMAND_SHOCK_SIGMA * sqrt($dt);
        $this->assertEqualsWithDelta($expectedShock, $demandShock - $neutralShock, 1e-15);
        $this->assertEqualsWithDelta(OutputGap::DOMESTIC_GAP_WEIGHT * $expectedShock * $dt, $demandGap - $neutralGap, 1e-12);

        // Third draw only: production over plan takes it whole and the demand disturbance does not move.
        [, $inventoryShock, , $inventoryCost] = $run(0.0, 0.0, 1.0);
        $decay = exp(-InventoryAndTiming::INVENTORY_COST_SHOCK_REVERSION * $dt);
        $this->assertEqualsWithDelta(InventoryAndTiming::INVENTORY_COST_SHOCK_SD * sqrt(1.0 - ($decay * $decay)), $inventoryCost, 1e-15);
        $this->assertEqualsWithDelta($neutralShock, $inventoryShock, 1e-15, 'A cost innovation must not enter the demand disturbance');

        // Fourth draw only: spending timing takes it whole, and neither the disturbance nor production over plan moves.
        [, $timedShock, , $timedCost, $timing] = $run(0.0, 0.0, 0.0, 1.0);
        $timingDecay = exp(-InventoryAndTiming::SPENDING_TIMING_SHOCK_REVERSION * $dt);
        $this->assertEqualsWithDelta(InventoryAndTiming::SPENDING_TIMING_SHOCK_SD * sqrt(1.0 - ($timingDecay * $timingDecay)), $timing, 1e-15);
        $this->assertEqualsWithDelta($neutralShock, $timedShock, 1e-15);
        $this->assertSame(0.0, $timedCost);
    }

    /**
     * A subsystem wired to a live probe, with a diffusion draw that is not zero.
     *
     * The class fixture returns 0.0 from every normal, which is what most of these tests want and is
     * exactly wrong here: it would leave the diffusion and clamp legs of the accounting untested.
     */
    // --- The Diet's levers ---

    public function testATariffPassesIntoImportPricesInFull(): void
    {
        $this->assertEqualsWithDelta(log(1.10), OpenEconomy::importPriceLevelTarget(92.0, 0.10) - OpenEconomy::importPriceLevelTarget(92.0), 1e-15);
    }

    public function testATariffCompressesImportedGoodsAndDrawsRetaliationOnGoodsExports(): void
    {
        $duty = log(1.10);
        $switching = OpenEconomy::IMPORT_PRICE_ELASTICITY * OpenEconomy::DISTRICT_DOMESTIC_IMPORT_SHARE * OpenEconomy::GOODS_SHARE_OF_IMPORTS * $duty;
        $retaliatedExports = ((OpenEconomy::DISTRICT_EXPORT_SHARE * OpenEconomy::GOODS_SHARE_OF_EXPORTS) - OpenEconomy::DISTRICT_DEFENSE_EXPORT_SHARE) * (1.0 - OpenEconomy::FOREIGN_VALUE_ADDED_SHARE_OF_EXPORTS);
        $retaliation = OpenEconomy::EXPORT_PRICE_ELASTICITY * $retaliatedExports * log(1.0 + (MacroConstants::TARIFF_RETALIATION_RATIO * 0.10));

        $netExports = OpenEconomy::netExportGapAt(0.0, 0.0, 0.0, $duty);

        $this->assertEqualsWithDelta($switching - $retaliation, $netExports, 1e-15);
        $this->assertGreaterThan(0.0, $netExports, 'Retaliation takes back part of what the duty switches home,');
        $this->assertLessThan($switching, $netExports, 'but only part (Furceri et al. 2018: little change in the trade balance).');
    }

    public function testATariffReachesTradeVolumesOverTheTradeLag(): void
    {
        $state = new MacroState();
        $state->importTariffRate = 0.10;
        $dt = 0.01;

        $this->subsystem->calculateOutputGap($state, $state->yield5y, $state->naturalRate, MacroConstants::TARGET_INFLATION, $dt, 1.0);

        $this->assertEqualsWithDelta(log(1.10) * (1.0 - exp(-$dt / OpenEconomy::TRADE_VOLUME_ADJUSTMENT_YEARS)), $state->tariffTradeLag, 1e-12);
    }

    public function testTheNaturalRateRisesWithLabourForceGrowthAtHlwsC(): void
    {
        $state = new MacroState();
        $state->naturalRate = MacroConstants::BASE_NATURAL_RATE;
        $state->laborForceGrowthRate = MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE + 0.01;
        $dt = 0.1;

        $this->subsystem->calculateNaturalRate($state, MacroConstants::TFP_DRIFT, $dt);

        $expectedStep = (1.0 - exp(-ProductivityGrowth::NATURAL_RATE_ADJUSTMENT_SPEED * $dt)) * ProductivityGrowth::NATURAL_RATE_GROWTH_LOADING * 0.01;
        $this->assertEqualsWithDelta(MacroConstants::BASE_NATURAL_RATE + $expectedStep, $state->naturalRate, 1e-15);
    }

    public function testPotentialAndPopulationGrowWithTheLabourForce(): void
    {
        $state = new MacroState();
        $state->potentialGdpIndex = 1.0;
        $state->laborForceGrowthRate = MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE + 0.004;

        $this->subsystem->calculatePotentialAndNominalGdp($state, 0.25, MacroConstants::TFP_DRIFT);

        $this->assertEqualsWithDelta(exp((MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE + 0.004 + MacroConstants::TFP_DRIFT) * 0.25), $state->potentialGdpIndex, 1e-15);
        $this->assertEqualsWithDelta(0.004 * 0.25, $state->immigrationPopulationShift, 1e-15, 'The regime adds its excess inflow to the population.');
    }

    /**
     * The window a probe has open; any tick on an enabled probe opens one.
     *
     * @return array<string, mixed>
     */
    private function openWindow(OutputGapProbe $probe): array
    {
        $window = $probe->snapshot()['current'];
        $this->assertNotNull($window, 'A recorded tick opens a window.');

        return $window;
    }

    private function probedSubsystem(OutputGapProbe $probe, float $normal): MacroAggregateSubsystem
    {
        // The demand-disaster gate stays shut so the tick is deterministic.
        return MacroAggregateSubsystem::headless(new ScriptedDraws(generateStandardNormal: $normal), $probe);
    }

    private function probedState(): MacroState
    {
        $state = new MacroState();
        $state->outputGap = -0.02;
        $state->inflation = MacroConstants::TARGET_INFLATION;
        $state->policyRate = MacroConstants::BASE_NATURAL_RATE + MacroConstants::TARGET_INFLATION;
        $state->demandShock = 0.004;

        return $state;
    }

    /**
     * The founding draw is in the baseline; the part a budget draws above the founding share is new spending, and
     * reaches demand as the stabilisation does.
     */
    public function testTheDrawAboveTheFoundingShareReachesDemandAsPurchasesDo(): void
    {
        $dt = 1.0 / 3600.0;
        $contribution = function (float $share) use ($dt): float {
            $probe = new OutputGapProbe();
            $probe->enable();
            $state = $this->probedState();
            $state->sovereignFundDrawToGdp = 0.012;
            $state->sovereignFundDrawShare = $share;
            $this->probedSubsystem($probe, 0.0)->calculateOutputGap($state, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);

            return $this->openWindow($probe)['contributions']['fundDraw'] / $dt;
        };

        $this->assertSame(0.0, $contribution(MacroConstants::RESERVE_DRAW_CEILING), 'The founding draw adds nothing to the baseline it is in.');
        $this->assertEqualsWithDelta(
            OutputGap::DOMESTIC_GAP_WEIGHT * DomesticDemand::KALDOR_GOVT_SPENDING_MULTIPLIER * (0.012 * 0.1 / 0.6) / MacroConstants::PUBLIC_PURCHASES_TO_GDP,
            $contribution(0.6),
            1e-15,
            'A sixth of a draw at 60% is the spending above the founding half.',
        );
    }

    /**
     * The decomposition has to add up to the move it claims to explain.
     *
     * The probe is a second reading of a sum the subsystem also computes, and a second reading is worth
     * nothing unless it reconciles: a channel added to the drift and not to the decomposition would
     * otherwise show up as a panel that quietly under-reports. `unexplained` is what catches that, so it is
     * asserted at zero rather than merely carried.
     */
    public function testTheProbeAccountsForEveryChannelThatMovedTheGap(): void
    {
        $probe = new OutputGapProbe();
        $probe->enable();
        $subsystem = $this->probedSubsystem($probe, 0.8);
        $state = $this->probedState();

        $dt = 1.0 / 3600.0;
        $opening = $state->outputGap;
        for ($tick = 0; $tick < 900; ++$tick) {
            $state->outputGap = $subsystem->calculateOutputGap($state, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        }

        $window = $probe->snapshot()['current'];
        $this->assertNotNull($window);

        $this->assertSame(900, $window['ticks']);
        $this->assertEqualsWithDelta(0.25, $window['years'], 1e-12, 'Nine hundred production ticks are one simulation quarter');
        $this->assertEqualsWithDelta($opening, $window['opening_gap'], 1e-15);
        $this->assertEqualsWithDelta($state->outputGap, $window['closing_gap'], 1e-15);

        $this->assertEqualsWithDelta(0.0, $window['unexplained'], 1e-12, 'A channel reaches the drift without reaching the decomposition');

        $this->assertEqualsWithDelta(
            $window['change'],
            $window['drift'] + $window['diffusion'] + $window['clamp'] + $window['unexplained'],
            1e-12,
            'The decomposition must reconstruct the gap it decomposes',
        );

        // Every channel in the drift is named, so the panel cannot silently drop one.
        $contributions = $window['contributions'];
        $this->assertIsArray($contributions);
        $this->assertCount(28, $contributions);
        $this->assertArrayHasKey('productivitySupply', $contributions);
        $this->assertArrayHasKey('fundDraw', $contributions);
        $this->assertArrayHasKey('creditPremiumDrag', $contributions);
        $this->assertArrayHasKey('demandDisaster', $contributions);
        $this->assertArrayHasKey('disasterCompensator', $contributions);
        $this->assertArrayHasKey('monetaryDrag', $contributions);
        $this->assertArrayHasKey('automaticStabiliser', $contributions);
        $this->assertEqualsWithDelta(array_sum($contributions), $window['drift'], 1e-15);
    }

    /**
     * The disturbance is linear, so its noise, its disasters and their compensator are three processes summing to
     * it exactly: the split attributes the level without changing it, and a disaster decays as the whole does.
     */
    public function testTheDisturbanceSplitsIntoNoiseDisastersAndCompensationThatSumToIt(): void
    {
        $probe = new OutputGapProbe();
        $probe->enable();
        // Exactly one disaster, on the first tick: down (a uniform above the up share), of size 0.04.
        $math = new ScriptedDraws(generateStandardNormal: 0.3, checkProbability: [true], generateUniform: 0.99, generateExponential: 0.04);
        $subsystem = MacroAggregateSubsystem::headless($math, $probe);
        $state = $this->probedState();
        $dt = 1.0 / 360.0;

        for ($tick = 0; $tick < 90; ++$tick) {
            $state->outputGap = $subsystem->calculateOutputGap($state, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        }

        $decay = 1.0 - (OutputGap::DEMAND_SHOCK_REVERSION * $dt);
        $this->assertEqualsWithDelta(-0.04 * $decay ** 89, $state->demandDisasterShock, 1e-12);

        // What is left is the Gaussian disturbance alone, as if no disaster or compensator had ever touched it.
        $noise = 0.004;
        for ($tick = 0; $tick < 90; ++$tick) {
            $noise = ($noise * $decay) + (OutputGap::DEMAND_SHOCK_SIGMA * sqrt($dt) * 0.3);
        }
        $this->assertEqualsWithDelta($noise, $state->demandShock - $state->demandDisasterShock + $state->demandDisasterCompensation, 1e-12);
        $this->assertLessThan(0.0, $this->openWindow($probe)['contributions']['demandDisaster']);
    }

    // --- Productivity Shocks ---

    /**
     * A crisis scar is permanent and potential takes it whole, over its absorption time; each tick's part lowers
     * potential growth and books the same amount into the gap as less slack, so output itself does not jump.
     */
    public function testACrisisScarLowersPotentialForGoodAndReadsAsLessSlack(): void
    {
        $state = new MacroState();
        $state->crisisScarPending = -0.03;
        $dt = 1.0 / 48.0;
        $absorbed = 0.0;
        for ($tick = 0; $tick < 40 * 48; ++$tick) {
            $growth = $this->subsystem->absorbProductivityShocks($state, MacroConstants::TFP_DRIFT, $dt);
            $this->assertEqualsWithDelta(MacroConstants::TFP_DRIFT + ($state->crisisScarStep / $dt), $growth, 1e-12);
            $absorbed += $state->crisisScarStep;
        }
        $this->assertEqualsWithDelta(-0.03, $absorbed, 1e-6, 'Potential takes the whole scar and keeps it.');

        $probe = new OutputGapProbe();
        $probe->enable();
        $scarred = $this->probedState();
        $scarred->crisisScarPending = -0.03;
        $this->subsystem->absorbProductivityShocks($scarred, MacroConstants::TFP_DRIFT, 0.25);
        $this->probedSubsystem($probe, 0.0)->calculateOutputGap($scarred, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
        $this->assertEqualsWithDelta(-$scarred->crisisScarStep, $this->openWindow($probe)['contributions']['crisisScar'], 1e-15, 'The part potential took is booked in the gap, at full weight.');
        $this->assertGreaterThan(0.0, $this->openWindow($probe)['contributions']['crisisScar']);
    }

    /**
     * The shock is a driftless random walk at the fitted volatility and is the whole of the index's departure
     * from its trend path: Fernald's utilization-adjusted TFP has no fat tails, so no jump rides on top. Checked
     * at two tick rates, because a sqrt(dt) innovation is exactly what a coarse clock gets wrong.
     */
    public function testTheProductivityShockIsADriftlessRandomWalk(): void
    {
        $years = 10;
        $expectedSd = ProductivityGrowth::TFP_VOLATILITY * sqrt($years);
        foreach ([36, 144] as $ticksPerYear) {
            $ends = [];
            for ($seed = 1; $seed <= 400; $seed++) {
                mt_srand($seed * 104729 + $ticksPerYear);
                $subsystem = MacroAggregateSubsystem::headless(new MathUtility());
                $state = new MacroState();
                $state->outputGapEma = 0.0;
                for ($tick = 0; $tick < $years * $ticksPerYear; $tick++) {
                    $subsystem->calculateTotalFactorProductivity($state, 1.0 / $ticksPerYear);
                }
                $ends[] = $state->tfpShockLevel;
                if ($seed === 1) {
                    $offTrend = log($state->totalFactorProductivityIndex / MacroConstants::TFP_BASELINE) - (MacroConstants::TFP_DRIFT * $years);
                    $this->assertEqualsWithDelta($offTrend, $state->tfpShockLevel, 1e-9, 'The index leaves its trend path by the shock level and nothing else.');
                }
            }

            $mean = array_sum($ends) / count($ends);
            $sd = sqrt(array_sum(array_map(static fn(float $v): float => ($v - $mean) ** 2, $ends)) / count($ends));
            $this->assertEqualsWithDelta(0.0, $mean, 3.0 * $expectedSd / sqrt(count($ends)), "At {$ticksPerYear} ticks/year the shock drifted.");
            $this->assertEqualsWithDelta($expectedSd, $sd, 0.10 * $expectedSd, "At {$ticksPerYear} ticks/year the shock spread at the wrong rate.");
        }
    }

    /**
     * Basu, Fernald & Kimball (2006): output and potential take up a technology gain through second-order Pascal
     * lags, output the faster, so output runs ahead of potential and the gap opens positive; the productivity
     * growth potential is built on carries exactly what potential absorbed.
     */
    public function testOutputAndPotentialAbsorbAShockAtTheirFittedSpeeds(): void
    {
        $state = new MacroState();
        $state->tfpShockLevel = 0.01;
        $dt = 1.0 / 360.0;
        $growth = 0.0;
        for ($tick = 0; $tick < 720; $tick++) {
            $growth = $this->subsystem->absorbProductivityShocks($state, MacroConstants::TFP_DRIFT, $dt);
        }

        $t = 2.0;
        $ky = ProductivityGrowth::TFP_OUTPUT_ABSORPTION_SPEED;
        $kp = ProductivityGrowth::TFP_POTENTIAL_ABSORPTION_SPEED;
        $this->assertEqualsWithDelta(0.01 * (1.0 - (1.0 + $ky * $t) * exp(-$ky * $t)), $state->tfpOutputStage2, 2e-5);
        $this->assertEqualsWithDelta(0.01 * (1.0 - (1.0 + $kp * $t) * exp(-$kp * $t)), $state->tfpPotentialAbsorbed, 2e-5);
        $this->assertGreaterThan($state->tfpPotentialAbsorbed, $state->tfpOutputStage2, 'Output catches up with the new technology before potential does.');
        $this->assertEqualsWithDelta(MacroConstants::TFP_DRIFT + ($kp * ($state->tfpPotentialStage1 - $state->tfpPotentialAbsorbed)), $growth, 1e-5);
    }

    /**
     * The supply part of the gap rides beside the demand equation, not inside it: two economies identical but
     * for an absorbed productivity gain stay exactly that gain apart, so momentum, stabilisers and policy drags
     * never answer a gap spending did not open. The probe books it as its own channel.
     */
    public function testTheSupplyGapRidesOutsideTheDemandEquation(): void
    {
        $probe = new OutputGapProbe();
        $probe->enable();
        $plain = $this->probedState();
        $shocked = $this->probedState();
        $shocked->tfpOutputStage1 = 0.004;
        $shocked->tfpOutputStage2 = 0.004;
        // Silent draws: a draw held at one value every tick walks the OU shocks far past their size and both runs onto
        // the ceiling, where the gaps no longer differ.
        $plainSubsystem = $this->probedSubsystem(new OutputGapProbe(), 0.0);
        $shockedSubsystem = $this->probedSubsystem($probe, 0.0);

        $dt = 1.0 / 3600.0;
        for ($tick = 0; $tick < 900; ++$tick) {
            $plain->outputGap = $plainSubsystem->calculateOutputGap($plain, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
            $shocked->outputGap = $shockedSubsystem->calculateOutputGap($shocked, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        }

        $this->assertEqualsWithDelta(0.004, $shocked->outputGap - $plain->outputGap, 1e-12);
        $this->assertEqualsWithDelta(0.004, $shocked->productivitySupplyGap, 1e-15);
        $window = $probe->snapshot()['current'];
        $this->assertNotNull($window);
        $this->assertEqualsWithDelta(0.004, $window['contributions']['productivitySupply'], 1e-12);
        $this->assertEqualsWithDelta(0.0, $window['unexplained'], 1e-12);
    }

    /**
     * The contribution is an integral, not a level: a channel's line is the percentage points of gap it
     * actually delivered over the window, which is the quantity a recession leg is decomposed in.
     */
    public function testAChannelsContributionIsItsRateIntegratedOverTheWindow(): void
    {
        $probe = new OutputGapProbe();
        $probe->enable();
        $subsystem = $this->probedSubsystem($probe, 0.0);

        $state = $this->probedState();
        $state->demandShock = 0.0;
        $state->creditCrisisDrag = 0.02;

        $dt = 1.0 / 3600.0;
        for ($tick = 0; $tick < 900; ++$tick) {
            $state->outputGap = $subsystem->calculateOutputGap($state, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        }

        $window = $this->openWindow($probe);

        // A 2pp/yr drag held for a quarter delivers its direct share of half a point of gap, and delivers it negative.
        $this->assertEqualsWithDelta(-OutputGap::DOMESTIC_GAP_WEIGHT * CreditFiscalSubsystem::crisisDirectDrag(0.02) * 0.25, $window['contributions']['crisisDeleveragingDrag'], 1e-12);
        $contributions = $window['contributions'];
        asort($contributions);
        $this->assertSame(
            'crisisDeleveragingDrag',
            array_key_first($contributions),
            'The crisis is the heaviest drag on a state built around it',
        );
    }

    /** A crisis costs output for good: with none running there is no crisis channel lifting demand to pay for it. */
    public function testACalmQuarterCarriesNoCrisisLift(): void
    {
        $probe = new OutputGapProbe();
        $probe->enable();
        $state = $this->probedState();
        $state->creditCrisisDrag = 0.0;
        $this->probedSubsystem($probe, 0.0)->calculateOutputGap($state, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
        $contributions = $this->openWindow($probe)['contributions'];

        $this->assertSame(0.0, $contributions['crisisDeleveragingDrag']);
        $this->assertArrayNotHasKey('crisisCompensator', $contributions);
    }

    /**
     * The bound is booked as itself. Deriving the clamp as whatever is left over would make it the bucket
     * every accounting error falls into, and `unexplained` would never fire.
     */
    public function testTheBoundIsBookedSeparatelyFromTheChannels(): void
    {
        $probe = new OutputGapProbe();
        $probe->enable();
        $subsystem = $this->probedSubsystem($probe, 0.0);

        $state = $this->probedState();
        $state->outputGap = OutputGap::OUTPUT_GAP_FLOOR;
        // Sales expected where they stand, so no surprise goes into stock and lifts output off the floor.
        $state->domesticDemandGapEma = OutputGap::domesticDemandGap($state);
        $state->creditCrisisDrag = 0.50;
        $state->demandShock = 0.0;

        $newGap = $subsystem->calculateOutputGap($state, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
        $window = $this->openWindow($probe);

        $this->assertSame(OutputGap::OUTPUT_GAP_FLOOR, $newGap, 'The floor holds');
        $this->assertGreaterThan(0.0, $window['clamp'], 'The floor gave back the gap the drift took past it');
        $this->assertEqualsWithDelta(0.0, $window['unexplained'], 1e-12);
        $this->assertEqualsWithDelta(
            $window['change'],
            $window['drift'] + $window['diffusion'] + $window['clamp'] + $window['unexplained'],
            1e-12,
        );
    }

    /**
     * Off unless something turns it on: the web process constructs the same subsystem and must not pay for
     * a decomposition nothing there reads.
     */
    public function testAProbeNobodyEnabledRecordsNothing(): void
    {
        $probe = new OutputGapProbe();
        $subsystem = $this->probedSubsystem($probe, 0.8);
        $state = $this->probedState();

        $subsystem->calculateOutputGap($state, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);

        $this->assertFalse($probe->isEnabled());
        $this->assertNull($probe->snapshot()['current']);
        $this->assertNull($probe->snapshot()['previous']);
    }

    /**
     * Rolling closes the quarter and opens an empty one, so a reading is "this quarter so far" against
     * "last quarter" rather than the run since the ticker started.
     */
    public function testRollingTheWindowKeepsTheClosedQuarterAndStartsTheNextEmpty(): void
    {
        $probe = new OutputGapProbe();
        $probe->enable();
        $subsystem = $this->probedSubsystem($probe, 0.0);
        $state = $this->probedState();

        $subsystem->calculateOutputGap($state, 0.03, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
        $closing = $this->openWindow($probe)['closing_gap'];

        $probe->rollWindow();
        $snapshot = $probe->snapshot();

        $this->assertNull($snapshot['current'], 'The new quarter has not seen a tick yet');
        $this->assertNotNull($snapshot['previous'], 'Rolling keeps the closed quarter');
        $this->assertSame(1, $snapshot['previous']['ticks']);
        $this->assertSame($closing, $snapshot['previous']['closing_gap']);
    }

    /**
     * The Mundell-Fleming term is a response to the currency MOVING, so a currency parked wherever this
     * economy's normal happens to be is not a net-export impulse. Read off the nominal 100 baseline instead,
     * a rectified safe-haven bid and a one-sided sovereign risk discount hold the index near 97.3 forever and
     * the term becomes a permanent 0.11 pp/yr of demand.
     */
    public function testACurrencyLevelTheTrendHasFollowedDoesNotMoveNetExports(): void
    {
        $atBaseline = $this->neutralBorrowingState();

        $weakButSettled = $this->neutralBorrowingState();
        $weakButSettled->exchangeRateIndexEma = 97.3;
        $weakButSettled->exchangeRateTrend = 97.3;

        $gapAtBaseline = $this->subsystem->calculateOutputGap($atBaseline, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);
        $gapWeakButSettled = $this->subsystem->calculateOutputGap($weakButSettled, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.25, 1.0);

        $this->assertEqualsWithDelta(
            $gapAtBaseline,
            $gapWeakButSettled,
            1e-12,
            'A currency sitting at its own trend is this economy\'s normal, not a devaluation.',
        );
    }

    /**
     * The elasticity the term exists for still has to bite when the currency moves away from that normal: a 10% real
     * depreciation below trend lifts net exports by the IMF's footnote-21 amount at the district's trade shares,
     * most of it inside the first year.
     */
    public function testACurrencyBelowItsTrendStillLiftsNetExports(): void
    {
        $settled = $this->neutralBorrowingState();
        $settled->exchangeRateIndexEma = 97.3;
        $settled->exchangeRateTrend = 97.3;

        $depreciated = $this->neutralBorrowingState();
        $depreciated->exchangeRateTrend = 97.3;
        $depreciated->exchangeRateIndexEma = 97.3 * 0.90;

        $dt = 0.01;
        for ($i = 0; $i < 100; $i++) {
            $settled->outputGap = $gapSettled = $this->subsystem->calculateOutputGap($settled, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
            $depreciated->outputGap = $gapDepreciated = $this->subsystem->calculateOutputGap($depreciated, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        }

        $priceResponse = (OpenEconomy::EXPORT_PRICE_PASS_THROUGH * OpenEconomy::EXPORT_PRICE_ELASTICITY * OpenEconomy::DISTRICT_VALUE_ADDED_EXPORT_SHARE)
            + (OpenEconomy::IMPORT_PRICE_PASS_THROUGH * OpenEconomy::IMPORT_PRICE_ELASTICITY * OpenEconomy::DISTRICT_DOMESTIC_IMPORT_SHARE);
        $longTerm = -$priceResponse * log(0.90);

        $this->assertSame(0.0, $settled->netExportGap, 'A currency sitting at its own trend moves no trade.');
        $this->assertEqualsWithDelta(0.87 * $longTerm, $depreciated->netExportGap, 0.002 * $longTerm, 'A year on, 87% of the long-term lift, as the IMF\'s one-year effects give.');
        $this->assertEqualsWithDelta($depreciated->netExportGap, $gapDepreciated - $gapSettled, 1e-9, 'and it is in the gap.');
        $this->assertLessThan(0.015, $longTerm, 'A 10% real depreciation is worth less than the 1.5% of GDP the IMF sample\'s 42% trade shares give, the District trading less of its output,');
        $this->assertGreaterThan(0.006, $longTerm, 'though still most of a point.');
    }

    /**
     * Market-based finance is counted with the domestic economy: national accounts do not measure fund managers' output
     * at the value of what they manage (BEA 2026: portfolio management volume follows the industry's employment), so a
     * bear market is no District recession on the day it happens. It reaches demand through household wealth, a year
     * on.
     */
    public function testABearMarketIsNoFinanceRecessionOnTheDay(): void
    {
        $calm = $this->neutralBorrowingState();
        $calm->equityWealthRatio = 1.0;
        $crash = $this->neutralBorrowingState();
        $crash->equityWealthRatio = 0.60;

        $gapCalm = $this->subsystem->calculateOutputGap($calm, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.01, 1.0);
        $gapCrash = $this->subsystem->calculateOutputGap($crash, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.01, 1.0);

        $this->assertSame(0.0, $crash->financeOutputGap, 'Credit at its trend: no finance cycle, whatever the market is worth.');
        $this->assertEqualsWithDelta($gapCalm, $gapCrash, 1e-12, 'The lost market value is not lost output.');
    }

    /** Bank output follows deflated loan balances: a credit boom is bank output, a bust takes it back. */
    public function testACreditBoomIsBankOutput(): void
    {
        $boom = $this->neutralBorrowingState();
        $boom->creditToGdpTrend = 1.0;
        $boom->creditToGdpGap = 0.10;
        $this->subsystem->calculateOutputGap($boom, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.01, 1.0);

        $this->assertEqualsWithDelta(
            OutputGap::FINANCE_GAP_WEIGHT * OutputGap::FINANCE_CREDIT_SHARE * log(1.10),
            $boom->financeOutputGap,
            1e-12,
        );
    }

    /** Deal-makers' output follows the world's deal flow against its trend: a deal boom is finance output. */
    public function testADealBoomIsFinanceOutput(): void
    {
        $boom = $this->neutralBorrowingState();
        $boom->dealActivityIndexTrend = 100.0;
        $boom->dealActivityIndexEma = 120.0;
        $this->subsystem->calculateOutputGap($boom, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, 0.01, 1.0);

        $this->assertEqualsWithDelta(
            OutputGap::FINANCE_GAP_WEIGHT * OutputGap::FINANCE_DEAL_SHARE * log(1.20),
            $boom->financeOutputGap,
            1e-12,
        );
    }

    /**
     * The finance weight is only the finance the District has beyond what the demand equation already holds: the two
     * weights split GDP.
     */
    public function testTheFinanceWeightIsTheShareBeyondTheDemandEquation(): void
    {
        $this->assertEqualsWithDelta(
            1.0,
            (OutputGap::DOMESTIC_GAP_WEIGHT / (1.0 - OutputGap::EXCESS_IMPORT_LEAKAGE))
                + (OutputGap::FINANCE_GAP_WEIGHT * (OutputGap::FINANCE_CREDIT_SHARE + OutputGap::FINANCE_DEAL_SHARE)),
            1e-15,
        );
        $this->assertGreaterThan(0.2, OutputGap::FINANCE_GAP_WEIGHT);
        $this->assertLessThan(OutputGap::DISTRICT_FINANCE_SHARE, OutputGap::FINANCE_GAP_WEIGHT);
    }

    /**
     * An open economy's demand leaks abroad: the District's imports take 1.4 times each move in domestic demand at
     * their share of GDP (IMF WEO 2015), and the demand equation's own slopes already leak at a 14.8% share, so only
     * the excess comes out.
     */
    public function testDomesticDemandLeaksTheDistrictsExcessImportsAbroad(): void
    {
        $this->assertEqualsWithDelta(
            OpenEconomy::IMPORT_DEMAND_ELASTICITY * (OpenEconomy::DISTRICT_DOMESTIC_IMPORT_SHARE - OutputGap::DEMAND_EQUATION_IMPORT_SHARE),
            OutputGap::EXCESS_IMPORT_LEAKAGE,
            1e-15,
        );
        $this->assertEqualsWithDelta(0.196, OpenEconomy::DISTRICT_DOMESTIC_IMPORT_SHARE, 0.001, 'Imports (27.5% of GDP) less the quarter of the exports that is passed-through imports.');
        $this->assertGreaterThan(0.0, OutputGap::EXCESS_IMPORT_LEAKAGE, 'A bit more than the equation\'s own share for home use leaks;');
        $this->assertLessThan(0.1, OutputGap::EXCESS_IMPORT_LEAKAGE, 'a large economy keeps most of every demand swing at home.');

        // A demand impulse reaches the gap net of the leak.
        $dt = 0.25;
        $calm = $this->neutralBorrowingState();
        $shocked = $this->neutralBorrowingState();
        $shocked->demandShock = 0.02;
        $delta = $this->subsystem->calculateOutputGap($shocked, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0)
            - $this->subsystem->calculateOutputGap($calm, 0.035, MacroConstants::BASE_NATURAL_RATE, MacroConstants::TARGET_INFLATION, $dt, 1.0);
        $this->assertLessThan(0.02 * $dt * (1.0 - OutputGap::EXCESS_IMPORT_LEAKAGE) + 1e-6, $delta, 'The impulse is net of the excess imports.');
    }

    /**
     * Import prices (IMF WEO October 2015, Ch. 3; Burstein, Neves & Rebelo 2003): an appreciation lowers the price
     * level by the imported share of the basket times the pass-through of all but the distribution margin, and the
     * currency's level at rest adds nothing to inflation.
     */
    public function testAnAppreciationLowersThePriceLevelThroughImports(): void
    {
        $atRest = $this->neutralBorrowingState();
        $appreciated = $this->neutralBorrowingState();
        $appreciated->exchangeRateIndexEma = 110.0;
        $appreciated->importPriceLevel = OpenEconomy::importPriceLevelTarget($atRest->exchangeRateIndexEma);

        $dt = 0.01;
        $levelGap = 0.0;
        for ($i = 0; $i < 3000; $i++) {
            $restInflation = $this->subsystem->calculateInflation($atRest, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, $dt);
            $levelGap += ($this->subsystem->calculateInflation($appreciated, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, $dt) - $restInflation) * $dt;
        }

        $importedShare = ConsumerPrices::IMPORTED_SHARE_OF_GOODS * (ConsumerPrices::INFLATION_WEIGHT_GOODS + ConsumerPrices::INFLATION_WEIGHT_COMMODITY);
        $expected = -$importedShare * (1.0 - ConsumerPrices::IMPORT_DISTRIBUTION_SHARE) * OpenEconomy::IMPORT_PRICE_PASS_THROUGH * log(1.10);
        $this->assertEqualsWithDelta($expected, $levelGap, 0.0005, 'A 10% appreciation takes about 0.8% off the price level.');
        $this->assertEqualsWithDelta(OpenEconomy::importPriceLevelTarget($appreciated->exchangeRateIndexEma), $appreciated->importPriceLevel, 1e-9, 'and import prices have settled.');
    }

    /**
     * The trend has to be slow enough that the currency's own swing survives it: a first-order lag this long
     * follows under a tenth of the ~12-year swing the rate differential and the risk discount drive.
     */
    public function testTheExchangeRateTrendLeavesTheCurrencySwingIntact(): void
    {
        $state = new MacroState();
        $state->exchangeRateIndex = 90.0;
        $state->exchangeRateIndexEma = 90.0;
        $state->exchangeRateTrend = 100.0;

        $this->subsystem->updateExponentialMovingAverages($state, 6.0);

        $followed = (100.0 - $state->exchangeRateTrend) / 10.0;

        $this->assertLessThan(
            0.30,
            $followed,
            'Over half a currency swing the trend must still read most of that swing as a deviation.',
        );
        $this->assertGreaterThan(
            0.0,
            $followed,
            'The trend must follow a genuinely new level eventually, or it is just another constant.',
        );
    }

    /**
     * A carbon price steps the price level up by electricity's CPI weight times the rise in the household bill, spread
     * over the energy lag: a step, not a lasting rate.
     */
    public function testACarbonPriceStepsThePriceLevelByElectricitysWeight(): void
    {
        $quiet = new ScriptedDraws();
        $subsystem = MacroAggregateSubsystem::headless($quiet);
        $free = new MacroState();
        $free->inflationEma = MacroConstants::TARGET_INFLATION;
        $free->wageGrowth = MacroConstants::TFP_DRIFT + MacroConstants::TARGET_INFLATION;
        $priced = clone $free;
        $priced->carbonPrice = 70.37;

        $dt = 0.01;
        $added = 0.0;
        $last = 0.0;
        for ($step = 0; $step < 1000; ++$step) {
            $last = $subsystem->calculateInflation($priced, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, $dt) - $subsystem->calculateInflation($free, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, $dt);
            $added += $last * $dt;
        }

        $step = ConsumerPrices::CPI_ELECTRICITY_WEIGHT * log(1.0 + (CommodityLogisticsSubsystem::carbonPowerPriceAdder(70.37) / ConsumerPrices::RESIDENTIAL_ELECTRICITY_PRICE));
        $this->assertEqualsWithDelta($step, $added, 1e-6, 'Ten years on, the price level is up by the step and no more.');
        $this->assertEqualsWithDelta(0.0, $last, 1e-9, 'Once passed through, inflation is back where it was.');
        $this->assertGreaterThan(0.002, $step);
        $this->assertLessThan(0.006, $step);
    }

    // --- Productivity, Noise, Supply Chains, Deflator ---

    /**
     * A technology gain is disinflationary (Gali 1999; Basu, Fernald & Kimball 2006). Ceteris paribus -- wages not yet
     * caught up, the supply part of the gap open by the gain -- inflation falls: unit labour cost drops, and the
     * output the gain lifts is not demand. Read on the whole gap and against trend productivity, it rose.
     */
    public function testAProductivityGainLowersInflationCeterisParibus(): void
    {
        $calm = $this->neutralBorrowingState();
        $calm->inflationEma = MacroConstants::TARGET_INFLATION;
        $calm->wageGrowth = MacroConstants::TFP_DRIFT + MacroConstants::TARGET_INFLATION;
        $gain = clone $calm;
        $gain->productivitySupplyGap = 0.01;
        $gain->outputGap = 0.01;
        $supplyOnly = clone $gain;

        $calmInflation = $this->subsystem->calculateInflation($calm, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, 0.25);
        $supplyInflation = $this->subsystem->calculateInflation($supplyOnly, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, 0.25);
        $gainInflation = $this->subsystem->calculateInflation($gain, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT + 0.01, 0.25);

        $this->assertEqualsWithDelta($calmInflation, $supplyInflation, 1e-15, 'The supply part of the gap pulls no demand inflation.');
        $this->assertLessThan($calmInflation, $gainInflation, 'Faster productivity at given wages lowers unit labour cost and inflation.');
        $this->assertLessThan($calm->supercoreInflation, $gain->supercoreInflation);
    }

    /**
     * Headline and producer prices are levels recomputed every tick, so white noise added to them scaled with sqrt(dt)
     * shrank with the tick rate (to nothing at 54,000 ticks a year). Neither draws noise of its own: their variance
     * comes from the shocked processes they read, at any tick length.
     */
    public function testInflationAndProducerPricesCarryNoTickRateNoise(): void
    {
        $high = MacroAggregateSubsystem::headless(new ScriptedDraws(generateStandardNormal: 3.0));
        $low = MacroAggregateSubsystem::headless(new ScriptedDraws(generateStandardNormal: -3.0));

        foreach ([0.25, 1.0 / 54000.0] as $dt) {
            $up = $this->neutralBorrowingState();
            $up->industrialMetalsIndex = 140.0;
            $up->industrialMetalsIndexTrend = 100.0;
            $up->wageGrowth = 0.05;
            $down = clone $up;

            $this->assertSame(
                $high->calculateInflation($up, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, $dt),
                $low->calculateInflation($down, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, $dt),
                "Headline inflation draws no noise at dt {$dt}.",
            );
            $high->calculateProducerPriceInflation($up, MacroConstants::TFP_DRIFT, $dt);
            $low->calculateProducerPriceInflation($down, MacroConstants::TFP_DRIFT, $dt);
            $this->assertSame($up->producerPriceInflation, $down->producerPriceInflation, "PPI draws no noise at dt {$dt}.");
        }
    }

    /**
     * Freight is a cost LEVEL of landed goods (Carriere-Swallow et al. 2023): freight that doubles lifts the core goods
     * price level once, by the headline effect over the goods weight, and freight that stays high -- parked at double
     * its baseline, its quarter average caught up -- adds no standing inflation.
     */
    public function testPersistentFreightAddsNoStandingCoreGoodsInflation(): void
    {
        $calm = $this->neutralBorrowingState();
        $calm->wageGrowth = MacroConstants::TFP_DRIFT + MacroConstants::TARGET_INFLATION;
        $calm->freightRateIndex = MacroConstants::FREIGHT_BASELINE;
        $calm->freightRateIndexEma = MacroConstants::FREIGHT_BASELINE;
        $parked = clone $calm;
        $parked->freightRateIndex = 2.0 * MacroConstants::FREIGHT_BASELINE;
        $parked->freightRateIndexEma = 2.0 * MacroConstants::FREIGHT_BASELINE;
        $building = clone $calm;
        $building->freightRateIndex = 2.0 * MacroConstants::FREIGHT_BASELINE;

        $dt = 0.01;
        $levelGap = 0.0;
        for ($step = 0; $step < 1500; ++$step) {
            $this->subsystem->calculateInflation($calm, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, $dt);
            $this->subsystem->calculateInflation($parked, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, $dt);
            $this->subsystem->calculateInflation($building, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, $dt);
            $this->assertSame($calm->coreGoodsInflation, $parked->coreGoodsInflation, 'Parked freight adds no core goods inflation.');
            $levelGap += ($building->coreGoodsInflation - $calm->coreGoodsInflation) * $dt;
            $building->freightRateIndexEma += (1.0 - exp(-$dt / MovingAverages::STANDARD_EMA_HORIZON_YEARS)) * ($building->freightRateIndex - $building->freightRateIndexEma);
        }

        $this->assertEqualsWithDelta(ConsumerPrices::FREIGHT_DOUBLING_HEADLINE_EFFECT / ConsumerPrices::INFLATION_WEIGHT_GOODS, $levelGap, 1e-4, 'A freight doubling lifts core goods prices by its headline effect over the goods weight, once.');
        $this->assertEqualsWithDelta(0.0, $building->coreGoodsInflation - $calm->coreGoodsInflation, 1e-4, 'and then inflation is back where it was.');
    }

    /**
     * The GDP deflator prices what the District makes: its services, the goods, fuel and food it makes itself, and the
     * local distribution margin on the ones it imports (Burstein, Neves & Rebelo 2003). The currency reaches headline
     * through the imported half but never the deflator; a world energy price reaches it through the fuel made at home,
     * and the carbon tax on the District's share of the basket.
     */
    public function testTheDeflatorPricesWhatTheDistrictMakes(): void
    {
        $this->assertEqualsWithDelta(0.865, ConsumerPrices::domesticDeflatorWeight(), 1e-12, 'Services 55%, half the 45% of goods made at home, 40% of the imported half.');

        $calm = $this->neutralBorrowingState();
        $calm->wageGrowth = MacroConstants::TFP_DRIFT + MacroConstants::TARGET_INFLATION;
        $appreciated = clone $calm;
        $appreciated->exchangeRateIndexEma = 110.0;
        $oilShock = clone $calm;
        $oilShock->energyPriceShock = 50.0;
        $taxed = clone $calm;
        $taxed->carbonPrice = 70.0;

        $calmHeadline = 0.0;
        for ($i = 0; $i < 8; $i++) {
            $calmHeadline = $this->subsystem->calculateInflation($calm, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, 0.25);
            $appreciatedHeadline = $this->subsystem->calculateInflation($appreciated, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, 0.25);
        }
        $this->assertGreaterThan(0.0005, abs($appreciatedHeadline - $calmHeadline), 'The currency moves headline through the imported half');
        $this->assertEqualsWithDelta($calm->domesticInflation, $appreciated->domesticInflation, 1e-15, 'but not the deflator, even while core goods carry it.');
        $this->assertNotEqualsWithDelta($calm->coreGoodsInflation, $appreciated->coreGoodsInflation, 1e-6);

        $reference = clone $oilShock;
        $reference->energyPriceShock = 0.0;
        $priorEnergy = $oilShock->energyCostPushLag;
        $this->subsystem->calculateInflation($reference, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, 0.25);
        $this->subsystem->calculateInflation($oilShock, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, 0.25);
        $energyPush = ($oilShock->energyCostPushLag - $priorEnergy) / 0.25;
        $this->assertGreaterThan(0.0, $energyPush);
        $this->assertEqualsWithDelta(
            (1.0 - ConsumerPrices::IMPORTED_SHARE_OF_GOODS) * $energyPush / ConsumerPrices::domesticDeflatorWeight(),
            $oilShock->domesticInflation - $reference->domesticInflation,
            1e-15,
            'The fuel made at home carries the world price into the deflator.',
        );

        $untaxed = clone $taxed;
        $untaxed->carbonPrice = 0.0;
        $priorCarbon = $taxed->electricityCarbonPriceLevel;
        $this->subsystem->calculateInflation($untaxed, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, 0.25);
        $this->subsystem->calculateInflation($taxed, MacroConstants::TARGET_INFLATION, MacroConstants::TFP_DRIFT, 0.25);
        $carbonPush = ConsumerPrices::CPI_ELECTRICITY_WEIGHT * ($taxed->electricityCarbonPriceLevel - $priorCarbon) / 0.25;
        $this->assertGreaterThan(0.0, $carbonPush);
        $this->assertEqualsWithDelta($carbonPush / ConsumerPrices::domesticDeflatorWeight(), $taxed->domesticInflation - $untaxed->domesticInflation, 1e-15);
    }

    /**
     * Firms price their wholesale input cost as the PPI against the inflation target, so an index whose cost shares
     * summed to 0.85 settled 0.3pp under it and handed every PPI-exposed firm a standing input-cost discount.
     */
    public function testProducerPricesRunAtTheTargetWhenEveryCostIsAtTrend(): void
    {
        $this->assertEqualsWithDelta(
            1.0,
            MacroConstants::PPI_METALS_WEIGHT + MacroConstants::PPI_ENERGY_WEIGHT + IndustryIndicators::PPI_AGRI_WEIGHT
                + IndustryIndicators::PPI_ULC_WEIGHT + IndustryIndicators::PPI_OTHER_COST_WEIGHT,
            1e-12,
        );

        $tfpGrowth = 0.012;
        $state = new MacroState();
        $state->industrialMetalsIndexTrend = $state->industrialMetalsIndex;
        $state->energyPriceIndexTrend = $state->energyPriceIndex;
        $state->agriculturalCommodityIndexTrend = $state->agriculturalCommodityIndex;
        $state->supplyChainPressureIndex = 0.0;
        $state->outputGap = 0.0;
        $state->wageGrowth = MacroConstants::TARGET_INFLATION + $tfpGrowth;
        $state->tipsBreakeven = MacroConstants::TARGET_INFLATION;

        $this->subsystem->calculateProducerPriceInflation($state, $tfpGrowth, 1.0 / 3600.0);

        $this->assertEqualsWithDelta(MacroConstants::TARGET_INFLATION, $state->producerPriceInflation, 1e-12);
    }

    /** The price level is recorded at each month end, and a year of record gives the year-on-year rate in logs. */
    public function testConsumerPriceInflationIsReadYearOnYearOffTheMonthEnds(): void
    {
        $state = new MacroState();
        $state->inflation = 0.03;
        $dt = 1.0 / 360.0;
        for ($tick = 0; $tick < 400; $tick++) {
            $state->totalTime += $dt;
            $this->subsystem->calculatePotentialAndNominalGdp($state, $dt, 0.0);
        }

        $this->assertCount(NationalAccounts::CPI_MONTHS_KEPT, $state->consumerPriceMonthly);
        $this->assertEqualsWithDelta(0.03, NationalAccounts::consumerPriceInflationYearOnYear($state->consumerPriceMonthly), 1e-9);
        $this->assertNull(NationalAccounts::consumerPriceInflationYearOnYear(array_slice($state->consumerPriceMonthly, 1)), 'Less than a year on record has no year-on-year rate.');
    }

    /**
     * Real GDP averaged over a quarter at the given tick rate, with the gap on a path that falls for two quarters and
     * then recovers: the statistics office's call at each quarter's end.
     *
     * @param callable(float): float $gapAt The output gap at a time.
     * @return list<bool>
     */
    private function recessionCalls(int $ticksPerYear, callable $gapAt, int $quarters): array
    {
        $state = new MacroState();
        $state->potentialGdpIndex = 1.0;
        $state->totalTime = 0.0;
        $dt = 1.0 / $ticksPerYear;
        $calls = [];
        for ($tick = 1; $tick <= $ticksPerYear * $quarters / 4; ++$tick) {
            $state->totalTime += $dt;
            $state->outputGap = $gapAt($state->totalTime);
            MacroAggregateSubsystem::recordQuarterlyRealGdp($state, $dt);
            if ($tick % ($ticksPerYear / 4) === 0) {
                $calls[] = $state->recessionDeclared;
            }
        }

        return $calls;
    }

    /**
     * Two quarters of falling real GDP declare a recession, the same quarters at any tick rate, and two of growth end
     * it.
     */
    public function testTwoFallingQuartersDeclareARecessionAndTwoRisingEndIt(): void
    {
        // Flat for two years, falling 1% a quarter for a year, then recovering 3% a quarter.
        $gapAt = static fn(float $t): float => $t < 2.0 ? 0.0 : ($t < 3.0 ? -0.04 * ($t - 2.0) : -0.04 + (0.12 * ($t - 3.0)));
        $expected = [false, false, false, false, false, false, false, false, false, true, true, true, true, false, false, false];
        foreach ([12, 48, 180] as $ticksPerYear) {
            $this->assertSame($expected, $this->recessionCalls($ticksPerYear, $gapAt, 16), "At {$ticksPerYear} ticks a year.");
        }
    }

    /**
     * A single falling quarter is no recession, and growth in a deep slump ends one: the call reads the change, not the
     * level.
     */
    public function testOneFallingQuarterIsNoRecession(): void
    {
        $dip = static fn(float $t): float => ($t > 1.05 && $t < 1.2) ? -0.02 : 0.0;
        $this->assertNotContains(true, $this->recessionCalls(48, $dip, 12));
    }

    /**
     * The default rates and commercial property are read against the levels they settle at: years at a new level make
     * it the trend.
     */
    public function testDefaultAndCommercialPropertyTrendsSettleOnTheLevelsTheyHold(): void
    {
        $state = new MacroState();
        $state->corporateDefaultRate = 0.012;
        $state->corporateDefaultRateEma = 0.012;
        $state->retailDefaultRate = 0.020;
        $state->retailDefaultRateEma = 0.020;
        $state->commercialPropertyIndex = 110.0;
        $state->commercialPropertyIndexEma = 110.0;

        // Four of the longer horizons.
        for ($i = 0; $i < 4 * 12; $i++) {
            $this->subsystem->updateExponentialMovingAverages($state, MovingAverages::DEFAULT_RATE_TREND_YEARS / 12.0);
        }

        $this->assertEqualsWithDelta(0.012, $state->corporateDefaultRateTrend, 0.0002);
        $this->assertEqualsWithDelta(0.020, $state->retailDefaultRateTrend, 0.0002);
        $this->assertEqualsWithDelta(110.0, $state->commercialPropertyIndexTrend, 0.1);
    }

    /**
     * The HY and interbank spreads' measured trends close on a sustained level over their decade, whatever the tick.
     */
    public function testTheHighYieldAndInterbankSpreadTrendsAreADecadesAverage(): void
    {
        $highYieldBase = MacroConstants::BASE_CREDIT_SPREAD * MacroConstants::HY_BASE_SPREAD_MULTIPLIER;
        $path = function (int $ticksPerYear) use ($highYieldBase): array {
            $state = new MacroState();
            $state->interbankLiquiditySpread = MacroConstants::INTERBANK_BASELINE_SPREAD + 0.01;
            $state->interbankLiquiditySpreadEma = MacroConstants::INTERBANK_BASELINE_SPREAD + 0.01;
            $state->interbankLiquiditySpreadTrend = MacroConstants::INTERBANK_BASELINE_SPREAD;
            $state->highYieldCreditSpread = $highYieldBase + 0.01;
            $state->highYieldCreditSpreadEma = $highYieldBase + 0.01;
            $state->highYieldCreditSpreadTrend = $highYieldBase;
            for ($tick = 0; $tick < 10 * $ticksPerYear; ++$tick) {
                $this->subsystem->updateExponentialMovingAverages($state, 1.0 / $ticksPerYear);
            }

            return [$state->interbankLiquiditySpreadTrend, $state->highYieldCreditSpreadTrend];
        };

        $closed = 0.01 * (1.0 - exp(-10.0 / MovingAverages::SPREAD_TREND_YEARS));
        $this->assertEqualsWithDelta([MacroConstants::INTERBANK_BASELINE_SPREAD + $closed, $highYieldBase + $closed], $path(12), 1e-12);
        $this->assertEqualsWithDelta($path(12), $path(252), 1e-12);
    }
}
