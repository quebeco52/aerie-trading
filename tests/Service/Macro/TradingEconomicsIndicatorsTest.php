<?php

namespace App\Tests\Service\Macro;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\MathUtility;
use App\Tests\Support\MacroStateBuilder;
use PHPUnit\Framework\TestCase;

class TradingEconomicsIndicatorsTest extends TestCase
{
    private MathUtility $mathUtility;
    private MacroAggregateSubsystem $macroAggregateSubsystem;
    private AssetMarketSubsystem $assetMarketSubsystem;
    private MonetaryPolicySubsystem $monetaryPolicySubsystem;

    protected function setUp(): void
    {
        $this->mathUtility = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
        };
        $this->macroAggregateSubsystem = new MacroAggregateSubsystem($this->mathUtility);
        $this->assetMarketSubsystem = new AssetMarketSubsystem($this->mathUtility);
        $this->monetaryPolicySubsystem = new MonetaryPolicySubsystem($this->mathUtility);
    }

    public function testManufacturingPmiExpansionAndContraction(): void
    {
        $dt = 0.25;

        // 1. Expansionary scenario: positive output gap, elevated capacity utilization, inventory restocking
        $expansionState = (new MacroStateBuilder())
            ->asExpansion()
            ->build();
        $expansionState->outputGap = 0.035;
        $expansionState->outputGapEma = 0.020;
        $expansionState->capacityUtilizationRate = 0.83; // Baseline is 0.785
        $expansionState->inventoryStockGap = -0.015;     // Restocking demand
        $expansionState->industrialMetalsIndex = 120.0;  // Upward commodity momentum
        $expansionState->industrialMetalsIndexEma = 100.0;
        $expansionState->energyPriceShock = 10.0;
        $expansionState->sloosTighteningIndexEma = 0.0;
        $expansionState->manufacturingPmi = 50.0;

        $this->macroAggregateSubsystem->calculateManufacturingPmi($expansionState, $dt);

        $this->assertGreaterThan(50.0, $expansionState->manufacturingPmi, 'PMI must indicate expansion (>50) during an economic boom.');
        $this->assertGreaterThanOrEqual(52.0, $expansionState->manufacturingPmi);
        $this->assertLessThanOrEqual(MacroEngine::MAX_PMI, $expansionState->manufacturingPmi);

        // 2. Contractionary scenario: negative output gap, depressed capacity utilization, inventory overhang
        $recessionState = (new MacroStateBuilder())
            ->asRecession()
            ->build();
        $recessionState->outputGap = -0.040;
        $recessionState->outputGapEma = -0.010;
        $recessionState->capacityUtilizationRate = 0.72; // Depressed capacity
        $recessionState->inventoryStockGap = 0.030;      // Involuntary inventory buildup
        $recessionState->industrialMetalsIndex = 80.0;   // Collapsing commodity orders
        $recessionState->industrialMetalsIndexEma = 100.0;
        $recessionState->energyPriceShock = -10.0;
        $recessionState->sloosTighteningIndexEma = 0.30; // Credit tightening
        $recessionState->manufacturingPmi = 50.0;

        $this->macroAggregateSubsystem->calculateManufacturingPmi($recessionState, $dt);

        $this->assertLessThan(50.0, $recessionState->manufacturingPmi, 'PMI must indicate contraction (<50) during a recession.');
        $this->assertLessThanOrEqual(48.0, $recessionState->manufacturingPmi);
        $this->assertGreaterThanOrEqual(MacroEngine::MIN_PMI, $recessionState->manufacturingPmi);
    }

    public function testProducerPriceInflationStageOfProcessingTransmission(): void
    {
        $dt = 0.25;
        $tfpGrowth = MacroEngine::TFP_DRIFT;

        // 1. Benign commodity & supply chain conditions
        $calmState = (new MacroStateBuilder())->build();
        $calmState->energyPriceIndex = MacroEngine::ENERGY_BASELINE;
        $calmState->industrialMetalsIndex = MacroEngine::METALS_BASELINE;
        $calmState->industrialMetalsIndexEma = MacroEngine::METALS_BASELINE;
        $calmState->agriculturalCommodityIndex = MacroEngine::AGRI_BASELINE;
        $calmState->supplyChainPressureIndex = 0.0;
        $calmState->wageGrowth = MacroEngine::TARGET_INFLATION + $tfpGrowth; // Neutral wage growth
        $calmState->outputGap = 0.0;

        $this->macroAggregateSubsystem->calculateProducerPriceInflation($calmState, $tfpGrowth, $dt);

        $this->assertEqualsWithDelta(MacroEngine::TARGET_INFLATION, $calmState->producerPriceInflation, 0.010, 'Calm baseline PPI should track close to long-run inflation target.');

        // 2. Stagflationary upstream cost shock: energy surge, metals spike, supply bottlenecks
        $shockState = (new MacroStateBuilder())
            ->asStagflation()
            ->build();
        $shockState->energyPriceIndex = 180.0; // +80% energy shock
        $shockState->industrialMetalsIndex = 140.0;
        $shockState->agriculturalCommodityIndex = 130.0;
        $shockState->supplyChainPressureIndex = 2.5; // Supply bottleneck
        $shockState->wageGrowth = 0.060;            // Wage pressure
        $shockState->outputGap = -0.010;

        $this->macroAggregateSubsystem->calculateProducerPriceInflation($shockState, $tfpGrowth, $dt);

        $this->assertGreaterThan($calmState->producerPriceInflation, $shockState->producerPriceInflation);
        $this->assertGreaterThan(0.050, $shockState->producerPriceInflation, 'Upstream wholesale cost surges must push PPI significantly higher.');
        $this->assertLessThanOrEqual(MacroEngine::MAX_PPI_INFLATION, $shockState->producerPriceInflation);
    }

    public function testTradeBalanceMundellFlemingDynamics(): void
    {
        $dt = 0.25;

        // 1. Strong domestic currency & high domestic absorption (large positive output gap)
        $stateOvervalued = (new MacroStateBuilder())->build();
        $stateOvervalued->outputGap = 0.035;
        $stateOvervalued->exchangeRateIndex = 115.0; // 15% currency appreciation
        $stateOvervalued->tradeBalanceToGdp = MacroEngine::TRADE_BALANCE_BASELINE;

        $this->assetMarketSubsystem->calculateTradeBalance($stateOvervalued, $dt);

        $this->assertLessThan(
            MacroEngine::TRADE_BALANCE_BASELINE,
            $stateOvervalued->tradeBalanceToGdp,
            'Currency appreciation and strong domestic absorption must deteriorate the trade balance.'
        );

        // 2. Depreciated currency & deep domestic contraction (import compression + export competitiveness)
        $stateDepreciated = (new MacroStateBuilder())->build();
        $stateDepreciated->outputGap = -0.035;
        $stateDepreciated->exchangeRateIndex = 85.0; // 15% currency depreciation
        $stateDepreciated->tradeBalanceToGdp = MacroEngine::TRADE_BALANCE_BASELINE;

        $this->assetMarketSubsystem->calculateTradeBalance($stateDepreciated, $dt);

        $this->assertGreaterThan(
            MacroEngine::TRADE_BALANCE_BASELINE,
            $stateDepreciated->tradeBalanceToGdp,
            'Currency depreciation and domestic import compression must improve the net export balance.'
        );
    }

    public function testHousingStartsTobinQAndUserCostResponse(): void
    {
        $dt = 0.25;

        // 1. Housing boom: high residential property values, low mortgage user costs, loose credit
        $boomState = (new MacroStateBuilder())
            ->asExpansion()
            ->build();
        $boomState->residentialPropertyIndex = 135.0; // High house prices relative to baseline
        $boomState->industrialMetalsIndex = 100.0;    // Normal replacement costs
        $boomState->wageGrowth = 0.030;
        $boomState->yield10yEma = 0.035;              // Low 10y mortgage benchmark
        $boomState->macroCreditSpread = 0.015;
        $boomState->inflationEma = 0.025;             // Moderate appreciation expectation
        $boomState->sloosTighteningIndexEma = 0.0;
        $boomState->housingStartsIndex = 100.0;

        $this->assetMarketSubsystem->calculateHousingStarts($boomState, $dt);

        $this->assertGreaterThan(
            100.0,
            $boomState->housingStartsIndex,
            'High Tobin Q and affordable mortgage credit must stimulate housing starts above baseline.'
        );

        // 2. Housing freeze: depressed prices, high replacement cost, 8% mortgage rates, tight credit
        $slumpState = (new MacroStateBuilder())
            ->asRecession()
            ->build();
        $slumpState->residentialPropertyIndex = 80.0;
        $slumpState->industrialMetalsIndex = 140.0; // Costly building materials
        $slumpState->wageGrowth = 0.050;
        $slumpState->yield10yEma = 0.075;           // ~9% mortgage rates
        $slumpState->macroCreditSpread = 0.040;
        $slumpState->inflationEma = 0.015;
        $slumpState->sloosTighteningIndexEma = 0.40; // 40% net banks tightening mortgages
        $slumpState->housingStartsIndex = 100.0;

        $this->assetMarketSubsystem->calculateHousingStarts($slumpState, $dt);

        $this->assertLessThan(
            100.0,
            $slumpState->housingStartsIndex,
            'High mortgage costs, elevated replacement costs, and bank credit rationing must depress housing starts.'
        );
    }

    public function testMoneySupplyGrowthMonetaryTransmission(): void
    {
        $dt = 0.25;
        $tfpGrowth = MacroEngine::TFP_DRIFT;
        $neutralGrowth = MacroEngine::TARGET_INFLATION + $tfpGrowth + MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE;

        // 1. Central bank quantitative easing (QE) & expansionary bank credit
        $qeState = (new MacroStateBuilder())->build();
        $qeState->balanceSheetIntensity = 0.60;           // Active QE bond buying
        $qeState->sloosTighteningIndexEma = -0.10;        // Easing credit standards
        $qeState->outputGap = 0.02;
        $qeState->moneySupplyGrowth = $neutralGrowth;

        $this->monetaryPolicySubsystem->calculateMoneySupplyGrowth($qeState, $dt, $tfpGrowth);

        $this->assertGreaterThan(
            $neutralGrowth,
            $qeState->moneySupplyGrowth,
            'Central bank QE and commercial bank credit expansion must accelerate M2 money supply growth.'
        );

        // 2. Central bank quantitative tightening (QT) & credit crunch
        $qtState = (new MacroStateBuilder())->build();
        $qtState->balanceSheetIntensity = -0.60;          // Aggressive balance sheet runoff (QT)
        $qtState->sloosTighteningIndexEma = 0.45;         // 45% net banks tightening lending
        $qtState->outputGap = -0.025;
        $qtState->moneySupplyGrowth = $neutralGrowth;

        $this->monetaryPolicySubsystem->calculateMoneySupplyGrowth($qtState, $dt, $tfpGrowth);

        $this->assertLessThan(
            $neutralGrowth,
            $qtState->moneySupplyGrowth,
            'Aggressive QT and commercial credit rationing must sharply slow M2 money supply growth.'
        );
    }

    public function testMacroStateSerializationRoundTripIncludesAllFiveIndicators(): void
    {
        $state = new MacroState();
        $state->manufacturingPmi = 54.2;
        $state->manufacturingPmiEma = 53.8;
        $state->producerPriceInflation = 0.038;
        $state->producerPriceInflationEma = 0.035;
        $state->tradeBalanceToGdp = -0.028;
        $state->tradeBalanceToGdpEma = -0.027;
        $state->housingStartsIndex = 112.5;
        $state->housingStartsIndexEma = 110.0;
        $state->moneySupplyGrowth = 0.068;
        $state->moneySupplyGrowthEma = 0.065;

        $array = $state->toArray();

        $this->assertArrayHasKey('manufacturing_pmi', $array);
        $this->assertArrayHasKey('manufacturing_pmi_ema', $array);
        $this->assertArrayHasKey('producer_price_inflation', $array);
        $this->assertArrayHasKey('producer_price_inflation_ema', $array);
        $this->assertArrayHasKey('trade_balance_to_gdp', $array);
        $this->assertArrayHasKey('trade_balance_to_gdp_ema', $array);
        $this->assertArrayHasKey('housing_starts_index', $array);
        $this->assertArrayHasKey('housing_starts_index_ema', $array);
        $this->assertArrayHasKey('money_supply_growth', $array);
        $this->assertArrayHasKey('money_supply_growth_ema', $array);

        $restored = MacroState::fromArray($array);

        $this->assertEqualsWithDelta(54.2, $restored->manufacturingPmi, 0.0001);
        $this->assertEqualsWithDelta(53.8, $restored->manufacturingPmiEma, 0.0001);
        $this->assertEqualsWithDelta(0.038, $restored->producerPriceInflation, 0.0001);
        $this->assertEqualsWithDelta(0.035, $restored->producerPriceInflationEma, 0.0001);
        $this->assertEqualsWithDelta(-0.028, $restored->tradeBalanceToGdp, 0.0001);
        $this->assertEqualsWithDelta(-0.027, $restored->tradeBalanceToGdpEma, 0.0001);
        $this->assertEqualsWithDelta(112.5, $restored->housingStartsIndex, 0.0001);
        $this->assertEqualsWithDelta(110.0, $restored->housingStartsIndexEma, 0.0001);
        $this->assertEqualsWithDelta(0.068, $restored->moneySupplyGrowth, 0.0001);
        $this->assertEqualsWithDelta(0.065, $restored->moneySupplyGrowthEma, 0.0001);
    }
}
