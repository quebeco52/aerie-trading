<?php

namespace App\Tests\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Math\MathUtility;
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

        $newGap = $this->subsystem->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, 0.25, 1.0);
        $newInflation = $this->subsystem->calculateInflation($state, MacroEngine::TARGET_INFLATION, 1.0, 0.25);

        $this->assertGreaterThan(-0.12, $newGap);
        $this->assertLessThan(0.10, $newGap);
        $this->assertGreaterThan(-0.02, $newInflation);
    }

    public function testConvexPhillipsCurveAcceleratesNearCapacity(): void
    {
        $expansionPressureLow = $this->mathUtility->calculateConvexPhillipsCurve(0.02, MacroEngine::PHILLIPS_MAX_CAPACITY, MacroEngine::PHILLIPS_CONVEX_KAPPA, MacroEngine::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR);
        $expansionPressureHigh = $this->mathUtility->calculateConvexPhillipsCurve(0.06, MacroEngine::PHILLIPS_MAX_CAPACITY, MacroEngine::PHILLIPS_CONVEX_KAPPA, MacroEngine::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR);

        // Near capacity (y=0.06), pressure must be more than 3x higher than at y=0.02 due to non-linear convexity
        $this->assertGreaterThan(3.0 * $expansionPressureLow, $expansionPressureHigh);

        // During contractions (y=-0.04), downward nominal rigidity flattens deflation pressure
        $recessionPressure = $this->mathUtility->calculateConvexPhillipsCurve(-0.04, MacroEngine::PHILLIPS_MAX_CAPACITY, MacroEngine::PHILLIPS_CONVEX_KAPPA, MacroEngine::PHILLIPS_DOWNWARD_RIGIDITY_FACTOR);
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

        $updatedGap = $this->subsystem->calculateOutputGap($state, 0.035, MacroEngine::BASE_NATURAL_RATE, 0.25, 1.0);

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

        $gapBaseline = $this->subsystem->calculateOutputGap($baseline, $neutral5y, MacroEngine::BASE_NATURAL_RATE, 0.25, 1.0);
        $gapHighEra = $this->subsystem->calculateOutputGap($highEra, $fiveYearInHighEra, MacroEngine::BASE_NATURAL_RATE, 0.25, 1.0);

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

        $gapBaseline = $this->subsystem->calculateOutputGap($baseline, 0.035, MacroEngine::BASE_NATURAL_RATE, 0.25, 1.0);
        $gapStimulus = $this->subsystem->calculateOutputGap($stimulus, 0.035, MacroEngine::BASE_NATURAL_RATE, 0.25, 1.0);

        $expectedImpulse = MacroEngine::KALDOR_GOVT_SPENDING_MULTIPLIER * 0.10 * 0.25;
        $this->assertEqualsWithDelta(
            $expectedImpulse,
            $gapStimulus - $gapBaseline,
            1e-9,
            'A 10% public spending increase must add the calibrated demand impulse to the output gap drift.'
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
            -0.20 * MacroEngine::AGRI_COST_PUSH_TRANSMISSION,
            $state->agriCostPushLag,
            0.0005,
            'After two years the distributed lag must have converged to the full symmetric pass-through.'
        );
    }
}
