<?php

namespace App\Tests\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Recorder\OutputGapProbe;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\SemiconductorBusinessModel;
use PHPUnit\Framework\TestCase;

class MacroAggregateSubsystemTest extends TestCase
{
    private MathUtility $mathUtility;
    private MacroAggregateSubsystem $subsystem;

    protected function setUp(): void
    {
        $this->mathUtility = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }

            // The rare demand disaster is the only probabilistic gate in this subsystem. Held shut so a
            // neutral tick stays neutral; its compensator still rides on every tick and cancels in the
            // state-vs-state differences these tests assert on.
            public function checkProbability(float $probability): bool
            {
                return false;
            }
        };
        $this->subsystem = new MacroAggregateSubsystem($this->mathUtility);
    }

    public function testTfpAndNaturalRateEvolveContinuously(): void
    {
        $state = new MacroState();
        $state->totalFactorProductivityIndex = 100.0;
        $state->naturalRate = MacroEngine::BASE_NATURAL_RATE;
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
        $state->naturalRate = MacroEngine::BASE_NATURAL_RATE;
        $state->outputGap = 0.03;
        $state->outputGapEma = 0.03;

        $this->subsystem->calculateNaturalRate($state, MacroEngine::TFP_DRIFT, 0.25);

        $this->assertSame(MacroEngine::BASE_NATURAL_RATE, $state->naturalRate);
    }

    /** r* moves c points per point of trend growth (HLW c = 1.113), approached at the adjustment speed. */
    public function testTheNaturalRateLoadsOnTrendGrowthAtHlwsC(): void
    {
        $state = new MacroState();
        $state->naturalRate = MacroEngine::BASE_NATURAL_RATE;
        $dt = 0.1;

        $this->subsystem->calculateNaturalRate($state, MacroEngine::TFP_DRIFT + 0.01, $dt);

        $expectedStep = MacroAggregateSubsystem::NATURAL_RATE_ADJUSTMENT_SPEED * MacroAggregateSubsystem::NATURAL_RATE_GROWTH_LOADING * 0.01 * $dt;
        $this->assertEqualsWithDelta(MacroEngine::BASE_NATURAL_RATE + $expectedStep, $state->naturalRate, 1e-15);
    }

    public function testOutputGapAndInflationRespondToShocks(): void
    {
        $state = new MacroState();
        $state->outputGap = 0.0;
        $state->inflation = 0.02;
        $state->policyRate = 0.02;
        $state->wageGrowth = 0.035;

        $newGap = $this->subsystem->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $newInflation = $this->subsystem->calculateInflation($state, MacroEngine::TARGET_INFLATION, 1.0, 0.25);

        $this->assertGreaterThan(-0.12, $newGap);
        $this->assertLessThan(0.10, $newGap);
        $this->assertGreaterThan(-0.02, $newInflation);
    }

    public function testConvexPhillipsCurveAcceleratesNearCapacity(): void
    {
        $expansionPressureLow = $this->mathUtility->calculateConvexPhillipsCurve(0.02, MacroAggregateSubsystem::PHILLIPS_MAX_CAPACITY, MacroAggregateSubsystem::PHILLIPS_CONVEX_KAPPA, MacroAggregateSubsystem::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR);
        $expansionPressureHigh = $this->mathUtility->calculateConvexPhillipsCurve(0.06, MacroAggregateSubsystem::PHILLIPS_MAX_CAPACITY, MacroAggregateSubsystem::PHILLIPS_CONVEX_KAPPA, MacroAggregateSubsystem::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR);

        // Near capacity (y=0.06), pressure must be more than 3x higher than at y=0.02 due to non-linear convexity
        $this->assertGreaterThan(3.0 * $expansionPressureLow, $expansionPressureHigh);

        // During contractions (y=-0.04), downward nominal rigidity flattens deflation pressure
        $recessionPressure = $this->mathUtility->calculateConvexPhillipsCurve(-0.04, MacroAggregateSubsystem::PHILLIPS_MAX_CAPACITY, MacroAggregateSubsystem::PHILLIPS_CONVEX_KAPPA, MacroAggregateSubsystem::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR);
        $this->assertLessThan(0.0, $recessionPressure);
        $this->assertGreaterThan(-0.01, $recessionPressure, 'Downward rigidity must prevent runaway deflationary pressure');
    }

    public function testSectoralInflationDisaggregation(): void
    {
        $state = new MacroState();
        $state->outputGap = 0.03;
        $state->laborTightness = 1.60; // Hot labor market
        $state->wageGrowth = 0.055;    // Elevated wage growth
        $state->freightRateIndexEma = 150.0; // Supply chain friction
        $state->inflation = 0.02;

        $newInflation = $this->subsystem->calculateInflation($state, MacroEngine::TARGET_INFLATION, 1.0, 0.25);

        $this->assertGreaterThan(MacroEngine::TARGET_INFLATION, $state->supercoreInflation, 'Hot labor market must push supercore services inflation up');
        $this->assertGreaterThan(MacroEngine::TARGET_INFLATION, $state->coreGoodsInflation, 'Elevated freight must push core goods inflation up');
        $this->assertGreaterThan(MacroEngine::TARGET_INFLATION, $newInflation, 'Headline inflation must rise above target');
    }

    public function testMetzlerInventoryCycleDynamics(): void
    {
        $state = new MacroState();
        $state->outputGap = -0.02;
        $state->outputGapEma = 0.02; // Sharp deceleration from +2% to -2%
        $state->inventoryStockGap = 0.0;

        $updatedGap = $this->subsystem->calculateOutputGap($state, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);

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

        $this->subsystem->calculateInflation($stateWage, MacroEngine::TARGET_INFLATION, 1.0, 0.25);

        // Supercore Services must absorb wage-push inflation more heavily than Core Goods
        $this->assertGreaterThan($stateWage->coreGoodsInflation, $stateWage->supercoreInflation, 'Wage surge must drive supercore services higher than core goods');

        // 2. Supply chain shock scenario: neutral wage growth, high freight
        $stateSupply = new MacroState();
        $stateSupply->wageGrowth = 0.035; // Neutral wage growth
        $stateSupply->freightRateIndexEma = 200.0; // 100% freight surge
        $stateSupply->industrialMetalsIndexEma = 150.0;
        $stateSupply->inflation = 0.02;

        $this->subsystem->calculateInflation($stateSupply, MacroEngine::TARGET_INFLATION, 1.0, 0.25);

        // Core Goods must absorb supply chain frictions more heavily than Supercore Services
        $this->assertGreaterThan($stateSupply->supercoreInflation, $stateSupply->coreGoodsInflation, 'Supply chain bottleneck must drive core goods higher than supercore services');
    }

    public function testCalculateCapacityUtilization(): void
    {
        $stateNormal = new MacroState();
        $stateNormal->outputGap = 0.0;
        $stateNormal->capitalStockOverhang = 0.0;

        $this->subsystem->calculateCapacityUtilization($stateNormal);
        $this->assertEqualsWithDelta(MacroEngine::CU_BASELINE, $stateNormal->capacityUtilizationRate, 0.0001);

        $stateBoom = new MacroState();
        $stateBoom->outputGap = 0.03;
        $stateBoom->capitalStockOverhang = 0.0;

        $this->subsystem->calculateCapacityUtilization($stateBoom);
        $this->assertGreaterThan(MacroEngine::CU_BASELINE, $stateBoom->capacityUtilizationRate);

        $stateOverhang = new MacroState();
        $stateOverhang->outputGap = 0.0;
        $stateOverhang->capitalStockOverhang = 0.15;

        $this->subsystem->calculateCapacityUtilization($stateOverhang);
        $this->assertLessThan(MacroEngine::CU_BASELINE, $stateOverhang->capacityUtilizationRate);
    }

    /**
     * The subsystem publishes utilization as a fraction and the sector models read it through
     * MathUtility::calculateCapacityUtilizationShift(). Both ends must agree on that scale.
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

        $gap = $boom->capacityUtilizationRate - MacroEngine::CU_BASELINE;
        $this->assertEqualsWithDelta(
            $gap,
            MathUtility::calculateCapacityUtilizationShift($boom->capacityUtilizationRate, MacroEngine::CU_BASELINE, 1.0),
            0.0001,
            'At unit sensitivity the shift is the utilization gap itself, in the units the subsystem publishes'
        );

        // A three-point gap is what the semiconductor fab-shortage gate is calibrated to, and a 3% output
        // gap has to clear it -- otherwise the gate is unreachable however hot the economy runs.
        $this->assertGreaterThan(
            SemiconductorBusinessModel::BOOM_CAPACITY_UTILIZATION_THRESHOLD,
            $gap,
            'A boom must be able to trip the fab shortage gate'
        );

        $glut = new MacroState();
        $glut->outputGap = -0.03;
        $glut->capitalStockOverhang = 0.15;
        $this->subsystem->calculateCapacityUtilization($glut);

        $this->assertLessThan(
            SemiconductorBusinessModel::GLUT_CAPACITY_UTILIZATION_THRESHOLD,
            $glut->capacityUtilizationRate - MacroEngine::CU_BASELINE,
            'A slump with idle plant must be able to trip the fab glut gate'
        );
    }

    public function testCommodityCostPushPassesThroughToHeadlineInflationWithoutAttenuation(): void
    {
        $stateNormal = new MacroState();
        $stateNormal->inflation = 0.02;
        $stateNormal->inflationEma = 0.02;
        $stateNormal->outputGap = 0.0;
        $stateNormal->wageGrowth = MacroEngine::TFP_DRIFT + MacroEngine::TARGET_INFLATION;
        $stateNormal->energyPriceShock = 0.0;
        $stateNormal->agriculturalCommodityIndex = 100.0;

        $stateEnergy = clone $stateNormal;
        $stateEnergy->energyPriceShock = 100.0; // 100% price surge (doubling)

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('calculateConvexPhillipsCurve')->willReturn(0.0);
        $mathMock->method('calculateDistributedLag')->willReturnCallback(
            fn(float $curr, float $target, float $dt, float $tau) => $target
        );

        $subsystemWithMock = new MacroAggregateSubsystem($mathMock);

        $infNormal = $subsystemWithMock->calculateInflation($stateNormal, MacroEngine::TARGET_INFLATION, 1.0, 0.25);
        $infEnergy = $subsystemWithMock->calculateInflation($stateEnergy, MacroEngine::TARGET_INFLATION, 1.0, 0.25);

        $expectedLift = MacroEngine::ENERGY_COST_PUSH_TRANSMISSION;
        $this->assertEqualsWithDelta($expectedLift, $infEnergy - $infNormal, 0.0001, 'Energy shock must transmit to headline inflation without being diluted by basket weight.');
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

        // Contraction: low capacity, deceleration, inventory overhang, bank credit tightening
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
        $this->assertLessThan(MacroEngine::MAX_PMI, $recovery->manufacturingPmi);

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
        $tfp = MacroEngine::TFP_DRIFT;

        $state = new MacroState();
        $state->industrialMetalsIndex = 140.0;
        $state->energyPriceIndex = 160.0;
        $state->agriculturalCommodityIndex = 120.0;
        $state->supplyChainPressureIndex = 2.0;
        $state->wageGrowth = 0.055;
        $state->outputGap = 0.02;

        $this->subsystem->calculateProducerPriceInflation($state, $tfp, $dt);
        $this->assertGreaterThan(MacroEngine::TARGET_INFLATION, $state->producerPriceInflation, 'Upstream commodity surges and supply frictions must drive PPI above CPI target');
    }

    public function testAHighTermPremiumEraTightensBusinessBorrowingAgainstAStructuralNeutral(): void
    {
        $baseline = new MacroState();
        $baseline->outputGap = 0.0;
        $baseline->outputGapEma = 0.0;
        $baseline->policyRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;
        $baseline->inflation = MacroEngine::TARGET_INFLATION;
        $scale5y = \App\Service\Math\MathUtility::calculateTermPremiumDurationScale(5.0, MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS);
        $neutral5y = $baseline->policyRate + MacroEngine::NS_BASE_TERM_PREMIUM * $scale5y;

        $highEra = clone $baseline;
        $highEra->termPremiumRegime = 0.020;
        $fiveYearInHighEra = $baseline->policyRate + 0.020 * $scale5y;

        $gapBaseline = $this->subsystem->calculateOutputGap($baseline, $neutral5y, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapHighEra = $this->subsystem->calculateOutputGap($highEra, $fiveYearInHighEra, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);

        $this->assertLessThan($gapBaseline, $gapHighEra, 'The neutral is structural: a premium era that lifts the five-year is a real tightening of business borrowing, which the Taylor rule long-rate offset, not the IS curve, is there to lean against.');
    }


    public function testGovernmentSpendingAboveBaselineLiftsOutputGapDrift(): void
    {
        $baseline = new MacroState();
        $baseline->outputGap = 0.0;
        $baseline->outputGapEma = 0.0;
        $baseline->governmentSpendingIndexEma = MacroEngine::GOVT_SPENDING_BASELINE;

        $stimulus = clone $baseline;
        $stimulus->governmentSpendingIndexEma = MacroEngine::GOVT_SPENDING_BASELINE * 1.10;

        $gapBaseline = $this->subsystem->calculateOutputGap($baseline, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapStimulus = $this->subsystem->calculateOutputGap($stimulus, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);

        // Domestic demand reaches GDP on its share; the finance the District lives on does not answer purchases.
        $expectedImpulse = MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::KALDOR_GOVT_SPENDING_MULTIPLIER * 0.10 * 0.25;
        $this->assertEqualsWithDelta(
            $expectedImpulse,
            $gapStimulus - $gapBaseline,
            1e-9,
            'A 10% public spending increase must add the calibrated demand impulse to the output gap drift.'
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

        $gapNeutral = $this->subsystem->calculateOutputGap($neutral, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapExcess = $this->subsystem->calculateOutputGap($excess, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapShortfall = $this->subsystem->calculateOutputGap($shortfall, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);

        $this->assertEqualsWithDelta(
            -MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::KALDOR_CAPITAL_DRAG * $overhang * 0.25,
            $gapExcess - $gapNeutral,
            1e-9,
            'Excess capacity must subtract its calibrated drag from the output gap drift.'
        );

        $this->assertEqualsWithDelta(
            MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::KALDOR_CAPITAL_REBOUND_DRAG * $overhang * 0.25,
            $gapShortfall - $gapNeutral,
            1e-9,
            'A capital shortfall must return demand at its own, slower rate.'
        );

        $this->assertLessThan(
            abs($gapExcess - $gapNeutral),
            abs($gapShortfall - $gapNeutral),
            'An equal and opposite shortfall must not hand back as much demand as the excess took away: that symmetry is the spring that pays a deep bust straight back as a bigger boom.'
        );
    }

    public function testFarmPriceCollapseLowersFoodCostPushBelowZero(): void
    {
        $state = new MacroState();
        $state->agriculturalCommodityIndex = MacroEngine::AGRI_BASELINE * 0.80;
        $state->agriCostPushLag = 0.0;

        for ($i = 0; $i < 8; $i++) {
            $this->subsystem->calculateInflation($state, MacroEngine::TARGET_INFLATION, 1.0, 0.25);
        }

        $this->assertLessThan(0.0, $state->agriCostPushLag, 'Falling farm prices must pass through as a food-CPI dividend, symmetric to a spike.');
        $this->assertEqualsWithDelta(
            -0.20 * MacroAggregateSubsystem::AGRI_COST_PUSH_TRANSMISSION,
            $state->agriCostPushLag,
            0.0005,
            'After two years the distributed lag must have converged to the full symmetric pass-through.'
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

        return $this->subsystem->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
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
            MacroAggregateSubsystem::KALDOR_WEALTH_EFFECT_ELASTICITY,
            MacroAggregateSubsystem::KALDOR_EQUITY_WEALTH_ELASTICITY,
            'The MPC out of financial wealth is the smaller one.'
        );
    }

    /**
     * The coefficients are MPCs on stocks, entered as drifts at the demand equation's own pull (Mian, Rao & Sufi 2013;
     * Chodorow-Reich, Nenov & Simsek 2021). Held, a wealth gain settles domestic demand where that pull, less the
     * restocking the inventory cycle's lean cyclical target sets off, balances it: the MPC on the stock, raised by the
     * restocking, before policy answers any of it. Measured in slack, below the capacity ceiling.
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
                $state->outputGap = $this->subsystem->calculateOutputGap($state, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.01, 1.0);
                // A held state holds no sales surprise: both averages sit on their series.
                $state->outputGapEma = $state->outputGap;
                $state->domesticDemandGapEma = MacroAggregateSubsystem::domesticDemandGap($state);
            }
            $this->assertLessThan(0.0, $state->outputGap, 'Measured below the capacity ceiling.');

            return $state->outputGap;
        };
        $base = $settle(0.0, 0.0);
        $restockingPull = MacroAggregateSubsystem::DEMAND_OWN_PULL
            - (MacroAggregateSubsystem::METZLER_INVENTORY_DRAG * MacroAggregateSubsystem::INVENTORY_CYCLICAL_DEMAND_SENSITIVITY);
        $amplification = MacroAggregateSubsystem::DEMAND_OWN_PULL / $restockingPull;

        $housingLevel = MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::HOUSING_WEALTH_MPC * MacroAggregateSubsystem::HOUSING_WEALTH_TO_GDP * 0.10;
        $this->assertEqualsWithDelta($amplification * $housingLevel, $settle(0.10, 0.0) - $base, 0.005 * $housingLevel, 'Ten percent on the houses is ~0.6% of GDP of spending, and restocking adds a quarter.');

        // Households own a fifth of the board; the rest of the world spends its gains at home.
        $equityLevel = MacroAggregateSubsystem::EQUITY_WEALTH_MPC * MacroAggregateSubsystem::DISTRICT_HOUSEHOLD_EQUITY_SHARE * SovereignFundSubsystem::MARKET_CAP_TO_GDP * 0.10;
        $this->assertEqualsWithDelta($amplification * $equityLevel, $settle(0.0, 0.10) - $base, 0.005 * $equityLevel, 'Ten percent on the board is ~0.1% of GDP of District spending.');
    }

    /**
     * The rate slopes were fitted on US moments with the engine's wealth and currency channels a fraction of their size,
     * so they absorbed the US routes through stocks, houses and the dollar. With those channels at their measured size
     * the absorbed part comes out, once: rebuilt at US scale, the explicit routes plus the slope give the fitted total.
     */
    public function testTheRateSlopesCarryOnlyWhatNoExplicitChannelDoes(): void
    {
        $pull = MacroAggregateSubsystem::DEMAND_OWN_PULL;
        $usWealthRoute = $pull * ((MacroAggregateSubsystem::US_STOCK_RESPONSE_TO_RATES * MacroAggregateSubsystem::EQUITY_WEALTH_MPC * MacroAggregateSubsystem::US_STOCK_WEALTH_TO_GDP)
            + (MacroAggregateSubsystem::US_HOUSE_PRICE_RESPONSE_TO_RATES * MacroAggregateSubsystem::HOUSING_WEALTH_MPC * MacroAggregateSubsystem::HOUSING_WEALTH_TO_GDP));
        $usDollarRoute = AssetMarketSubsystem::UIP_SENSITIVITY * $pull * ((MacroAggregateSubsystem::EXPORT_PRICE_PASS_THROUGH * MacroAggregateSubsystem::EXPORT_PRICE_ELASTICITY * MacroAggregateSubsystem::US_EXPORT_SHARE)
            + (MacroAggregateSubsystem::IMPORT_PRICE_PASS_THROUGH * MacroAggregateSubsystem::IMPORT_PRICE_ELASTICITY * MacroAggregateSubsystem::US_IMPORT_SHARE));
        $carriedWhenFitted = (MacroAggregateSubsystem::FIT_HOUSING_WEALTH_DRIFT * MacroAggregateSubsystem::US_HOUSE_PRICE_RESPONSE_TO_RATES)
            + (MacroAggregateSubsystem::FIT_EXCHANGE_RATE_DRIFT * AssetMarketSubsystem::UIP_SENSITIVITY);

        $this->assertEqualsWithDelta(1.6, MacroAggregateSubsystem::KALDOR_MONETARY_DRAG_RESTRICTIVE + $usWealthRoute + $usDollarRoute - $carriedWhenFitted, 1e-12, 'The fitted restrictive total, routed.');
        $this->assertEqualsWithDelta(0.9, MacroAggregateSubsystem::KALDOR_MONETARY_DRAG_ACCOMMODATIVE + $usWealthRoute + $usDollarRoute - $carriedWhenFitted, 1e-12, 'The fitted accommodative total, routed.');
        $this->assertGreaterThan(0.0, MacroAggregateSubsystem::KALDOR_MONETARY_DRAG_ACCOMMODATIVE, 'Easing still lifts demand directly.');
        $this->assertLessThan(MacroAggregateSubsystem::KALDOR_MONETARY_DRAG_RESTRICTIVE, MacroAggregateSubsystem::KALDOR_MONETARY_DRAG_ACCOMMODATIVE, 'and still pushes on a string.');
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
            $this->subsystem->updateExponentialMovingAverages($state, MacroAggregateSubsystem::EQUITY_WEALTH_TREND_HORIZON_YEARS / 12.0);
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
            'Once it is normal, a 60% richer board moves demand no more than an ordinary one.'
        );
        $this->assertNotEqualsWithDelta(
            $this->gapWithEquityAt(1.00),
            $this->gapWithEquityAt(1.60),
            1e-6,
            'While it is still news, it must move demand.'
        );
    }

    /** A house price index and the level households have got used to, with every other series held still. */
    private function gapWithHousingAt(float $index, float $trend): float
    {
        $state = $this->neutralBorrowingState();
        $state->residentialPropertyIndex = $index;
        $state->residentialPropertyIndexEma = $index;
        $state->residentialWealthTrend = $trend;

        return $this->subsystem->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
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
            'A 30% dearer housing stock must move demand while it is still news.'
        );

        $state = $this->neutralBorrowingState();
        $state->residentialPropertyIndex = 130.0;
        $state->residentialPropertyIndexEma = 130.0;

        // Four horizons of living with it.
        for ($i = 0; $i < 4 * 12; $i++) {
            $this->subsystem->updateExponentialMovingAverages($state, MacroAggregateSubsystem::RESIDENTIAL_WEALTH_TREND_HORIZON_YEARS / 12.0);
        }

        $this->assertEqualsWithDelta(130.0, $state->residentialWealthTrend, 1.0, 'The trend settles on the level it has been shown.');
        $this->assertEqualsWithDelta(
            $this->gapWithHousingAt(100.0, 100.0),
            $this->gapWithHousingAt(130.0, 130.0),
            1e-12,
            'Once it is normal, a 30% dearer housing stock moves demand no more than an ordinary one.'
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
            $this->subsystem->calculateOutputGap($unreported, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0),
            1e-12,
            'An unreported market must move demand exactly as much as a board at its trend: not at all.'
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
        $expectedGrowth = MacroEngine::TFP_DRIFT;

        foreach ([[3600, 4, 80], [252, 10, 300]] as [$ticksPerYear, $seeds, $years]) {
            $annualGrowth = [];
            for ($seed = 1; $seed <= $seeds; $seed++) {
                mt_srand($seed * 7919);
                $subsystem = new MacroAggregateSubsystem(new MathUtility());
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
                    / count($annualGrowth)
            );

            $this->assertEqualsWithDelta(
                $expectedGrowth,
                $mean,
                0.0035,
                sprintf(
                    'At %d ticks/year the index grew %.3f%%/yr, not the %.3f%% it is parameterized for.',
                    $ticksPerYear,
                    $mean * 100,
                    $expectedGrowth * 100
                )
            );

            $this->assertEqualsWithDelta(
                MacroAggregateSubsystem::TFP_VOLATILITY,
                $realizedVolatility,
                0.006,
                sprintf(
                    'At %d ticks/year the index realized %.4f volatility against a TFP_VOLATILITY of %.4f.',
                    $ticksPerYear,
                    $realizedVolatility,
                    MacroAggregateSubsystem::TFP_VOLATILITY
                )
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
        $draws = new class extends MathUtility {
            public int $calls = 0;
            public function generateStandardNormal(): float
            {
                // One unit innovation on the first tick, silence afterwards.
                return $this->calls++ === 0 ? 1.0 : 0.0;
            }

            // Hold the demand-disaster gate shut so the tick is deterministic.
            public function checkProbability(float $probability): bool
            {
                return false;
            }
        };
        $subsystem = new MacroAggregateSubsystem($draws);

        // The disaster jump's compensator rides on every tick regardless of the innovation, so measure the
        // impulse against a control run that takes the same ticks without it. The disturbance is linear, so
        // the difference between the two is the impulse alone and decays at the reversion speed.
        $control = new MacroAggregateSubsystem(new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }

            public function checkProbability(float $probability): bool
            {
                return false;
            }
        });

        $state = new MacroState();
        $state->outputGap = 0.0;
        $base = new MacroState();
        $base->outputGap = 0.0;
        $dt = 1.0 / 3600.0;

        $state->outputGap = $subsystem->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        $base->outputGap = $control->calculateOutputGap($base, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        $impulse = $state->demandShock - $base->demandShock;

        $this->assertGreaterThan(0.0, $impulse, 'The innovation must land on the disturbance.');
        $this->assertEqualsWithDelta(
            MacroAggregateSubsystem::DEMAND_SHOCK_SIGMA * sqrt($dt),
            $impulse,
            1e-9,
            'A unit innovation scales by sigma * sqrt(dt).'
        );

        // Half a reversion half-life later the disturbance must still be most of the way there.
        $halfLifeTicks = (int) round((log(2.0) / MacroAggregateSubsystem::DEMAND_SHOCK_REVERSION) * 3600.0);
        for ($i = 0; $i < $halfLifeTicks; $i++) {
            $state->outputGap = $subsystem->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
            $base->outputGap = $control->calculateOutputGap($base, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        }

        $this->assertEqualsWithDelta(
            0.5 * $impulse,
            $state->demandShock - $base->demandShock,
            0.02 * $impulse,
            'After one half-life the disturbance must retain half the impulse, not have been redrawn away.'
        );
    }

    /** The disturbance is a demand impulse in gap-drift units, so it moves the gap alongside the other Kaldor terms. */
    public function testDemandDisturbanceEntersTheGapAsADriftImpulse(): void
    {
        $neutral = new MacroState();
        $neutral->outputGap = 0.0;
        $neutral->inflation = MacroEngine::TARGET_INFLATION;
        $neutral->policyRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;

        $shocked = clone $neutral;
        $shocked->demandShock = 0.01;

        $dt = 0.25;
        $baseGap = $this->subsystem->calculateOutputGap($neutral, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        $shockedGap = $this->subsystem->calculateOutputGap($shocked, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);

        // The disturbance is stepped before the drift reads it, so the tick applies the already-decayed level.
        $applied = 0.01 * (1.0 - (MacroAggregateSubsystem::DEMAND_SHOCK_REVERSION * $dt));
        $this->assertEqualsWithDelta(MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * $applied * $dt, $shockedGap - $baseGap, 1e-9);
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

        $gapCalm = $this->subsystem->calculateOutputGap($calm, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapSpiked = $this->subsystem->calculateOutputGap($spiked, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);

        $this->assertEqualsWithDelta($gapCalm, $gapSpiked, 1e-12, 'Headline inflation has no place in the ex-ante real rate.');
    }

    /** A rise in short-horizon expectations lowers the real policy leg and lifts demand; the fixed-rate leg is unmoved. */
    public function testHigherExpectedInflationLowersTheRealPolicyLegOnly(): void
    {
        // Two fresh states: one call mutates the demand disturbance it is handed, so reusing a single
        // state would carry that mutation into the second leg instead of differencing cleanly against it.
        $anchored = $this->neutralBorrowingState();
        $unanchored = $this->neutralBorrowingState();

        $gapAnchored = $this->subsystem->calculateOutputGap($anchored, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapUnanchored = $this->subsystem->calculateOutputGap($unanchored, 0.035, MacroEngine::BASE_NATURAL_RATE, 0.03, 0.25, 1.0);

        // One percentage point of expected inflation on a 0.50 policy leg is 50bps of real easing, through the drag
        // coefficient over a quarter, of which a one-quarter step has passed w^2 through the two Pascal stages.
        $expectedLift = MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::KALDOR_MONETARY_DRAG_ACCOMMODATIVE * MacroAggregateSubsystem::BORROWING_POLICY_WEIGHT * 0.01 * $this->pascalPassThrough(0.25) * 0.25;
        $this->assertEqualsWithDelta($expectedLift, $gapUnanchored - $gapAnchored, 1e-9);
    }

    /**
     * The mirror of the case above on the restrictive side: from a stance already restrictive, lower expected inflation
     * raises the real policy leg further, and drags at the restrictive rate. (The neutral fixture's five-year sits a term
     * premium under its neutral, so the fixture itself is slightly accommodative; starting from it would cross the kink.)
     */
    public function testLowerExpectedInflationTightensAtTheRestrictiveRate(): void
    {
        $anchored = $this->neutralBorrowingState();
        $tightened = $this->neutralBorrowingState();

        $gapAnchored = $this->subsystem->calculateOutputGap($anchored, 0.035, MacroEngine::BASE_NATURAL_RATE, 0.01, 0.25, 1.0);
        $gapTightened = $this->subsystem->calculateOutputGap($tightened, 0.035, MacroEngine::BASE_NATURAL_RATE, 0.0, 0.25, 1.0);
        $this->assertGreaterThan(0.0, $anchored->monetaryStanceTransmitted, 'Both legs sit on the restrictive side.');

        $expectedDrag = MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::KALDOR_MONETARY_DRAG_RESTRICTIVE * MacroAggregateSubsystem::BORROWING_POLICY_WEIGHT * 0.01 * $this->pascalPassThrough(0.25) * 0.25;
        $this->assertEqualsWithDelta(-$expectedDrag, $gapTightened - $gapAnchored, 1e-9);
    }

    /**
     * Barnichon & Matthes (2018), Tenreyro & Thwaites (2016): tightening moves spending, easing pushes on a string.
     * An equal real-rate move either side of neutral cuts demand more than it lifts it.
     */
    public function testTighteningCutsDemandMoreThanAnEqualEasingLiftsIt(): void
    {
        $neutral = $this->neutralBorrowingState();
        $tight = $this->neutralBorrowingState();
        $easy = $this->neutralBorrowingState();

        $gapNeutral = $this->subsystem->calculateOutputGap($neutral, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapTight = $this->subsystem->calculateOutputGap($tight, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION - 0.01, 0.25, 1.0);
        $gapEasy = $this->subsystem->calculateOutputGap($easy, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION + 0.01, 0.25, 1.0);

        $this->assertLessThan($gapNeutral, $gapTight);
        $this->assertGreaterThan($gapNeutral, $gapEasy);
        $this->assertLessThan($gapNeutral - $gapTight, $gapEasy - $gapNeutral, 'Easing must lift demand by less than an equal tightening cuts it.');
    }

    /**
     * Gilchrist & Zakrajsek (2012) premium, Barnichon, Matthes & Ziegenbein (2022) asymmetry: an adverse premium drags at
     * the adverse rate, a favorable one lifts demand at the (much smaller) favorable rate.
     */
    public function testAnAdversePremiumCutsDemandMoreThanAnEqualFavorableOneLiftsIt(): void
    {
        $neutral = $this->neutralBorrowingState();
        $adverse = $this->neutralBorrowingState();
        $adverse->excessBondPremium = 0.01;
        $favorable = $this->neutralBorrowingState();
        $favorable->excessBondPremium = -0.01;

        $gapNeutral = $this->subsystem->calculateOutputGap($neutral, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapAdverse = $this->subsystem->calculateOutputGap($adverse, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapFavorable = $this->subsystem->calculateOutputGap($favorable, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);

        $this->assertEqualsWithDelta(-MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::KALDOR_EXCESS_BOND_PREMIUM_DRAG_ADVERSE * 0.01 * 0.25, $gapAdverse - $gapNeutral, 1e-12);
        $this->assertEqualsWithDelta(MacroAggregateSubsystem::KALDOR_EXCESS_BOND_PREMIUM_DRAG_FAVORABLE * 0.01 * 0.25, $gapFavorable - $gapNeutral, 1e-12);
        $this->assertLessThan($gapNeutral - $gapAdverse, $gapFavorable - $gapNeutral, 'Easy credit must lift demand by less than tight credit cuts it.');
    }

    /** The fixed-rate leg is the real five-year: the breakeven deflates it, so a higher breakeven at the same nominal yield is easier money. */
    public function testAHigherBreakevenAtTheSameNominalFiveYearIsEasierMoney(): void
    {
        $anchored = $this->neutralBorrowingState();
        $repriced = $this->neutralBorrowingState();
        $repriced->tipsBreakeven = 0.03;

        $gapAnchored = $this->subsystem->calculateOutputGap($anchored, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapRepriced = $this->subsystem->calculateOutputGap($repriced, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);

        $expectedLift = MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::KALDOR_MONETARY_DRAG_ACCOMMODATIVE * MacroAggregateSubsystem::BORROWING_YIELD5Y_WEIGHT * 0.01 * $this->pascalPassThrough(0.25) * 0.25;
        $this->assertEqualsWithDelta($expectedLift, $gapRepriced - $gapAnchored, 1e-9);
    }

    /**
     * A rate move reaches spending through a second-order Pascal lag (Solow 1960): nothing on the tick, 1 - 2/e of it
     * one lag constant later (a single stage would pass 1 - 1/e), 1 - 3/e^2 after two, and the same at any step size.
     */
    public function testARealRateStepReachesDemandThroughAPascalLag(): void
    {
        $tau = MacroAggregateSubsystem::MONETARY_TRANSMISSION_LAG_YEARS;
        foreach ([1.0 / 360.0, 4.0 / 360.0] as $dt) {
            $state = $this->neutralBorrowingState();
            $state->policyRate += 0.01;
            $this->subsystem->calculateOutputGap($state, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
            $stance = $state->monetaryStanceStage1 / (1.0 - exp(-$dt / $tau));
            $this->assertLessThan(0.01 * $stance, $state->monetaryStanceTransmitted, 'Nothing reaches demand on the tick.');

            $passed = [];
            for ($i = 1; $i < (int) round(2.0 * $tau / $dt); $i++) {
                $state->outputGap = 0.0;
                $this->subsystem->calculateOutputGap($state, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
                $passed[$i + 1] = $state->monetaryStanceTransmitted / $stance;
            }
            $this->assertEqualsWithDelta(1.0 - (2.0 / M_E), $passed[(int) round($tau / $dt)], 0.01, "One lag constant in at dt {$dt}.");
            $this->assertEqualsWithDelta(1.0 - (3.0 / M_E ** 2), end($passed), 0.01, "Two lag constants in at dt {$dt}.");
        }
    }

    /** Share of a stance step that has passed both Pascal stages after one step of $dt from rest: w^2. */
    private function pascalPassThrough(float $dt): float
    {
        return (1.0 - exp(-$dt / MacroAggregateSubsystem::MONETARY_TRANSMISSION_LAG_YEARS)) ** 2;
    }

    /** A state with every demand channel at its baseline, so only the borrowing-cost terms can move the gap. */
    private function neutralBorrowingState(): MacroState
    {
        $state = new MacroState();
        $state->outputGap = 0.0;
        $state->outputGapEma = 0.0;
        $state->inflation = MacroEngine::TARGET_INFLATION;
        $state->tipsBreakeven = MacroEngine::TARGET_INFLATION;
        $state->policyRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;
        $state->naturalRate = MacroEngine::BASE_NATURAL_RATE;
        $state->corporateTaxRate = MacroEngine::TARGET_CORPORATE_TAX_RATE;
        $state->macroCreditSpreadEma = MacroEngine::BASE_CREDIT_SPREAD;
        $state->interbankLiquiditySpreadEma = MacroEngine::INTERBANK_BASELINE_SPREAD;
        $state->governmentSpendingIndexEma = MacroEngine::GOVT_SPENDING_BASELINE;
        $state->residentialPropertyIndexEma = AssetMarketSubsystem::RESIDENTIAL_BASELINE;
        $state->exchangeRateIndexEma = AssetMarketSubsystem::EXCHANGE_RATE_BASELINE;
        $state->exchangeRateTrend = AssetMarketSubsystem::EXCHANGE_RATE_BASELINE;
        $state->energyPriceIndexEma = MacroEngine::ENERGY_BASELINE;
        $state->freightRateIndexEma = MacroEngine::FREIGHT_BASELINE;
        $state->capitalStockOverhang = 0.0;
        $state->inventoryStockGap = 0.0;
        $state->demandShock = 0.0;

        return $state;
    }

    // --- Ten-Year Breakeven ---

    /** Core on target and nothing passing through: the ten-year prices the target, where the US fit is anchored. */
    public function testTheBreakevenPricesTheTargetWhenCoreIsOnTargetAndNothingPassesThrough(): void
    {
        $this->assertEqualsWithDelta(MacroEngine::TARGET_INFLATION, $this->breakeven($this->onTargetInflationState()), 1e-15);
    }

    /** The ten-year moves by the fitted loadings: per point of core over target, and per point of headline over core. */
    public function testTheBreakevenMovesByItsLoadingsOnCoreAndOnHeadlineOverCore(): void
    {
        $base = $this->onTargetInflationState();
        $core = $this->onTargetInflationState();
        $core->supercoreInflationEma += 0.01;
        $core->coreGoodsInflationEma += 0.01;
        $commodity = $this->onTargetInflationState();
        $commodity->energyCostPushLag = 0.006;
        $commodity->agriCostPushLag = 0.004;

        $this->assertEqualsWithDelta(MacroAggregateSubsystem::BREAKEVEN_CORE_LOADING * 0.01, $this->breakeven($core) - $this->breakeven($base), 1e-15);
        $this->assertEqualsWithDelta(MacroAggregateSubsystem::BREAKEVEN_NONCORE_LOADING * 0.01, $this->breakeven($commodity) - $this->breakeven($base), 1e-15);
    }

    /**
     * A commodity spike is priced as passing. The pass-through adds itself to headline one for one, and the US ten-year
     * takes 0.14-0.20 of that across samples; the blend this replaced took two thirds, and an oil shock lifted the rule.
     */
    public function testAnEnergySpikeReachesTheTenYearOnlyAsFarAsItDoesInTheUs(): void
    {
        $spike = $this->onTargetInflationState();
        $spike->energyCostPushLag = 0.015;

        $share = ($this->breakeven($spike) - MacroEngine::TARGET_INFLATION) / $spike->energyCostPushLag;

        $this->assertGreaterThan(0.10, $share);
        $this->assertLessThan(0.20, $share);
    }

    /** The gap adds nothing once inflation is held (CBO gap 0.02, se 0.02), and neither headline noise nor equity panic is an expectation. */
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

    private function breakeven(MacroState $state): float
    {
        return $this->subsystem->calculateTipsBreakeven($state, MacroEngine::TARGET_INFLATION);
    }

    /** Core at target and no energy or food pass-through. */
    private function onTargetInflationState(): MacroState
    {
        $state = new MacroState();
        $state->supercoreInflationEma = MacroEngine::TARGET_INFLATION;
        $state->coreGoodsInflationEma = MacroEngine::TARGET_INFLATION;
        $state->energyCostPushLag = 0.0;
        $state->agriCostPushLag = 0.0;

        return $state;
    }

    /** Baker, Bloom & Davis (2016): spikes in policy uncertainty cost output (Bloom 2009 wait-and-see), but calm does not stimulate. */
    public function testPolicyUncertaintyDragsDemandInLogs(): void
    {
        $neutral = new MacroState();
        $doubled = new MacroState();
        $doubled->policyUncertaintyIndexEma = 2.0 * MacroEngine::EPU_BASELINE;
        $halved = new MacroState();
        $halved->policyUncertaintyIndexEma = 0.5 * MacroEngine::EPU_BASELINE;

        $dt = 0.25;
        $gapNeutral = $this->subsystem->calculateOutputGap($neutral, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        $gapDoubled = $this->subsystem->calculateOutputGap($doubled, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        $gapHalved = $this->subsystem->calculateOutputGap($halved, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);

        $expected = MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::KALDOR_EPU_DRAG * log(2.0) * $dt;
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
        $gapAverage = $this->subsystem->calculateOutputGap($average, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        $gapQuiet = $this->subsystem->calculateOutputGap($quiet, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        $gapStormy = $this->subsystem->calculateOutputGap($stormy, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);

        $this->assertEqualsWithDelta($gapAverage, $gapQuiet, 1e-12, 'A quiet season is not a boom.');
        $this->assertEqualsWithDelta(-MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::KALDOR_CATASTROPHE_DRAG * 2.0 * $dt, $gapStormy - $gapAverage, 1e-9);
    }


    /** IMF WEO October 2015, Ch. 3: exports move 2.3 times the mainland's demand, and they are a level part of the gap. */
    public function testAMainlandBoomLiftsTheGapThroughExportsAtOnce(): void
    {
        $home = new MacroState();
        $abroad = new MacroState();
        $abroad->foreignOutputGapEma = 0.03;

        $dt = 0.25;
        $gapHome = $this->subsystem->calculateOutputGap($home, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        $gapAbroad = $this->subsystem->calculateOutputGap($abroad, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);

        // The mainland's demand buys the civilian exports; the arms makers sell into allied budgets instead.
        $exports = (MacroAggregateSubsystem::DISTRICT_EXPORT_SHARE - MacroAggregateSubsystem::DISTRICT_DEFENSE_EXPORT_SHARE) * MacroAggregateSubsystem::EXPORT_DEMAND_ELASTICITY * 0.03;
        $this->assertEqualsWithDelta($exports, $abroad->netExportGap, 1e-12);
        $this->assertEqualsWithDelta($exports, $gapAbroad - $gapHome, 1e-9, 'The export level reaches the gap whole, not at a rate.');

        // The domestic-demand equation does not pull the export level back: held, it stays in the gap.
        $home->outputGap = $gapHome;
        $abroad->outputGap = $gapAbroad;
        for ($i = 0; $i < 40; $i++) {
            $home->outputGap = $gapHome = $this->subsystem->calculateOutputGap($home, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
            $abroad->outputGap = $gapAbroad = $this->subsystem->calculateOutputGap($abroad, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        }
        $this->assertEqualsWithDelta($exports, $gapAbroad - $gapHome, 1e-6);
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
        $gapHome = $this->subsystem->calculateOutputGap($home, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        $gapArmed = $this->subsystem->calculateOutputGap($armed, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);

        $delivered = MacroAggregateSubsystem::DISTRICT_DEFENSE_EXPORT_SHARE * MacroEngine::ALLIED_PROCUREMENT_ELASTICITY * log(1.5);
        $firstQuarter = 1.0 - exp(-$dt / MacroAggregateSubsystem::DEFENSE_DELIVERY_LAG_YEARS);
        $this->assertEqualsWithDelta($firstQuarter * $delivered, $armed->netExportGap, 1e-12, 'A quarter delivers the backlog\'s first slice, not the order.');
        $this->assertEqualsWithDelta($firstQuarter * $delivered, $gapArmed - $gapHome, 1e-9);

        $home->outputGap = $gapHome;
        $armed->outputGap = $gapArmed;
        for ($i = 0; $i < 60; $i++) {
            $home->outputGap = $gapHome = $this->subsystem->calculateOutputGap($home, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
            $armed->outputGap = $gapArmed = $this->subsystem->calculateOutputGap($armed, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        }
        $this->assertEqualsWithDelta($delivered, $armed->netExportGap, 1e-5, 'Held, the whole order book is delivered.');
        $this->assertEqualsWithDelta($delivered, $gapArmed - $gapHome, 1e-5, 'And it stays in the gap as a level.');
        $this->assertGreaterThan(0.005, $delivered, 'A half again allied budget is worth more than half a point of District GDP.');
    }

    /** The mainland's cycle does not buy arms, and an allied build-up does not buy the civilian exports. */
    public function testArmsExportsAnswerAlliedBudgetsNotTheMainlandCycle(): void
    {
        $mainlandBoom = MacroAggregateSubsystem::netExportGapAt(0.03, 0.0, 0.0);
        $this->assertEqualsWithDelta((MacroAggregateSubsystem::DISTRICT_EXPORT_SHARE - MacroAggregateSubsystem::DISTRICT_DEFENSE_EXPORT_SHARE) * MacroAggregateSubsystem::EXPORT_DEMAND_ELASTICITY * 0.03, $mainlandBoom, 1e-15);

        $buildUp = MacroAggregateSubsystem::netExportGapAt(0.0, 0.0, 0.2);
        $this->assertEqualsWithDelta(MacroAggregateSubsystem::DISTRICT_DEFENSE_EXPORT_SHARE * MacroEngine::ALLIED_PROCUREMENT_ELASTICITY * 0.2, $buildUp, 1e-15);
        $this->assertEqualsWithDelta($mainlandBoom + $buildUp, MacroAggregateSubsystem::netExportGapAt(0.03, 0.0, 0.2), 1e-15, 'The two export markets add.');
    }

    /** Drehmann, Juselius & Korinek (2018): borrowed income is spent as it is borrowed, and income repaid out of the stock is not spent. */
    public function testNewHouseholdBorrowingMovesDemandBothWays(): void
    {
        $neutral = new MacroState();
        $borrowing = new MacroState();
        $borrowing->householdNewBorrowing = 0.03;
        $repaying = new MacroState();
        $repaying->householdNewBorrowing = -0.03;

        $dt = 0.25;
        $gapNeutral = $this->subsystem->calculateOutputGap($neutral, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        $gapBorrowing = $this->subsystem->calculateOutputGap($borrowing, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        $gapRepaying = $this->subsystem->calculateOutputGap($repaying, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);

        $this->assertGreaterThan(0.0, MacroAggregateSubsystem::KALDOR_HOUSEHOLD_NEW_BORROWING);
        $this->assertEqualsWithDelta(MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::KALDOR_HOUSEHOLD_NEW_BORROWING * 0.03 * $dt, $gapBorrowing - $gapNeutral, 1e-9, 'Three points of income borrowed a year are spent.');
        $this->assertEqualsWithDelta(-MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::KALDOR_HOUSEHOLD_NEW_BORROWING * 0.03 * $dt, $gapRepaying - $gapNeutral, 1e-9, 'Paying the stock down is the same flow the other way.');
    }

    /** Drehmann, Juselius & Korinek (2018): service below its average leaves income to spend; above it, repayment drags. */
    public function testHouseholdDebtServiceMovesDemandBothWaysAroundItsAverage(): void
    {
        $neutral = new MacroState();
        $carried = new MacroState();
        $carried->householdDebtServiceGap = -0.02;
        $repaying = new MacroState();
        $repaying->householdDebtServiceGap = 0.02;

        $dt = 0.25;
        $gapNeutral = $this->subsystem->calculateOutputGap($neutral, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        $gapCarried = $this->subsystem->calculateOutputGap($carried, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        $gapRepaying = $this->subsystem->calculateOutputGap($repaying, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);

        $this->assertEqualsWithDelta(MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::KALDOR_HOUSEHOLD_DEBT_SERVICE * 0.02 * $dt, $gapCarried - $gapNeutral, 1e-9, 'Two points of service below average leave a point a year of demand.');
        $this->assertEqualsWithDelta(-MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::KALDOR_HOUSEHOLD_DEBT_SERVICE * 0.02 * $dt, $gapRepaying - $gapNeutral, 1e-9, 'Two points over it take a point a year off demand.');
    }

    public function testCrisisDeleveragingAndLendingStandardsDragDemandOneForOne(): void
    {
        $neutral = new MacroState();
        $deleveraging = new MacroState();
        $deleveraging->creditCrisisDrag = 0.02;
        $tightStandards = new MacroState();
        $tightStandards->sloosTighteningIndexEma = 0.80;
        $looseStandards = new MacroState();
        $looseStandards->sloosTighteningIndexEma = -0.20;

        $dt = 0.25;
        $run = fn(MacroState $state): float => $this->subsystem->calculateOutputGap($state, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        $gapNeutral = $run($neutral);

        $this->assertEqualsWithDelta(-MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * 0.02 * $dt, $run($deleveraging) - $gapNeutral, 1e-9, 'The crisis drag enters the drift at face value: two points a year off demand, on demand\'s share of GDP.');
        $this->assertEqualsWithDelta(-MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::KALDOR_LENDING_STANDARDS_DRAG * 0.80 * $dt, $run($tightStandards) - $gapNeutral, 1e-9, 'Standards at the 80% of 2008 are a quantity constraint no rate cut reaches.');
        $this->assertEqualsWithDelta(MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::KALDOR_LENDING_STANDARDS_DRAG * 0.20 * $dt, $run($looseStandards) - $gapNeutral, 1e-9, 'Easing standards lend into demand.');
    }

    /**
     * The aggregate demand disturbance and the output gap's own diffusion are separate innovations in
     * the estimated system (Smets & Wouters 2007); one draw serving both correlates them at unity.
     */
    public function testDemandDisturbanceAndGapDiffusionDrawSeparateInnovations(): void
    {
        $dt = 0.25;
        $math = new class extends MathUtility {
            /** @var list<float> */
            private array $draws = [];
            private int $calls = 0;

            public function script(float ...$draws): void
            {
                $this->draws = $draws;
                $this->calls = 0;
            }

            public function drawCount(): int
            {
                return $this->calls;
            }

            public function generateStandardNormal(): float
            {
                return $this->draws[$this->calls++] ?? 0.0;
            }

            // Hold the demand-disaster gate shut so the tick is deterministic.
            public function checkProbability(float $probability): bool
            {
                return false;
            }
        };
        $subsystem = new MacroAggregateSubsystem($math);

        $run = function (float $demandDraw, float $diffusionDraw) use ($subsystem, $math, $dt): array {
            $math->script($demandDraw, $diffusionDraw);
            $state = new MacroState();
            $state->outputGap = 0.0;
            $state->inflation = 0.02;
            $state->policyRate = 0.02;
            $state->wageGrowth = 0.035;
            $gap = $subsystem->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);

            return [$gap, $state->demandShock, $math->drawCount()];
        };

        [$neutralGap, $neutralShock, $calls] = $run(0.0, 0.0);
        // A shared draw fails here first: it consumes one innovation, not two.
        $this->assertSame(2, $calls, 'One innovation for the demand disturbance, one for the gap diffusion');
        // With both innovations silent the disturbance still carries the disaster jump's compensator, which
        // is a deterministic function of dt and is therefore the baseline the other two runs are read against.

        // Second draw only: the gap moves by the full diffusion, the disturbance does not move at all.
        [$diffusionGap, $diffusionShock] = $run(0.0, 1.0);
        $this->assertEqualsWithDelta($neutralShock, $diffusionShock, 1e-15, 'A diffusion innovation must not enter the demand disturbance');
        $this->assertEqualsWithDelta(
            MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::OUTPUT_GAP_DIFFUSION_SIGMA * sqrt($dt),
            $diffusionGap - $neutralGap,
            1e-12
        );

        // First draw only: the disturbance takes it whole and reaches the gap only through the drift.
        [$demandGap, $demandShock] = $run(1.0, 0.0);
        $expectedShock = MacroAggregateSubsystem::DEMAND_SHOCK_SIGMA * sqrt($dt);
        $this->assertEqualsWithDelta($expectedShock, $demandShock - $neutralShock, 1e-15);
        $this->assertEqualsWithDelta(MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * $expectedShock * $dt, $demandGap - $neutralGap, 1e-12);
    }

    /**
     * A subsystem wired to a live probe, with a diffusion draw that is not zero.
     *
     * The class fixture returns 0.0 from every normal, which is what most of these tests want and is
     * exactly wrong here: it would leave the diffusion and clamp legs of the accounting untested.
     */
    private function probedSubsystem(OutputGapProbe $probe, float $normal): MacroAggregateSubsystem
    {
        $math = new class($normal) extends MathUtility {
            public function __construct(private readonly float $normal) {}

            public function generateStandardNormal(): float
            {
                return $this->normal;
            }

            // Hold the demand-disaster gate shut so the tick is deterministic.
            public function checkProbability(float $probability): bool
            {
                return false;
            }
        };

        return new MacroAggregateSubsystem($math, $probe);
    }

    private function probedState(): MacroState
    {
        $state = new MacroState();
        $state->outputGap = -0.02;
        $state->inflation = MacroEngine::TARGET_INFLATION;
        $state->policyRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;
        $state->demandShock = 0.004;

        return $state;
    }

    /**
     * The sovereign fund's stabilisation is spending: one point of GDP of it is worth what one point of GDP of purchases
     * is through the purchases channel, and without it the channel is silent. The budget round that set it is its
     * legislative lag, so demand answers the rate the round set from the tick it is set, not a smoothing of it.
     */
    public function testTheFundsStabilisationReachesDemandAsPurchasesDo(): void
    {
        $dt = 1.0 / 3600.0;
        // The probe books each channel's move over the window, so one tick's is its drift times the tick.
        $contribution = function (float $stabilisation) use ($dt): float {
            $probe = new OutputGapProbe();
            $probe->enable();
            $state = $this->probedState();
            $state->sovereignFundStabilisationToGdp = $stabilisation;
            $this->probedSubsystem($probe, 0.0)->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);

            return $probe->snapshot()['current']['contributions']['fundStabilisation'] / $dt;
        };

        $this->assertSame(0.0, $contribution(0.0));
        $this->assertEqualsWithDelta(
            MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::KALDOR_GOVT_SPENDING_MULTIPLIER * 0.01 / MacroEngine::TARGET_CORPORATE_TAX_RATE,
            $contribution(0.01),
            1e-15,
            'A point of GDP is a purchases shift of one over the purchases share.'
        );
        $this->assertEqualsWithDelta(-$contribution(0.01), $contribution(-0.01), 1e-15, 'Saving in a boom drags as much as spending lifts.');
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
            $state->outputGap = $subsystem->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
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
            'The decomposition must reconstruct the gap it decomposes'
        );

        // Every channel in the drift is named, so the panel cannot silently drop one.
        $this->assertCount(28, $window['contributions']);
        $this->assertArrayHasKey('productivitySupply', $window['contributions']);
        $this->assertArrayHasKey('fundStabilisation', $window['contributions']);
        $this->assertArrayHasKey('premiumDrag', $window['contributions']);
        $this->assertArrayHasKey('premiumCompensator', $window['contributions']);
        $this->assertArrayHasKey('demandDisaster', $window['contributions']);
        $this->assertArrayHasKey('disasterCompensator', $window['contributions']);
        $this->assertArrayHasKey('monetaryDrag', $window['contributions']);
        $this->assertArrayHasKey('automaticStabiliser', $window['contributions']);
        $this->assertEqualsWithDelta(array_sum($window['contributions']), $window['drift'], 1e-15);
    }

    /**
     * The disturbance is linear, so its noise, its disasters and their compensator are three processes summing to
     * it exactly: the split attributes the level without changing it, and a disaster decays as the whole does.
     */
    public function testTheDisturbanceSplitsIntoNoiseDisastersAndCompensationThatSumToIt(): void
    {
        $probe = new OutputGapProbe();
        $probe->enable();
        $math = new class extends MathUtility {
            private int $gate = 0;

            public function generateStandardNormal(): float
            {
                return 0.3;
            }

            // Exactly one disaster, on the first tick.
            public function checkProbability(float $probability): bool
            {
                return $this->gate++ === 0;
            }

            public function generateUniform(): float
            {
                return 0.99; // down
            }

            public function generateExponential(float $rate = 1.0): float
            {
                return 0.04;
            }
        };
        $subsystem = new MacroAggregateSubsystem($math, $probe);
        $state = $this->probedState();
        $dt = 1.0 / 360.0;

        for ($tick = 0; $tick < 90; ++$tick) {
            $state->outputGap = $subsystem->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        }

        $decay = 1.0 - (MacroAggregateSubsystem::DEMAND_SHOCK_REVERSION * $dt);
        $this->assertEqualsWithDelta(-0.04 * $decay ** 89, $state->demandDisasterShock, 1e-12);

        // What is left is the Gaussian disturbance alone, as if no disaster or compensator had ever touched it.
        $noise = 0.004;
        for ($tick = 0; $tick < 90; ++$tick) {
            $noise = ($noise * $decay) + (MacroAggregateSubsystem::DEMAND_SHOCK_SIGMA * sqrt($dt) * 0.3);
        }
        $this->assertEqualsWithDelta($noise, $state->demandShock - $state->demandDisasterShock + $state->demandDisasterCompensation, 1e-12);
        $this->assertLessThan(0.0, $probe->snapshot()['current']['contributions']['demandDisaster']);
    }

    /** At a zero premium the kinked response is silent and the compensator alone is on the panel, as its own line. */
    public function testThePremiumCompensatorIsItsOwnChannel(): void
    {
        $probe = new OutputGapProbe();
        $probe->enable();
        $subsystem = $this->probedSubsystem($probe, 0.0);
        $state = $this->probedState();
        $state->excessBondPremium = 0.0;

        $subsystem->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $contributions = $probe->snapshot()['current']['contributions'];

        $this->assertSame(0.0, $contributions['premiumDrag']);
        $this->assertGreaterThan(0.0, $contributions['premiumCompensator'], 'The adverse-only drag is compensated upward.');
    }

    // --- Productivity Shocks ---

    /**
     * The shock is a driftless random walk at the fitted volatility and is the whole of the index's departure
     * from its trend path: Fernald's utilization-adjusted TFP has no fat tails, so no jump rides on top. Checked
     * at two tick rates, because a sqrt(dt) innovation is exactly what a coarse clock gets wrong.
     */
    public function testTheProductivityShockIsADriftlessRandomWalk(): void
    {
        $years = 10;
        $expectedSd = MacroAggregateSubsystem::TFP_VOLATILITY * sqrt($years);
        foreach ([36, 144] as $ticksPerYear) {
            $ends = [];
            for ($seed = 1; $seed <= 400; $seed++) {
                mt_srand($seed * 104729 + $ticksPerYear);
                $subsystem = new MacroAggregateSubsystem(new MathUtility());
                $state = new MacroState();
                $state->outputGapEma = 0.0;
                for ($tick = 0; $tick < $years * $ticksPerYear; $tick++) {
                    $subsystem->calculateTotalFactorProductivity($state, 1.0 / $ticksPerYear);
                }
                $ends[] = $state->tfpShockLevel;
                if ($seed === 1) {
                    $offTrend = log($state->totalFactorProductivityIndex / MacroEngine::TFP_BASELINE) - (MacroEngine::TFP_DRIFT * $years);
                    $this->assertEqualsWithDelta($offTrend, $state->tfpShockLevel, 1e-9, 'The index leaves its trend path by the shock level and nothing else.');
                }
            }

            $mean = array_sum($ends) / count($ends);
            $sd = sqrt(array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $ends)) / count($ends));
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
            $growth = $this->subsystem->absorbProductivityShocks($state, MacroEngine::TFP_DRIFT, $dt);
        }

        $t = 2.0;
        $ky = MacroAggregateSubsystem::TFP_OUTPUT_ABSORPTION_SPEED;
        $kp = MacroAggregateSubsystem::TFP_POTENTIAL_ABSORPTION_SPEED;
        $this->assertEqualsWithDelta(0.01 * (1.0 - (1.0 + $ky * $t) * exp(-$ky * $t)), $state->tfpOutputStage2, 2e-5);
        $this->assertEqualsWithDelta(0.01 * (1.0 - (1.0 + $kp * $t) * exp(-$kp * $t)), $state->tfpPotentialAbsorbed, 2e-5);
        $this->assertGreaterThan($state->tfpPotentialAbsorbed, $state->tfpOutputStage2, 'Output catches up with the new technology before potential does.');
        $this->assertEqualsWithDelta(MacroEngine::TFP_DRIFT + ($kp * ($state->tfpPotentialStage1 - $state->tfpPotentialAbsorbed)), $growth, 1e-5);
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
        $plainSubsystem = $this->probedSubsystem(new OutputGapProbe(), 0.8);
        $shockedSubsystem = $this->probedSubsystem($probe, 0.8);

        $dt = 1.0 / 3600.0;
        for ($tick = 0; $tick < 900; ++$tick) {
            $plain->outputGap = $plainSubsystem->calculateOutputGap($plain, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
            $shocked->outputGap = $shockedSubsystem->calculateOutputGap($shocked, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
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
            $state->outputGap = $subsystem->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        }

        $window = $probe->snapshot()['current'];

        // A 2pp/yr drag held for a quarter delivers half a point of gap, and delivers it negative.
        $this->assertEqualsWithDelta(-MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * 0.02 * 0.25, $window['contributions']['crisisDeleveragingDrag'], 1e-12);
        $contributions = $window['contributions'];
        asort($contributions);
        $this->assertSame(
            'crisisDeleveragingDrag',
            array_key_first($contributions),
            'The crisis is the heaviest drag on a state built around it'
        );
    }

    /**
     * The crisis drag is one-sided, so it carries its Merton compensator as its own channel, as the disasters and the
     * premium do: a calm quarter reads a small lift, and a drag at its long-run average nets to nothing.
     */
    public function testTheCrisisDragIsCompensatedByItsLongRunAverage(): void
    {
        $dt = 1.0 / 3600.0;
        $crisisChannels = function (float $drag) use ($dt): array {
            $probe = new OutputGapProbe();
            $probe->enable();
            $state = $this->probedState();
            $state->creditCrisisDrag = $drag;
            $this->probedSubsystem($probe, 0.0)->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
            $contributions = $probe->snapshot()['current']['contributions'];

            return [$contributions['crisisDeleveragingDrag'] / $dt, $contributions['crisisCompensator'] / $dt];
        };

        [$calmDrag, $calmCompensator] = $crisisChannels(0.0);
        $this->assertSame(0.0, $calmDrag);
        $this->assertEqualsWithDelta(MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT * MacroAggregateSubsystem::KALDOR_CRISIS_DRAG_COMPENSATOR, $calmCompensator, 1e-12, 'With no crisis running the compensator is its whole long-run average, on demand\'s share of GDP.');

        [$drag, $compensator] = $crisisChannels(MacroAggregateSubsystem::KALDOR_CRISIS_DRAG_COMPENSATOR);
        $this->assertEqualsWithDelta(0.0, $drag + $compensator, 1e-12, 'A drag at its long-run average nets to nothing.');
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
        $state->outputGap = MacroAggregateSubsystem::OUTPUT_GAP_FLOOR;
        $state->creditCrisisDrag = 0.50;
        $state->demandShock = 0.0;

        $newGap = $subsystem->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $window = $probe->snapshot()['current'];

        $this->assertSame(MacroAggregateSubsystem::OUTPUT_GAP_FLOOR, $newGap, 'The floor holds');
        $this->assertGreaterThan(0.0, $window['clamp'], 'The floor gave back the gap the drift took past it');
        $this->assertEqualsWithDelta(0.0, $window['unexplained'], 1e-12);
        $this->assertEqualsWithDelta(
            $window['change'],
            $window['drift'] + $window['diffusion'] + $window['clamp'] + $window['unexplained'],
            1e-12
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

        $subsystem->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);

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

        $subsystem->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $closing = $probe->snapshot()['current']['closing_gap'];

        $probe->rollWindow();
        $snapshot = $probe->snapshot();

        $this->assertNull($snapshot['current'], 'The new quarter has not seen a tick yet');
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

        $gapAtBaseline = $this->subsystem->calculateOutputGap($atBaseline, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapWeakButSettled = $this->subsystem->calculateOutputGap($weakButSettled, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);

        $this->assertEqualsWithDelta(
            $gapAtBaseline,
            $gapWeakButSettled,
            1e-12,
            'A currency sitting at its own trend is this economy\'s normal, not a devaluation.'
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
            $settled->outputGap = $gapSettled = $this->subsystem->calculateOutputGap($settled, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
            $depreciated->outputGap = $gapDepreciated = $this->subsystem->calculateOutputGap($depreciated, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        }

        $priceResponse = (MacroAggregateSubsystem::EXPORT_PRICE_PASS_THROUGH * MacroAggregateSubsystem::EXPORT_PRICE_ELASTICITY * MacroAggregateSubsystem::DISTRICT_EXPORT_SHARE)
            + (MacroAggregateSubsystem::IMPORT_PRICE_PASS_THROUGH * MacroAggregateSubsystem::IMPORT_PRICE_ELASTICITY * MacroAggregateSubsystem::DISTRICT_IMPORT_SHARE);
        $longTerm = -$priceResponse * log(0.90);

        $this->assertSame(0.0, $settled->netExportGap, 'A currency sitting at its own trend moves no trade.');
        $this->assertEqualsWithDelta(0.87 * $longTerm, $depreciated->netExportGap, 0.002 * $longTerm, 'A year on, 87% of the long-term lift, as the IMF\'s one-year effects give.');
        $this->assertEqualsWithDelta($depreciated->netExportGap, $gapDepreciated - $gapSettled, 1e-9, 'and it is in the gap.');
        $this->assertGreaterThan(0.005, $longTerm, 'A 10% real depreciation is worth well over half a point of GDP at a fifth of GDP exported');
        $this->assertLessThan(0.015, $longTerm, 'and less than the 1.5% the IMF sample\'s 42% trade shares give.');
    }

    /**
     * GDP by industry with ESA 2010 deflated-balance volumes: the District's funds and brokers produce as the value of what
     * they manage does, so a bear market is a District recession on the day it happens, whatever demand does after.
     */
    public function testABearMarketIsADistrictRecession(): void
    {
        $calm = $this->neutralBorrowingState();
        $calm->equityWealthRatio = 1.0;
        $calm->financeMarketTrend = 1.0;
        $crash = $this->neutralBorrowingState();
        $crash->equityWealthRatio = 0.60;
        $crash->financeMarketTrend = 1.0;

        $gapCalm = $this->subsystem->calculateOutputGap($calm, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.01, 1.0);
        $gapCrash = $this->subsystem->calculateOutputGap($crash, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.01, 1.0);

        $financeLoss = MacroAggregateSubsystem::FINANCE_GAP_WEIGHT * MacroAggregateSubsystem::FINANCE_MARKET_SHARE * log(0.60);
        $this->assertEqualsWithDelta($financeLoss, $crash->financeOutputGap, 1e-12);
        $this->assertEqualsWithDelta($financeLoss - $calm->financeOutputGap, $gapCrash - $gapCalm, 1e-9, 'The lost fees are lost output, at once.');
        $this->assertLessThan(-0.03, $financeLoss, 'A 40% fall in managed assets costs the District over 3% of GDP.');
    }

    /**
     * Potential finance output is the one-sided HP trend of what it manages: a market that climbs steadily is growth, not a
     * boom. The three-year habituation trend the household wealth effect reads lags that climb by three years of it.
     */
    public function testASteadyBullMarketIsPotentialFinanceOutputNotABoom(): void
    {
        $state = new MacroState();
        $state->nominalGdpIndex = 1.0;
        $cap = 1.0e12;
        for ($quarter = 0; $quarter < 200; $quarter++) {
            $cap *= exp(0.032 / 4.0);
            $state->equityMarketCap = $cap;
            for ($tick = 0; $tick < 25; $tick++) {
                $state->totalTime += 0.01;
                $this->subsystem->updateExponentialMovingAverages($state, 0.01);
            }
        }

        // The trend steps on the quarter, so between steps the market can sit up to a quarter's climb above it.
        $this->assertEqualsWithDelta(0.0, MacroAggregateSubsystem::marketBalanceGap($state->equityWealthRatio, $state->financeMarketTrend), 0.032 / 4.0, 'A steady 3.2% a year is the trend itself.');
        $this->assertGreaterThan(0.08, log($state->equityWealthRatio / $state->equityWealthTrend), 'The three-year habituation trend sits ~9.6% behind it.');
    }

    /** Bank output follows deflated loan balances: a credit boom is bank output, a bust takes it back. */
    public function testACreditBoomIsBankOutput(): void
    {
        $boom = $this->neutralBorrowingState();
        $boom->creditToGdpTrend = 1.0;
        $boom->creditToGdpGap = 0.10;
        $this->subsystem->calculateOutputGap($boom, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.01, 1.0);

        $this->assertEqualsWithDelta(
            MacroAggregateSubsystem::FINANCE_GAP_WEIGHT * MacroAggregateSubsystem::FINANCE_CREDIT_SHARE * log(1.10),
            $boom->financeOutputGap,
            1e-12
        );
    }

    /**
     * The finance weight is only the finance the District has beyond what the US-fitted demand equation already holds:
     * the two weights split GDP, and a District with the US's finance share would add no finance cycle at all.
     */
    public function testTheFinanceWeightIsTheShareBeyondTheFittedEconomy(): void
    {
        $this->assertEqualsWithDelta(
            1.0,
            (MacroAggregateSubsystem::DOMESTIC_GAP_WEIGHT / (1.0 - MacroAggregateSubsystem::EXCESS_IMPORT_LEAKAGE))
                + (MacroAggregateSubsystem::FINANCE_GAP_WEIGHT * (MacroAggregateSubsystem::FINANCE_CREDIT_SHARE + MacroAggregateSubsystem::FINANCE_MARKET_SHARE)),
            1e-15
        );
        $us = MacroAggregateSubsystem::US_FINANCE_SHARE;
        $this->assertEqualsWithDelta(0.0, $us - ((1.0 - $us) * $us / (1.0 - $us)), 1e-15, 'At the US share the weight vanishes.');
        $this->assertGreaterThan(0.2, MacroAggregateSubsystem::FINANCE_GAP_WEIGHT);
        $this->assertLessThan(MacroAggregateSubsystem::DISTRICT_FINANCE_SHARE, MacroAggregateSubsystem::FINANCE_GAP_WEIGHT);
    }

    /**
     * An open economy's demand leaks abroad: the District's imports take 1.4 times each move in domestic demand at
     * their share of GDP (IMF WEO 2015), and the fitted US equation already leaks at the US's share, so only the
     * excess comes out. At the US's import share nothing does.
     */
    public function testDomesticDemandLeaksTheDistrictsExcessImportsAbroad(): void
    {
        $this->assertEqualsWithDelta(
            MacroAggregateSubsystem::IMPORT_DEMAND_ELASTICITY * (MacroAggregateSubsystem::DISTRICT_IMPORT_SHARE - MacroAggregateSubsystem::US_IMPORT_SHARE),
            MacroAggregateSubsystem::EXCESS_IMPORT_LEAKAGE,
            1e-15
        );
        $this->assertEqualsWithDelta(0.0, MacroAggregateSubsystem::IMPORT_DEMAND_ELASTICITY * (MacroAggregateSubsystem::US_IMPORT_SHARE - MacroAggregateSubsystem::US_IMPORT_SHARE), 1e-15, 'At the US share nothing extra leaks.');
        $this->assertGreaterThan(0.2, MacroAggregateSubsystem::EXCESS_IMPORT_LEAKAGE, 'Twice the US import share leaks about a quarter of every demand swing.');

        // A demand impulse reaches the gap net of the leak.
        $dt = 0.25;
        $calm = $this->neutralBorrowingState();
        $shocked = $this->neutralBorrowingState();
        $shocked->demandShock = 0.02;
        $delta = $this->subsystem->calculateOutputGap($shocked, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0)
            - $this->subsystem->calculateOutputGap($calm, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        $this->assertLessThan(0.02 * $dt * (1.0 - MacroAggregateSubsystem::EXCESS_IMPORT_LEAKAGE) + 1e-6, $delta, 'The impulse is net of the excess imports.');
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
        $appreciated->importPriceLevel = MacroAggregateSubsystem::importPriceLevelTarget($atRest->exchangeRateIndexEma);

        $dt = 0.01;
        $levelGap = 0.0;
        for ($i = 0; $i < 3000; $i++) {
            $restInflation = $this->subsystem->calculateInflation($atRest, MacroEngine::TARGET_INFLATION, 1.0, $dt);
            $levelGap += ($this->subsystem->calculateInflation($appreciated, MacroEngine::TARGET_INFLATION, 1.0, $dt) - $restInflation) * $dt;
        }

        $importedShare = MacroAggregateSubsystem::INFLATION_WEIGHT_GOODS + MacroAggregateSubsystem::INFLATION_WEIGHT_COMMODITY;
        $expected = -$importedShare * (1.0 - MacroAggregateSubsystem::IMPORT_DISTRIBUTION_SHARE) * MacroAggregateSubsystem::IMPORT_PRICE_PASS_THROUGH * log(1.10);
        $this->assertEqualsWithDelta($expected, $levelGap, 0.0005, 'A 10% appreciation takes about 1.6% off the price level.');
        $this->assertEqualsWithDelta(MacroAggregateSubsystem::importPriceLevelTarget($appreciated->exchangeRateIndexEma), $appreciated->importPriceLevel, 1e-9, 'and import prices have settled.');
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
            'Over half a currency swing the trend must still read most of that swing as a deviation.'
        );
        $this->assertGreaterThan(
            0.0,
            $followed,
            'The trend must follow a genuinely new level eventually, or it is just another constant.'
        );
    }
}
