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
}

