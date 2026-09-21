<?php

namespace App\Tests\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Recorder\OutputGapProbe;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
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

        $expectedImpulse = MacroAggregateSubsystem::KALDOR_GOVT_SPENDING_MULTIPLIER * 0.10 * 0.25;
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
            -MacroAggregateSubsystem::KALDOR_CAPITAL_DRAG * $overhang * 0.25,
            $gapExcess - $gapNeutral,
            1e-9,
            'Excess capacity must subtract its calibrated drag from the output gap drift.'
        );

        $this->assertEqualsWithDelta(
            MacroAggregateSubsystem::KALDOR_CAPITAL_REBOUND_DRAG * $overhang * 0.25,
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
     * The index is a log random walk: a drift scaled by dt, an innovation scaled by sqrt(dt), and rare
     * Schumpeterian breakthroughs. Those two scalings are the trap. A bound written in annual rate units
     * is narrower than one standard deviation of the innovation by a factor of sqrt(1/dt), so clipping the
     * increment rather than the rate clips nearly every step, and realized growth stops answering to
     * TFP_DRIFT and settles on the midpoint of the bounds instead. That is not a rounding error: at the
     * production 3600 ticks/year it delivered 1.02%/yr against the 1.50% asked for, cut the index's
     * volatility from 2.2% to 0.07% and swallowed the jumps whole, leaving a straight line where a
     * stochastic process belongs.
     *
     * So both moments are asserted at two timesteps an order of magnitude apart: what the index delivers
     * must be what the constants say, and must not depend on how finely the clock is ticked.
     */
    public function testTheProductivityIndexDeliversTheDriftAndVolatilityItIsParameterisedWith(): void
    {
        // The breakthrough jumps are not Merton-compensated, so they carry their own arrival-weighted drift.
        $expectedGrowth = MacroEngine::TFP_DRIFT
            + (MacroAggregateSubsystem::TFP_JUMP_PROBABILITY * MacroAggregateSubsystem::TFP_JUMP_MEAN);

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
        $this->assertEqualsWithDelta($applied * $dt, $shockedGap - $baseGap, 1e-9);
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

        // One percentage point of expected inflation on a 0.50 policy leg is 50bps of real easing, through the drag coefficient over a quarter.
        $expectedLift = MacroAggregateSubsystem::KALDOR_MONETARY_DRAG * MacroAggregateSubsystem::BORROWING_POLICY_WEIGHT * 0.01 * 0.25;
        $this->assertEqualsWithDelta($expectedLift, $gapUnanchored - $gapAnchored, 1e-9);
    }

    /** The fixed-rate leg is the real five-year: the breakeven deflates it, so a higher breakeven at the same nominal yield is easier money. */
    public function testAHigherBreakevenAtTheSameNominalFiveYearIsEasierMoney(): void
    {
        $anchored = $this->neutralBorrowingState();
        $repriced = $this->neutralBorrowingState();
        $repriced->tipsBreakeven = 0.03;

        $gapAnchored = $this->subsystem->calculateOutputGap($anchored, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapRepriced = $this->subsystem->calculateOutputGap($repriced, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);

        $expectedLift = MacroAggregateSubsystem::KALDOR_MONETARY_DRAG * MacroAggregateSubsystem::BORROWING_YIELD5Y_WEIGHT * 0.01 * 0.25;
        $this->assertEqualsWithDelta($expectedLift, $gapRepriced - $gapAnchored, 1e-9);
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

    /** The risk premium inside the breakeven is not expected inflation: a breakeven lifted only by the premium leaves the real five-year unchanged. */
    public function testTheInflationRiskPremiumDoesNotEaseTheRealFiveYear(): void
    {
        $anchored = $this->neutralBorrowingState();

        $stressed = $this->neutralBorrowingState();
        $stressed->inflationEma = 0.03; // 1pp of excess inflation adds TIPS_INFLATION_RISK_PREMIUM_SCALE x 1pp of premium.
        $premium = 0.01 * MacroAggregateSubsystem::TIPS_INFLATION_RISK_PREMIUM_SCALE;
        $stressed->tipsBreakeven = MacroEngine::TARGET_INFLATION + $premium;

        $gapAnchored = $this->subsystem->calculateOutputGap($anchored, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapStressed = $this->subsystem->calculateOutputGap($stressed, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);

        $this->assertEqualsWithDelta($gapAnchored, $gapStressed, 1e-12, 'A breakeven lifted only by its risk premium must not read as easier money.');
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

        $expected = MacroAggregateSubsystem::KALDOR_EPU_DRAG * log(2.0) * $dt;
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
        $this->assertEqualsWithDelta(-MacroAggregateSubsystem::KALDOR_CATASTROPHE_DRAG * 2.0 * $dt, $gapStormy - $gapAverage, 1e-9);
    }


    /** Obstfeld & Rogoff (1996): the foreign bloc's boom is the district's exports. */
    public function testAForeignBoomLiftsDemandThroughExports(): void
    {
        $home = new MacroState();
        $abroad = new MacroState();
        $abroad->foreignOutputGapEma = 0.03;

        $dt = 0.25;
        $gapHome = $this->subsystem->calculateOutputGap($home, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);
        $gapAbroad = $this->subsystem->calculateOutputGap($abroad, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, 1.0);

        $this->assertEqualsWithDelta(MacroAggregateSubsystem::KALDOR_FOREIGN_DEMAND * 0.03 * $dt, $gapAbroad - $gapHome, 1e-9);
    }


    /** Drehmann, Juselius & Korinek (2017): service below its average is borrowing that lifts demand; above it, repayment that drags. */
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

        $this->assertEqualsWithDelta(MacroAggregateSubsystem::KALDOR_HOUSEHOLD_DEBT_SERVICE * 0.02 * $dt, $gapCarried - $gapNeutral, 1e-9, 'Two points of service below average is borrowing that adds a point a year.');
        $this->assertEqualsWithDelta(-MacroAggregateSubsystem::KALDOR_HOUSEHOLD_DEBT_SERVICE * 0.02 * $dt, $gapRepaying - $gapNeutral, 1e-9, 'Two points over it take a point a year off demand.');
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

        $this->assertEqualsWithDelta(-0.02 * $dt, $run($deleveraging) - $gapNeutral, 1e-9, 'The crisis drag enters the drift at face value: two points a year off demand.');
        $this->assertEqualsWithDelta(-MacroAggregateSubsystem::KALDOR_LENDING_STANDARDS_DRAG * 0.80 * $dt, $run($tightStandards) - $gapNeutral, 1e-9, 'Standards at the 80% of 2008 are a quantity constraint no rate cut reaches.');
        $this->assertEqualsWithDelta(MacroAggregateSubsystem::KALDOR_LENDING_STANDARDS_DRAG * 0.20 * $dt, $run($looseStandards) - $gapNeutral, 1e-9, 'Easing standards lend into demand.');
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
            MacroAggregateSubsystem::OUTPUT_GAP_DIFFUSION_SIGMA * sqrt($dt),
            $diffusionGap - $neutralGap,
            1e-12
        );

        // First draw only: the disturbance takes it whole and reaches the gap only through the drift.
        [$demandGap, $demandShock] = $run(1.0, 0.0);
        $expectedShock = MacroAggregateSubsystem::DEMAND_SHOCK_SIGMA * sqrt($dt);
        $this->assertEqualsWithDelta($expectedShock, $demandShock - $neutralShock, 1e-15);
        $this->assertEqualsWithDelta($expectedShock * $dt, $demandGap - $neutralGap, 1e-12);
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
        $this->assertCount(18, $window['contributions']);
        $this->assertArrayHasKey('monetaryDrag', $window['contributions']);
        $this->assertEqualsWithDelta(array_sum($window['contributions']), $window['drift'], 1e-15);
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
        $this->assertEqualsWithDelta(-0.02 * 0.25, $window['contributions']['crisisDeleveragingDrag'], 1e-12);
        $contributions = $window['contributions'];
        asort($contributions);
        $this->assertSame(
            'crisisDeleveragingDrag',
            array_key_first($contributions),
            'The crisis is the heaviest drag on a state built around it'
        );
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
     * The elasticity the term exists for still has to bite when the currency moves away from that normal.
     */
    public function testACurrencyBelowItsTrendStillLiftsNetExports(): void
    {
        $settled = $this->neutralBorrowingState();
        $settled->exchangeRateIndexEma = 97.3;
        $settled->exchangeRateTrend = 97.3;

        $depreciated = $this->neutralBorrowingState();
        $depreciated->exchangeRateTrend = 97.3;
        $depreciated->exchangeRateIndexEma = 97.3 * 0.90;

        $gapSettled = $this->subsystem->calculateOutputGap($settled, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapDepreciated = $this->subsystem->calculateOutputGap($depreciated, 0.035, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 0.25, 1.0);

        $expected = MacroAggregateSubsystem::KALDOR_FX_ELASTICITY * 0.10 * 0.25;

        $this->assertEqualsWithDelta(
            $expected,
            $gapDepreciated - $gapSettled,
            1e-12,
            'A 10% depreciation below trend must still deliver the full Mundell-Fleming export lift.'
        );
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
