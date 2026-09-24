<?php

namespace App\Tests\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

class CommodityLogisticsSubsystemTest extends TestCase
{
    private MathUtility $mathUtility;
    private CommodityLogisticsSubsystem $subsystem;

    protected function setUp(): void
    {
        $this->mathUtility = new MathUtility();
        $this->subsystem = new CommodityLogisticsSubsystem($this->mathUtility);
    }

    public function testCommodityAndFreightIndicesStayWithinSensibleBounds(): void
    {
        $state = new MacroState();
        $state->energyPriceIndex = 100.0;
        $state->industrialMetalsIndex = 100.0;
        $state->agriculturalCommodityIndex = 100.0;
        $state->freightRateIndex = 100.0;
        $state->freightSupplyEma = 100.0;

        $this->subsystem->calculateEnergyShock($state, 0.25);
        $this->subsystem->calculateIndustrialMetalsIndex($state, 0.25);
        $this->subsystem->calculateAgriculturalCommodityIndex($state, 0.25);
        $this->subsystem->calculateFreightRateIndex($state, 0.25);

        $this->assertGreaterThan(10.0, $state->energyPriceIndex);
        $this->assertGreaterThan(20.0, $state->industrialMetalsIndex);
        $this->assertGreaterThan(20.0, $state->agriculturalCommodityIndex);
        $this->assertGreaterThan(20.0, $state->freightRateIndex);
    }

    public function testEnergyEquilibriumClearsGlobalDemandOverLaggedCapacity(): void
    {
        // Balanced: demand at baseline against baseline capacity clears at the baseline price.
        $this->assertEqualsWithDelta(
            MacroEngine::ENERGY_BASELINE,
            CommodityLogisticsSubsystem::resolveEnergyEquilibriumPrice(0.0, MacroEngine::ENERGY_BASELINE),
            1e-9
        );

        // A 2% global boom against unchanged capacity clears at the combined inelasticity.
        $boomDemand = 1.0 + (0.02 * CommodityLogisticsSubsystem::ENERGY_DEMAND_GAP_SENSITIVITY);
        $this->assertEqualsWithDelta(
            MacroEngine::ENERGY_BASELINE * ($boomDemand ** CommodityLogisticsSubsystem::ENERGY_CAPACITY_INELASTICITY),
            CommodityLogisticsSubsystem::resolveEnergyEquilibriumPrice(0.02, MacroEngine::ENERGY_BASELINE),
            1e-9
        );

        // Capacity built out far beyond demand cannot drag the equilibrium below the floor any market has cleared at.
        $this->assertSame(
            CommodityLogisticsSubsystem::MIN_ENERGY_EQUILIBRIUM,
            CommodityLogisticsSubsystem::resolveEnergyEquilibriumPrice(-0.05, 400.0)
        );
    }

    public function testConvenienceYieldSpikesWhenPhysicalInventoryDrawsDown(): void
    {
        $ampleYield = $this->mathUtility->calculateConvenienceYield(105.0, 50.0);
        $this->assertEquals(0.0, $ampleYield, 'Ample buffer stocks must have zero convenience yield (contango)');

        $tightYield = $this->mathUtility->calculateConvenienceYield(75.0, 50.0);
        $criticalYield = $this->mathUtility->calculateConvenienceYield(55.0, 50.0);

        $this->assertGreaterThan(0.0, $tightYield);
        $this->assertGreaterThan(3.0 * $tightYield, $criticalYield, 'Critical inventory depletion must spike convenience yield non-linearly');

        $state = new MacroState();
        $state->energyPriceIndex = 100.0;
        $state->energyInventoryIndex = 55.0; // Very tight inventory buffer

        $this->subsystem->calculateEnergyShock($state, 0.25);
        $this->assertGreaterThan(0.0, $state->energyPriceShock, 'Depleted buffer inventory must generate backwardation price shock');
    }

    public function testCalculateRefiningCrackSpread(): void
    {
        $state = new MacroState();
        $state->refiningCrackSpread = 22.0;
        $state->outputGapEma = 0.02;
        $state->energyInventoryIndexEma = 75.0; // Tight inventory

        $this->subsystem->calculateRefiningCrackSpread($state, 0.25);
        $this->assertGreaterThan(10.0, $state->refiningCrackSpread);
        $this->assertLessThan(80.0, $state->refiningCrackSpread);
    }

    public function testCalculateSupplyChainPressureIndex(): void
    {
        $stateNeutral = new MacroState();
        $stateNeutral->freightRateIndexEma = 100.0;
        $stateNeutral->inventoryStockGapEma = 0.0;
        $stateNeutral->industrialMetalsIndexEma = 100.0;

        $this->subsystem->calculateSupplyChainPressureIndex($stateNeutral);
        $this->assertEqualsWithDelta(0.0, $stateNeutral->supplyChainPressureIndex, 0.0001);

        $stateChoked = new MacroState();
        $stateChoked->freightRateIndexEma = 220.0; // Huge ocean freight spike
        $stateChoked->inventoryStockGapEma = -0.04; // Severe inventory depletion
        $stateChoked->industrialMetalsIndexEma = 140.0;

        $this->subsystem->calculateSupplyChainPressureIndex($stateChoked);
        $this->assertGreaterThan(1.5, $stateChoked->supplyChainPressureIndex, 'Logistics bottlenecks must generate positive GSCPI stress.');
    }


    // --- Natural Gas ---

    /** Ramberg & Parsons (2012): the gas-to-oil ratio is stationary around one; the level is not. */
    public function testTheGasToOilRatioIsStationaryAroundUnity(): void
    {
        mt_srand(20260919);
        $state = new MacroState();
        $state->energyPriceIndex = 100.0;

        $logRatios = [];
        $ratios = [];
        for ($i = 0; $i < 40 * 252; $i++) {
            $state->totalTime += 1.0 / 252.0;
            $this->subsystem->calculateNaturalGasIndex($state, 1.0 / 252.0);
            if ($i > 5 * 252 && $i % 63 === 0) {
                $logRatios[] = $state->gasOilRatioLog;
                $ratios[] = exp($state->gasOilRatioLog);
            }
        }

        $count = count($logRatios);
        $this->assertEqualsWithDelta(1.0, array_sum($ratios) / $count, 0.10, 'The ratio averages one: gas prices off oil.');
        $variance = 0.0;
        $mean = array_sum($logRatios) / $count;
        foreach ($logRatios as $x) {
            $variance += ($x - $mean) ** 2;
        }
        $this->assertGreaterThan(0.20, sqrt($variance / $count), 'but wanders enough to be a market of its own');
        $this->assertLessThan(0.45, sqrt($variance / $count), 'without leaving the recorded range.');
    }

    public function testGasCarriesAWinterPremiumOverItsSummerPrice(): void
    {
        $quiet = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
            public function checkProbability(float $probability): bool { return false; }
        };
        $subsystem = new CommodityLogisticsSubsystem($quiet);

        $winter = new MacroState();
        $winter->totalTime = 3.0;
        $summer = new MacroState();
        $summer->totalTime = 3.5;

        $subsystem->calculateNaturalGasIndex($winter, 0.01);
        $subsystem->calculateNaturalGasIndex($summer, 0.01);

        $expectedRatio = (1.0 + CommodityLogisticsSubsystem::GAS_SEASONALITY_AMPLITUDE) / (1.0 - CommodityLogisticsSubsystem::GAS_SEASONALITY_AMPLITUDE);
        $this->assertEqualsWithDelta($expectedRatio, $winter->naturalGasPriceIndex / $summer->naturalGasPriceIndex, 0.001);
    }

    public function testGasFollowsOilThroughTheRatio(): void
    {
        $quiet = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
            public function checkProbability(float $probability): bool { return false; }
        };
        $subsystem = new CommodityLogisticsSubsystem($quiet);

        $cheapOil = new MacroState();
        $cheapOil->totalTime = 0.25;
        $cheapOil->energyPriceIndex = 80.0;
        $dearOil = new MacroState();
        $dearOil->totalTime = 0.25;
        $dearOil->energyPriceIndex = 160.0;

        $subsystem->calculateNaturalGasIndex($cheapOil, 0.01);
        $subsystem->calculateNaturalGasIndex($dearOil, 0.01);

        $this->assertEqualsWithDelta(2.0, $dearOil->naturalGasPriceIndex / $cheapOil->naturalGasPriceIndex, 1e-9, 'With the ratio pinned, gas is oil.');
    }


    // --- Physical Catastrophes ---

    public function testTheCatastropheBurdenAveragesAnAverageYearWithARealRightTail(): void
    {
        mt_srand(20260919);
        $state = new MacroState();
        $samples = [];
        for ($i = 0; $i < 60 * 252; $i++) {
            $state->totalTime += 1.0 / 252.0;
            $this->subsystem->calculateCatastropheLosses($state, 1.0 / 252.0);
            if ($i > 2 * 252 && $i % 21 === 0) {
                $samples[] = $state->catastropheLossIndex;
            }
        }

        sort($samples);
        $count = count($samples);
        $mean = array_sum($samples) / $count;
        $this->assertEqualsWithDelta(1.0, $mean, 0.25, 'The burden averages an average year: lambda x mean severity / decay = 1.');
        $this->assertGreaterThan(3.0 * $mean, $samples[(int) floor(0.99 * $count)], 'and the worst percent of months carry several average years of losses.');
        $this->assertLessThan(0.5 * $mean, $samples[(int) floor(0.25 * $count)], 'while most months are quiet.');
    }

    public function testAHeadlineStormIsRecordedOnTheTickItLands(): void
    {
        $math = new class extends MathUtility {
            public int $events = 0;
            public float $severity = 0.0;
            public function generatePoissonCount(float $mean): int { return $this->events; }
            public function generateParetoSeverity(float $scale, float $alpha, float $cap): float { return $this->severity; }
        };
        $subsystem = new CommodityLogisticsSubsystem($math);
        $state = new MacroState();
        $state->totalTime = 2.0;
        $state->catastropheLossIndex = 0.0; // a clean slate, so the two scripted events are all the burden there is

        $math->events = 1;
        $math->severity = CommodityLogisticsSubsystem::SYSTEMIC_CATASTROPHE_SEVERITY - 0.1;
        $subsystem->calculateCatastropheLosses($state, 0.01);
        $this->assertSame(-1.0, $state->lastCatastropheAt, 'A routine event is claims, not news.');

        $state->totalTime = 2.01;
        $math->severity = 4.0;
        $subsystem->calculateCatastropheLosses($state, 0.01);
        $this->assertSame(2.01, $state->lastCatastropheAt);
        $this->assertSame(4.0, $state->lastCatastropheSeverity);
        $this->assertGreaterThan(4.0, $state->catastropheLossIndex, 'The burden carries both events.');

        $math->events = 0;
        $state->totalTime = 2.51;
        $subsystem->calculateCatastropheLosses($state, 0.5);
        $this->assertEqualsWithDelta(exp(-CommodityLogisticsSubsystem::CATASTROPHE_LOSS_DECAY * 0.5), $state->catastropheLossIndex / (4.0 + (CommodityLogisticsSubsystem::SYSTEMIC_CATASTROPHE_SEVERITY - 0.1) * exp(-CommodityLogisticsSubsystem::CATASTROPHE_LOSS_DECAY * 0.01)), 0.01, 'and settles at the claims decay rate.');
    }


    // --- Energy Supply Cobweb ---

    private function quietCommoditySubsystem(): CommodityLogisticsSubsystem
    {
        $quiet = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
            public function checkProbability(float $probability): bool { return false; }
        };

        return new CommodityLogisticsSubsystem($quiet);
    }

    /** Anderson, Kellogg & Salant (2018): a sustained high price summons capacity, but only over the investment lag. */
    public function testASustainedPriceLiftsCapacityOverTheInvestmentLag(): void
    {
        $subsystem = $this->quietCommoditySubsystem();
        $state = new MacroState();
        $state->energyPriceIndexEma = 120.0;
        $state->energyInventoryIndex = 100.0;

        $subsystem->calculateEnergyShock($state, 0.25);
        $this->assertLessThan(101.0, $state->energySupplyEma, 'A quarter of high prices adds almost no capacity.');

        for ($i = 0; $i < 4 * 9; $i++) {
            $state->energyPriceIndexEma = 120.0;
            $subsystem->calculateEnergyShock($state, 0.25);
        }
        $targetSupply = 100.0 * (1.2 ** CommodityLogisticsSubsystem::ENERGY_SUPPLY_ELASTICITY);
        $this->assertEqualsWithDelta($targetSupply, $state->energySupplyEma, 0.3, 'Nine years on, capacity has caught up with the price at the supply elasticity.');
    }

    public function testTheEquilibriumPriceClearsDemandAgainstCapacity(): void
    {
        $subsystem = $this->quietCommoditySubsystem();

        $settle = static function (float $gap, float $supply) use ($subsystem): float {
            $state = new MacroState();
            $state->globalDemandGapEma = $gap; // fuel clears on world demand
            $state->energySupplyEma = $supply;
            $state->energyPriceIndex = 100.0;
            $state->energyBasePrice = 100.0;
            $state->energyInventoryIndex = 100.0;
            for ($i = 0; $i < 400; $i++) {
                $state->energyPriceIndexEma = 100.0; // hold the price capacity sees, so only demand moves the clearing level
                $state->energySupplyEma = $supply;
                $subsystem->calculateEnergyShock($state, 0.01);
            }

            return $state->energyBasePrice;
        };

        $neutral = $settle(0.0, 100.0);
        $boom = $settle(0.04, 100.0);
        $glut = $settle(0.0, 104.0);

        $expectedBoom = $neutral * ((1.0 + 0.04 * CommodityLogisticsSubsystem::ENERGY_DEMAND_GAP_SENSITIVITY) ** CommodityLogisticsSubsystem::ENERGY_CAPACITY_INELASTICITY);
        $this->assertEqualsWithDelta($expectedBoom, $boom, 0.5, 'A 4% boom lifts demand 2% and the clearing price by the inelasticity of that.');
        $expectedGlut = $neutral * ((100.0 / 104.0) ** CommodityLogisticsSubsystem::ENERGY_CAPACITY_INELASTICITY);
        $this->assertEqualsWithDelta($expectedGlut, $glut, 0.5, 'and 4% of spare capacity does the same in reverse.');
    }

    public function testCapacityAdjustmentIsInvariantToTheTickRate(): void
    {
        $subsystem = $this->quietCommoditySubsystem();

        $run = static function (int $ticksPerYear) use ($subsystem): float {
            $state = new MacroState();
            $state->energyInventoryIndex = 100.0;
            for ($i = 0; $i < $ticksPerYear * 2; $i++) {
                $state->energyPriceIndexEma = 130.0;
                $subsystem->calculateEnergyShock($state, 1.0 / $ticksPerYear);
            }

            return $state->energySupplyEma;
        };

        $this->assertEqualsWithDelta($run(4), $run(252), 0.05, 'Two years of capacity build must not depend on how finely the years are ticked.');
    }


    /** A district this size does not set the copper price: metals clear on world demand, so a foreign boom moves them with the district flat. */
    public function testMetalsClearOnGlobalDemandNotTheDistrictsGapAlone(): void
    {
        $subsystem = $this->quietCommoditySubsystem();

        $settle = static function (float $globalGap) use ($subsystem): float {
            $state = new MacroState();
            $state->outputGapEma = 0.0;
            $state->globalDemandGapEma = $globalGap;
            for ($i = 0; $i < 4000; $i++) {
                $subsystem->calculateIndustrialMetalsIndex($state, 0.01);
            }

            return $state->industrialMetalsIndex;
        };

        $flat = $settle(0.0);
        $worldBoom = $settle(0.03);
        $this->assertEqualsWithDelta(exp(0.03 * CommodityLogisticsSubsystem::METALS_OUTPUT_GAP_SENSITIVITY), $worldBoom / $flat, 0.01, 'A 3% world boom lifts the long-run metals equilibrium by its elasticity.');
    }
}
