<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Data\SectorPE;
use App\Data\EconomicCycle;

#[AllowMockObjectsWithoutExpectations]
class MacroEngineTest extends TestCase
{
    private MathUtility&MockObject $mathUtilityMock;
    private \Redis&MockObject $redisMock;
    private MacroEngine $engine;

    protected function setUp(): void
    {
        // Use a partial mock so the actual math methods run, but we can control randomness
        $this->mathUtilityMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal', 'checkProbability'])
            ->getMock();
        $this->redisMock = $this->createMock(\Redis::class);

        // Bypass the constructor to avoid making an actual Redis connection
        $reflection = new \ReflectionClass(MacroEngine::class);
        $this->engine = $reflection->newInstanceWithoutConstructor();

        // Inject our mocked Redis and MathUtility via reflection
        $redisProperty = $reflection->getProperty('redis');
        $redisProperty->setValue($this->engine, $this->redisMock);

        $mathProperty = $reflection->getProperty('mathUtility');
        $mathProperty->setValue($this->engine, $this->mathUtilityMock);
    }

    public function testMacroEngineCanBeInstantiated(): void
    {
        $this->assertInstanceOf(MacroEngine::class, $this->engine);
    }

    public function testUpdateMacroStateInitializesFromEmptyRedis(): void
    {
        // Simulate an empty Redis cache
        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(false);

        // Expect the engine to save the new state to Redis
        $this->redisMock->expects($this->once())
            ->method('set')
            ->with('macroeconomic_state', $this->callback(fn($val) => is_string($val)));

        // Neutral shocks
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(1.0);

        $this->assertInstanceOf(\App\DTO\MacroStateDTO::class, $result);

        // Starting in a 0.02 boom pulls the initial target rate up, raising the policy rate from 0.02 to ~0.04
        $this->assertEqualsWithDelta(0.04, $result->policyRate, 0.01);
        $this->assertEqualsWithDelta(0.02, $result->inflation, 0.01);
        $this->assertGreaterThan(0.0, $result->exchangeRateIndex);
        $this->assertGreaterThan(0.0, $result->industrialMetalsIndex);
        $this->assertGreaterThan(0.0, $result->governmentSpendingIndex);
        $this->assertGreaterThan(0.0, $result->commercialPropertyIndex);
        $this->assertGreaterThan(0.0, $result->residentialPropertyIndex);
        $this->assertGreaterThan(0.0, $result->retailDefaultRate);
        $this->assertGreaterThan(0.0, $result->agriculturalCommodityIndex);
        $this->assertGreaterThan(0.0, $result->freightRateIndex);
    }

    public function testUpdateMacroStateWithExistingStateAndRecessionShock(): void
    {
        $existingState = [
            'inflation' => 0.02,
            'output_gap' => 0.00,
            'policy_rate' => 0.04,
            'inflation_ema' => 0.02,
            'output_gap_ema' => 0.00,
            'policy_rate_ema' => 0.04,
            'corporate_tax_rate' => 0.21,
            'nominal_gdp_index' => 1.0,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($existingState));

        // Force a severe negative shock to output gap and inflation
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(-2.0);

        $result = $this->engine->updateMacroState(0.25); // Advance by 1 quarter

        $this->assertInstanceOf(\App\DTO\MacroStateDTO::class, $result);

        // Output gap should drop below 0 due to the negative shock
        $this->assertLessThan(0.0, $result->outputGap, 'Recession shock should drive output gap negative.');

        // Because output gap is negative, ERP should increase
        $this->assertGreaterThan(MacroEngine::BASE_EQUITY_RISK_PREMIUM, $result->equityRiskPremium, 'ERP should rise during a recession.');
    }

    public function testUpdateMacroStateDuringSevereInflationBoom(): void
    {
        $existingState = [
            'inflation' => 0.08, // Massive 8% inflation
            'output_gap' => 0.05, // 5% positive output gap (overheated)
            'policy_rate' => 0.05,
            'inflation_ema' => 0.07,
            'output_gap_ema' => 0.04,
            'policy_rate_ema' => 0.05,
            'corporate_tax_rate' => 0.21,
            'nominal_gdp_index' => 1.10,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($existingState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(1.0);

        // Advance by a smaller time step (0.05) so the economy has time to hike taxes
        $result = $this->engine->updateMacroState(0.05);

        $this->assertInstanceOf(\App\DTO\MacroStateDTO::class, $result);

        // Central bank should aggressively hike target rates
        $this->assertGreaterThan(0.05, $result->targetRate, 'Central Bank should aggressively hike rates during an inflationary boom.');

        // Corporate tax rate should increase to cool the economy
        $this->assertGreaterThan(MacroEngine::BASE_CORPORATE_TAX_RATE, $result->corporateTaxRate, 'Fiscal policy should hike taxes to cool an overheated economy.');

        // ERP should drop due to complacency in a boom
        $this->assertLessThan(MacroEngine::BASE_EQUITY_RISK_PREMIUM, $result->equityRiskPremium, 'ERP should drop during an economic boom due to market complacency.');
    }

    public function testExchangeRateAppreciatesDuringHighDomesticRates(): void
    {
        $existingState = [
            'policy_rate' => 0.06, // 6% policy rate (well above 2.5% global baseline)
            'policy_rate_ema' => 0.06,
            'exchange_rate_index' => 100.0,
            'exchange_rate_index_ema' => 100.0,
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'output_gap' => 0.0,
            'output_gap_ema' => 0.0,
            'yield5y' => 0.06,
            'yield10y' => 0.065,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($existingState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertGreaterThan(100.0, $result->exchangeRateIndex, 'High domestic policy rate should drive currency appreciation via UIP.');
        $this->assertGreaterThan(100.0, $result->exchangeRateIndexEma);
    }

    public function testExchangeRateDepreciatesDuringLowDomesticRates(): void
    {
        $existingState = [
            'policy_rate' => 0.005, // 0.5% policy rate (well below 2.5% global baseline)
            'policy_rate_ema' => 0.005,
            'exchange_rate_index' => 100.0,
            'exchange_rate_index_ema' => 100.0,
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'output_gap' => 0.0,
            'output_gap_ema' => 0.0,
            'yield5y' => 0.01,
            'yield10y' => 0.02,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($existingState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertLessThan(100.0, $result->exchangeRateIndex, 'Low domestic policy rate should drive currency depreciation via UIP.');
    }

    public function testIndustrialMetalsRiseDuringBoom(): void
    {
        $existingState = [
            'output_gap' => 0.04,
            'output_gap_ema' => 0.04,
            'industrial_metals_index' => 100.0,
            'industrial_metals_index_ema' => 100.0,
            'metals_chi' => 0.0,
            'metals_xi' => 4.60517, // ln(100)
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'policy_rate' => 0.03,
            'policy_rate_ema' => 0.03,
            'yield5y' => 0.035,
            'yield10y' => 0.04,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($existingState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.50);

        $this->assertGreaterThan(100.0, $result->industrialMetalsIndex, 'Positive output gap should drive positive supercycle drift in metals.');
    }

    public function testGovernmentSpendingRisesDuringRecession(): void
    {
        $existingState = [
            'output_gap' => -0.04,
            'output_gap_ema' => -0.04,
            'government_spending_index' => 100.0,
            'government_spending_index_ema' => 100.0,
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'policy_rate' => 0.02,
            'policy_rate_ema' => 0.02,
            'yield5y' => 0.025,
            'yield10y' => 0.03,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($existingState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertGreaterThan(MacroEngine::GOVT_SPENDING_BASELINE, $result->governmentSpendingIndex, 'Recession should trigger countercyclical automatic fiscal stabilizers.');
    }

    public function testGovernmentSpendingFallsDuringBoom(): void
    {
        $existingState = [
            'output_gap' => 0.04,
            'output_gap_ema' => 0.04,
            'government_spending_index' => 100.0,
            'government_spending_index_ema' => 100.0,
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'policy_rate' => 0.04,
            'policy_rate_ema' => 0.04,
            'yield5y' => 0.045,
            'yield10y' => 0.05,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($existingState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertLessThan(MacroEngine::GOVT_SPENDING_BASELINE, $result->governmentSpendingIndex, 'Economic boom should reduce government spending index.');
    }

    public function testCommercialPropertyCollapsesDuringHighRatesAndUnemployment(): void
    {
        $existingState = [
            'unemployment_rate' => 0.08,
            'unemployment_rate_ema' => 0.08,
            'yield10y' => 0.08,
            'yield10y_ema' => 0.08,
            'macro_credit_spread' => 0.05,
            'macro_credit_spread_ema' => 0.05,
            'commercial_property_index' => 100.0,
            'commercial_property_index_ema' => 100.0,
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'output_gap' => -0.05,
            'output_gap_ema' => -0.05,
            'policy_rate' => 0.06,
            'policy_rate_ema' => 0.06,
            'yield5y' => 0.075,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($existingState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertLessThan(100.0, $result->commercialPropertyIndex, 'Elevated cap rates and high unemployment should crush commercial property valuations.');
    }

    public function testRetailDefaultRateSpikesDuringUnemploymentAndInflation(): void
    {
        $existingState = [
            'unemployment_rate' => 0.09,
            'unemployment_rate_ema' => 0.09,
            'inflation' => 0.08,
            'inflation_ema' => 0.08,
            'policy_rate' => 0.06,
            'policy_rate_ema' => 0.06,
            'yield5y' => 0.07,
            'yield10y' => 0.075,
            'output_gap' => -0.04,
            'output_gap_ema' => -0.04,
            'retail_default_rate' => 0.025,
            'retail_default_rate_ema' => 0.025,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($existingState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertGreaterThan(MacroEngine::RETAIL_DEFAULT_BASELINE, $result->retailDefaultRate, 'High unemployment and inflation should spike retail defaults via Vasicek ASRF.');
    }

    public function testAgriculturalCommoditiesFluctuateWithSeasonality(): void
    {
        $existingState = [
            'output_gap' => 0.01,
            'output_gap_ema' => 0.01,
            'agricultural_commodity_index' => 100.0,
            'agricultural_commodity_index_ema' => 100.0,
            'agri_chi' => 0.0,
            'agri_xi' => 4.60517,
            'nominal_gdp_index' => 1.0,
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'policy_rate' => 0.03,
            'policy_rate_ema' => 0.03,
            'yield5y' => 0.04,
            'yield10y' => 0.045,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($existingState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertGreaterThan(0.0, $result->agriculturalCommodityIndex);
        $this->assertGreaterThan(0.0, $result->agriculturalCommodityIndexEma);
    }

    public function testFreightRateIndexMaintainsEquilibriumAtNeutralState(): void
    {
        $neutralState = [
            'output_gap' => 0.0,
            'output_gap_ema' => 0.0,
            'industrial_metals_index' => 100.0,
            'industrial_metals_index_ema' => 100.0,
            'freight_rate_index' => 100.0,
            'freight_rate_index_ema' => 100.0,
            'freight_supply_ema' => 100.0,
            'inflation' => MacroEngine::TARGET_INFLATION,
            'inflation_ema' => MacroEngine::TARGET_INFLATION,
            'policy_rate' => 0.041,
            'policy_rate_ema' => 0.041,
            'yield5y' => 0.05,
            'yield10y' => 0.0564,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($neutralState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertEqualsWithDelta(100.0, $result->freightRateIndex, 1.0, 'Freight rate index should remain at baseline 100 in neutral macro conditions.');
    }

    public function testFreightRateIndexSurgesDuringTradeBoom(): void
    {
        $existingState = [
            'output_gap' => 0.05,
            'output_gap_ema' => 0.05,
            'industrial_metals_index' => 120.0,
            'industrial_metals_index_ema' => 120.0,
            'freight_rate_index' => 100.0,
            'freight_rate_index_ema' => 100.0,
            'freight_supply_ema' => 100.0,
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'policy_rate' => 0.03,
            'policy_rate_ema' => 0.03,
            'yield5y' => 0.04,
            'yield10y' => 0.045,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($existingState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertGreaterThan(MacroEngine::FREIGHT_BASELINE, $result->freightRateIndex, 'Strong output gap and metals demand should push freight rates above baseline.');
    }

    public function testFreightRateIndexDepressedDuringFleetCapacityGlut(): void
    {
        // When fleet capacity exceeds demand (e.g. following post-boom shipbuilding deliveries), utilization drops and rates fall
        $glutState = [
            'output_gap' => -0.02,
            'output_gap_ema' => -0.02,
            'industrial_metals_index' => 90.0,
            'industrial_metals_index_ema' => 90.0,
            'freight_rate_index' => 100.0,
            'freight_rate_index_ema' => 100.0,
            'freight_supply_ema' => 120.0, // Excess fleet capacity
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'policy_rate' => 0.03,
            'policy_rate_ema' => 0.03,
            'yield5y' => 0.04,
            'yield10y' => 0.045,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($glutState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertLessThan(MacroEngine::FREIGHT_BASELINE, $result->freightRateIndex, 'Fleet capacity overhang during trade contraction should depress freight rates.');
    }

    public function testResidentialHousingRespondsToUserCostOfCapital(): void
    {
        // High 30Y mortgage yields should increase user cost and reduce residential valuations
        $existingState = [
            'yield_30y' => 0.09,
            'yield_30y_ema' => 0.09,
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'unemployment_rate' => 0.07,
            'unemployment_rate_ema' => 0.07,
            'residential_property_index' => 100.0,
            'residential_property_index_ema' => 100.0,
            'policy_rate' => 0.06,
            'policy_rate_ema' => 0.06,
            'yield_5y' => 0.07,
            'yield_10y' => 0.08,
            'output_gap' => -0.02,
            'output_gap_ema' => -0.02,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($existingState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertLessThan(100.0, $result->residentialPropertyIndex, 'High mortgage rates and elevated unemployment should depress residential housing index.');
    }

    public function testCommercialPropertyMaintainsEquilibriumAtNeutralState(): void
    {
        $neutralState = [
            'unemployment_rate' => MacroEngine::NATURAL_UNEMPLOYMENT,
            'unemployment_rate_ema' => MacroEngine::NATURAL_UNEMPLOYMENT,
            'yield_10y' => 0.0504,
            'yield_10y_ema' => 0.0504,
            'macro_credit_spread' => MacroEngine::BASE_CREDIT_SPREAD,
            'macro_credit_spread_ema' => MacroEngine::BASE_CREDIT_SPREAD,
            'commercial_property_index' => 100.0,
            'commercial_property_index_ema' => 100.0,
            'inflation' => MacroEngine::TARGET_INFLATION,
            'inflation_ema' => MacroEngine::TARGET_INFLATION,
            'output_gap' => 0.0,
            'output_gap_ema' => 0.0,
            'policy_rate' => 0.035,
            'policy_rate_ema' => 0.035,
            'yield_5y' => 0.044,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($neutralState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertEqualsWithDelta(100.0, $result->commercialPropertyIndex, 0.5, 'Commercial property index should remain at 100 in neutral macro conditions.');
    }

    public function testResidentialPropertyMaintainsEquilibriumAtNeutralState(): void
    {
        $neutralState = [
            'yield_30y' => 0.0548,
            'yield_30y_ema' => 0.0548,
            'inflation' => MacroEngine::TARGET_INFLATION,
            'inflation_ema' => MacroEngine::TARGET_INFLATION,
            'unemployment_rate' => MacroEngine::NATURAL_UNEMPLOYMENT,
            'unemployment_rate_ema' => MacroEngine::NATURAL_UNEMPLOYMENT,
            'residential_property_index' => 100.0,
            'residential_property_index_ema' => 100.0,
            'policy_rate' => 0.035,
            'policy_rate_ema' => 0.035,
            'yield_5y' => 0.044,
            'yield_10y' => 0.0504,
            'output_gap' => 0.0,
            'output_gap_ema' => 0.0,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($neutralState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertEqualsWithDelta(100.0, $result->residentialPropertyIndex, 0.5, 'Residential property index should remain at 100 in neutral macro conditions.');
    }

    public function testResidentialPropertyExpandsAboveBaselineDuringTightLaborMarket(): void
    {
        $boomState = [
            'yield_30y' => 0.0548,
            'yield_30y_ema' => 0.0548,
            'inflation' => MacroEngine::TARGET_INFLATION,
            'inflation_ema' => MacroEngine::TARGET_INFLATION,
            'unemployment_rate' => 0.025,
            'unemployment_rate_ema' => 0.025,
            'residential_property_index' => 100.0,
            'residential_property_index_ema' => 100.0,
            'policy_rate' => 0.035,
            'policy_rate_ema' => 0.035,
            'yield_5y' => 0.044,
            'yield_10y' => 0.0504,
            'output_gap' => 0.02,
            'output_gap_ema' => 0.02,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($boomState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertGreaterThan(100.0, $result->residentialPropertyIndex, 'Tight labor market should expand residential housing index above neutral baseline.');
    }

    public function testEvansRuleLocksTargetRateAtZlbDuringElevatedUnemployment(): void
    {
        // Unemployment > 5.0% and Inflation < 2.5% -> Evans Rule forces target rate to 0% (ZLB)
        $recessionRecoveryState = [
            'unemployment_rate' => 0.055,
            'unemployment_rate_ema' => 0.055,
            'inflation' => 0.020,
            'inflation_ema' => 0.020,
            'output_gap' => -0.010,
            'output_gap_ema' => -0.010,
            'policy_rate' => 0.01,
            'policy_rate_ema' => 0.01,
            'yield_5y' => 0.02,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($recessionRecoveryState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertEquals(0.00, $result->targetRate, 'Evans Rule must lock target rate at 0.00% while unemployment exceeds 5.0% and inflation is below 2.5%.');
    }

    public function testEvansRuleLiftsOffWhenInflationBreachesThreshold(): void
    {
        // Unemployment > 5.0% but Inflation > 2.5% -> Evans Rule allows lift-off to protect price stability
        $stagflationState = [
            'unemployment_rate' => 0.055,
            'unemployment_rate_ema' => 0.055,
            'inflation' => 0.030,
            'inflation_ema' => 0.030,
            'output_gap' => -0.010,
            'output_gap_ema' => -0.010,
            'policy_rate' => 0.02,
            'policy_rate_ema' => 0.02,
            'yield_5y' => 0.04,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($stagflationState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertGreaterThan(0.00, $result->targetRate, 'Target rate must lift off from ZLB when inflation exceeds the 2.5% Evans Rule threshold.');
    }

    public function testEvansRuleLiftsOffWhenUnemploymentNormalizes(): void
    {
        // Unemployment <= 5.0% and normal inflation -> standard Taylor Rule applies
        $normalizedLaborState = [
            'unemployment_rate' => 0.045,
            'unemployment_rate_ema' => 0.045,
            'inflation' => 0.020,
            'inflation_ema' => 0.020,
            'output_gap' => 0.00,
            'output_gap_ema' => 0.00,
            'policy_rate' => 0.03,
            'policy_rate_ema' => 0.03,
            'yield_5y' => 0.04,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($normalizedLaborState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertGreaterThan(0.00, $result->targetRate, 'Target rate must calculate normally when unemployment is <= 5.0%.');
    }

    public function testEvansRuleOverridesTaylorRule(): void
    {
        // When unemployment is > 5.0% and inflation < 2.5%, calculateTargetRate returns exactly 0.00
        $recoveryState = [
            'unemployment_rate' => 0.052,
            'unemployment_rate_ema' => 0.052,
            'inflation' => 0.021,
            'inflation_ema' => 0.021,
            'output_gap' => -0.010,
            'output_gap_ema' => -0.010,
            'policy_rate' => 0.015,
            'policy_rate_ema' => 0.015,
            'yield_5y' => 0.025,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($recoveryState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertSame(0.00, $result->targetRate, 'Evans Rule must override Taylor Rule and return 0.00% target rate when unemployment is above 5.0% and inflation is below 2.5%.');
    }

    public function testEvansRuleUsesTrendUnemploymentRatherThanHighFrequencyNoise(): void
    {
        // Raw unemployment drops below 5.0% due to 1-period noise, but trend (EMA) is still elevated at 5.4%
        $noisyRecoveryState = [
            'unemployment_rate' => 0.048,
            'unemployment_rate_ema' => 0.054,
            'inflation' => 0.020,
            'inflation_ema' => 0.020,
            'output_gap' => -0.010,
            'output_gap_ema' => -0.010,
            'policy_rate' => 0.01,
            'policy_rate_ema' => 0.01,
            'yield_5y' => 0.02,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($noisyRecoveryState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertSame(0.00, $result->targetRate, 'Evans Rule must anchor on trend unemployment (EMA) to avoid premature liftoff from single-period noise.');
    }

    public function testCapitalOverhangDragsOutputGap(): void
    {
        // State with positive capital overhang (excess capacity / boom overbuild)
        $positiveOverhangState = [
            'output_gap' => 0.0,
            'output_gap_ema' => 0.0,
            'capital_stock_overhang' => 0.10,
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'policy_rate' => 0.035,
            'policy_rate_ema' => 0.035,
            'yield_5y' => 0.044,
        ];

        // State with negative capital overhang (depreciation / pent-up replacement demand)
        $negativeOverhangState = [
            'output_gap' => 0.0,
            'output_gap_ema' => 0.0,
            'capital_stock_overhang' => -0.10,
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'policy_rate' => 0.035,
            'policy_rate_ema' => 0.035,
            'yield_5y' => 0.044,
        ];

        $this->redisMock->expects($this->exactly(2))
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturnOnConsecutiveCalls(
                json_encode($positiveOverhangState),
                json_encode($negativeOverhangState)
            );
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $resultPositive = $this->engine->updateMacroState(0.25);
        $resultNegative = $this->engine->updateMacroState(0.25);

        $this->assertLessThan(
            $resultNegative->outputGap,
            $resultPositive->outputGap,
            'Positive capital overhang must exert drag on the output gap compared to negative overhang which provides an autonomous recovery impulse.'
        );
    }

    public function testConsumerSentimentAnchorsAtBaselineInNeutralMacro(): void
    {
        $neutralState = [
            'inflation' => MacroEngine::TARGET_INFLATION,
            'inflation_ema' => MacroEngine::TARGET_INFLATION,
            'unemployment_rate' => MacroEngine::NATURAL_UNEMPLOYMENT,
            'unemployment_rate_ema' => MacroEngine::NATURAL_UNEMPLOYMENT,
            'output_gap' => 0.0,
            'output_gap_ema' => 0.0,
            'policy_rate' => 0.035,
            'policy_rate_ema' => 0.035,
            'yield10y' => 0.0475,
            'yield10y_ema' => 0.0475,
            'market_volatility' => MacroEngine::MACRO_VOL_BASE_ANCHOR,
            'market_volatility_ema' => MacroEngine::MACRO_VOL_BASE_ANCHOR,
            'consumer_sentiment_index' => 100.0,
            'consumer_sentiment_index_ema' => 100.0,
            'energy_price_index' => 100.0,
            'energy_price_index_ema' => 100.0,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($neutralState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertEqualsWithDelta(
            MacroEngine::SENTIMENT_BASELINE,
            $result->consumerSentimentIndex,
            1.0,
            'Consumer sentiment index must remain near 100 in neutral macroeconomic equilibrium.'
        );
    }

    public function testConsumerSentimentDepressedDuringStagflationAndVolatility(): void
    {
        $crisisState = [
            'inflation' => 0.07,
            'inflation_ema' => 0.05,
            'unemployment_rate' => 0.07,
            'unemployment_rate_ema' => 0.05,
            'output_gap' => -0.04,
            'output_gap_ema' => -0.02,
            'policy_rate' => 0.06,
            'policy_rate_ema' => 0.05,
            'yield10y' => 0.07,
            'yield10y_ema' => 0.06,
            'market_volatility' => 0.35,
            'market_volatility_ema' => 0.30,
            'consumer_sentiment_index' => 100.0,
            'consumer_sentiment_index_ema' => 100.0,
            'energy_price_index' => 160.0,
            'energy_price_index_ema' => 140.0,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($crisisState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertLessThan(
            85.0,
            $result->consumerSentimentIndex,
            'Stagflation, surging energy costs, and high market volatility should heavily depress consumer sentiment.'
        );
    }

    public function testModiglianiWealthEffectDragsOutputGap(): void
    {
        // 1. Arrange: Create MathUtility with mocked 0.0 standard normal to eliminate noise
        $mathUtility = $this->createMock(MathUtility::class);
        $mathUtility->method('generateStandardNormal')->willReturn(0.0);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $redis = $this->createMock(\Redis::class);

        $macroEngine = new MacroEngine($mathUtility, $logger, $redis);

        // Create a perfectly neutral baseline state
        $stateNeutral = new \App\Service\Macro\MacroState();
        $stateNeutral->outputGap = 0.0;
        $stateNeutral->inflation = 0.02;
        $stateNeutral->policyRate = 0.035; // Natural rate (1.5%) + Target Inflation (2%)
        $stateNeutral->corporateTaxRate = 0.21;
        $stateNeutral->capitalStockOverhang = 0.0;

        // Neutral Housing Market
        $stateNeutral->residentialPropertyIndexEma = 100.0;

        // Create an identical state, but with a collapsed housing market (20% crash)
        $stateCrash = clone $stateNeutral;
        $stateCrash->residentialPropertyIndexEma = 80.0;

        // Create an identical state, but with a booming housing market (20% surge)
        $stateBoom = clone $stateNeutral;
        $stateBoom->residentialPropertyIndexEma = 120.0;

        // 2. Act: Calculate Output Gap manually using Reflection (or simply call public update Macro if testing full cycle)
        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateOutputGap');

        // Assume 5Y Yield is neutral (naturalRate + targetInflation + NS_BASE_TERM_PREMIUM * durationScale)
        $neutral5yDurationScale = (1.0 - exp(-5.0 / 10.0)) / (1.0 - exp(-1.0));
        $neutral5yYield = MacroEngine::NATURAL_RATE + MacroEngine::TARGET_INFLATION + (MacroEngine::NS_BASE_TERM_PREMIUM * $neutral5yDurationScale);
        $dt = 0.25;
        $stressMultiplier = 1.0;

        $gapNeutral = $reflectionMethod->invoke($macroEngine, $stateNeutral, $neutral5yYield, MacroEngine::NATURAL_RATE, $dt, $stressMultiplier);
        $gapCrash   = $reflectionMethod->invoke($macroEngine, $stateCrash, $neutral5yYield, MacroEngine::NATURAL_RATE, $dt, $stressMultiplier);
        $gapBoom    = $reflectionMethod->invoke($macroEngine, $stateBoom, $neutral5yYield, MacroEngine::NATURAL_RATE, $dt, $stressMultiplier);

        // 3. Assert: 
        // Neutral should not drift
        $this->assertEqualsWithDelta(0.0, $gapNeutral, 0.0001, 'Neutral economy with baseline housing should not drift.');

        // Crash should cause negative drift (Recession)
        // Math: -0.20 * 0.02 = -0.004 drag * 0.25 dt = -0.001
        $this->assertEqualsWithDelta(-0.001, $gapCrash, 0.0001, 'Housing crash must create a negative drag on the output gap.');

        // Boom should cause positive drift (Expansion)
        // Math: +0.20 * 0.02 = +0.004 stimulus * 0.25 dt = +0.001
        $this->assertEqualsWithDelta(0.001, $gapBoom, 0.0001, 'Housing boom must create a positive stimulus on the output gap.');
    }

    public function testInterbankLiquiditySpreadMeanRevertsViaCIR(): void
    {
        $mathUtility = $this->createMock(MathUtility::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $redis = $this->createMock(\Redis::class);

        // Suppress jump diffusion and noise to test pure structural drift
        $mathUtility->method('generateStandardNormal')->willReturn(0.0);
        $mathUtility->method('calculateJumpDiffusion')->willReturn([
            'multiplier' => 1.0,
            'shock_pct' => null,
            'exponent' => null,
        ]);

        // Expect the CIR method to be called with exact constants
        $mathUtility->expects($this->once())
            ->method('calculateCIR')
            ->with(
                0.05, // Current elevated 500 bps spread
                MacroEngine::INTERBANK_SPREAD_KAPPA,
                MacroEngine::INTERBANK_BASELINE_SPREAD,
                MacroEngine::INTERBANK_SPREAD_SIGMA,
                0.25, // dt
                0.0   // dW
            )
            ->willReturn(0.035); // Mock a reversion down to 350 bps

        $macroEngine = new MacroEngine($mathUtility, $logger, $redis);

        $state = new \App\Service\Macro\MacroState();
        $state->interbankLiquiditySpread = 0.05;
        $state->marketVolatilityEma = 0.15; // Neutral VIX

        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateInterbankLiquiditySpread');
        $reflectionMethod->invoke($macroEngine, $state, 0.25);

        $this->assertEquals(0.035, $state->interbankLiquiditySpread, 'Interbank spread must mean-revert using CIR.');
    }

    public function testInterbankLiquiditySpreadBlowsOutDuringMarketPanicJump(): void
    {
        $mathUtility = $this->createMock(MathUtility::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $redis = $this->createMock(\Redis::class);

        $mathUtility->method('generateStandardNormal')->willReturn(0.0);
        // Mock CIR base process staying at 15 bps baseline
        $mathUtility->method('calculateCIR')->willReturn(MacroEngine::INTERBANK_BASELINE_SPREAD);
        // Simulate a 5x blowout jump
        $mathUtility->method('calculateJumpDiffusion')->willReturn([
            'multiplier' => 5.0,
            'shock_pct' => 400.0,
            'exponent' => 1.60,
        ]);

        $macroEngine = new MacroEngine($mathUtility, $logger, $redis);

        $state = new \App\Service\Macro\MacroState();
        $state->interbankLiquiditySpread = MacroEngine::INTERBANK_BASELINE_SPREAD;
        $state->marketVolatilityEma = 0.35; // Panicked VIX

        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateInterbankLiquiditySpread');
        $reflectionMethod->invoke($macroEngine, $state, 0.25);

        // Expected: 0.0015 + (0.0015 * (5.0 - 1.0)) = 0.0015 * 5.0 = 0.0075 (75 bps)
        $this->assertEqualsWithDelta(0.0075, $state->interbankLiquiditySpread, 0.0001, 'Interbank spread must blow out on Poisson jump.');
    }

    public function testCapitalStockOverhangAccumulatesDuringBoomAndDecaysDuringRecession(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $state = new \App\Service\Macro\MacroState();
        $state->outputGap = 0.05; // 5% Boom
        $state->capitalStockOverhang = 0.0;

        $recessionState = new \App\Service\Macro\MacroState();
        $recessionState->outputGap = -0.05;
        $recessionState->capitalStockOverhang = 0.05;

        $this->redisMock->method('get')->willReturnOnConsecutiveCalls(
            json_encode($state->toArray()),
            json_encode($recessionState->toArray())
        );

        // Run 1 tick of boom ($dt = 0.25 years / 1 quarter)
        $dtoBoom = $this->engine->updateMacroState(0.25);

        // Boom should accumulate capital stock overhang: dK = (y * ACCUMULATION_RATE - DECAY_RATE * K) * dt
        $expectedBoomOverhang = (0.05 * MacroEngine::CAPITAL_ACCUMULATION_RATE - MacroEngine::CAPITAL_DECAY_RATE * 0.0) * 0.25;
        $this->assertGreaterThan(0.0, $dtoBoom->capitalStockOverhang, 'Capital stock overhang must accumulate during economic booms.');
        $this->assertEqualsWithDelta($expectedBoomOverhang, $dtoBoom->capitalStockOverhang, 0.0001);

        // Run 1 quarter of recession
        $dtoRecession = $this->engine->updateMacroState(0.25);

        // Recession & decay: dK = (y * ACCUMULATION_RATE - DECAY_RATE * K) * dt
        $expectedRecessionOverhang = 0.05 + ((-0.05 * MacroEngine::CAPITAL_ACCUMULATION_RATE - MacroEngine::CAPITAL_DECAY_RATE * 0.05) * 0.25);
        $this->assertLessThan(0.05, $dtoRecession->capitalStockOverhang, 'Capital stock overhang must decay during recessions.');
        $this->assertEqualsWithDelta($expectedRecessionOverhang, $dtoRecession->capitalStockOverhang, 0.0001);
    }

    public function testSolowSwanSmoothPotentialGdpGrowth(): void
    {
        $mathUtility = $this->createMock(MathUtility::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $redis = $this->createMock(\Redis::class);

        $mathUtility->method('generateStandardNormal')->willReturn(0.0);

        $macroEngine = new MacroEngine($mathUtility, $logger, $redis);

        $state = new \App\Service\Macro\MacroState();
        $state->totalFactorProductivityIndex = 100.0;
        $state->potentialGdpIndex = 1.0;
        $state->nominalGdpIndex = 1.0;
        $state->outputGap = 0.0;
        $state->outputGapEma = 0.0;
        $state->inflation = 0.02;
        $state->inflationEma = 0.02;

        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculatePotentialAndNominalGdp');
        $reflectionMethod->invoke($macroEngine, $state, 0.25);

        // Expected TFP: 100 * exp(0.015 * 0.25) ~= 100.3757
        $expectedTfp = 100.0 * exp(MacroEngine::TFP_DRIFT * 0.25);
        $this->assertEqualsWithDelta($expectedTfp, $state->totalFactorProductivityIndex, 0.0001, 'TFP Index must compound with continuous secular drift.');

        // Expected Potential GDP: 1.0 * exp((0.005 + 0.015) * 0.25) ~= 1.00501
        $expectedPotential = 1.0 * exp((MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + MacroEngine::TFP_DRIFT) * 0.25);
        $this->assertEqualsWithDelta($expectedPotential, $state->potentialGdpIndex, 0.0001, 'Potential GDP must grow by real labor + TFP growth.');

        // Nominal GDP compounds Real Potential Growth + Inflation Deflator:
        $expectedNominal = 1.0 * exp((MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + MacroEngine::TFP_DRIFT + 0.02) * 0.25);
        $this->assertEqualsWithDelta($expectedNominal, $state->nominalGdpIndex, 0.0001, 'Nominal GDP must accumulate real capacity growth and inflation deflator.');
    }

    public function testSolowSwanOutputGapImpactsNominalGdp(): void
    {
        $mathUtility = $this->createMock(MathUtility::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $redis = $this->createMock(\Redis::class);

        $mathUtility->method('generateStandardNormal')->willReturn(0.0);

        $macroEngine = new MacroEngine($mathUtility, $logger, $redis);

        $state = new \App\Service\Macro\MacroState();
        $state->totalFactorProductivityIndex = 100.0;
        $state->potentialGdpIndex = 1.0;
        $state->nominalGdpIndex = 1.0;
        $state->outputGap = 0.05; // 5% positive output gap (economic boom)
        $state->outputGapEma = 0.0;
        $state->inflation = 0.02;
        $state->inflationEma = 0.02;

        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculatePotentialAndNominalGdp');
        $reflectionMethod->invoke($macroEngine, $state, 0.25);

        $expectedPotential = 1.0 * exp((MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + MacroEngine::TFP_DRIFT) * 0.25);
        $expectedNominal = 1.0 * exp((MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + MacroEngine::TFP_DRIFT + 0.02) * 0.25) * 1.05;

        $this->assertEqualsWithDelta($expectedPotential, $state->potentialGdpIndex, 0.0001);
        $this->assertEqualsWithDelta($expectedNominal, $state->nominalGdpIndex, 0.0001, 'Nominal GDP must scale with cyclical output gap and inflation.');
    }

    public function testSolowSwanTfpCanContractDuringSevereRecession(): void
    {
        $mathUtility = $this->createMock(MathUtility::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $redis = $this->createMock(\Redis::class);

        // Severe negative productivity shock (e.g. supply chain disruption)
        $mathUtility->method('generateStandardNormal')->willReturn(-3.0);

        $macroEngine = new MacroEngine($mathUtility, $logger, $redis);

        $state = new \App\Service\Macro\MacroState();
        $state->totalFactorProductivityIndex = 120.0;
        $state->outputGapEma = -0.06; // Deep 6% economic recession

        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateTotalFactorProductivity');
        $reflectionMethod->invoke($macroEngine, $state, 0.25);

        $this->assertLessThan(120.0, $state->totalFactorProductivityIndex, 'TFP index can and should contract during deep recessions with negative innovation shocks.');
        $minAllowedTfp = 120.0 * exp(MacroEngine::MIN_TFP_GROWTH_RATE * 0.25);
        $this->assertGreaterThanOrEqual($minAllowedTfp, $state->totalFactorProductivityIndex, 'TFP contraction must be bounded by structural MIN_TFP_GROWTH_RATE floor.');
    }

    public function testExchangeRateAppreciationDragsDownOutputGap(): void
    {
        $stateStrongFx = new \App\Service\Macro\MacroState();
        $stateStrongFx->exchangeRateIndexEma = 120.0; // 20% appreciation

        $stateNeutralFx = new \App\Service\Macro\MacroState();
        $stateNeutralFx->exchangeRateIndexEma = 100.0; // Neutral

        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateOutputGap');
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $gapStrongFx = $reflectionMethod->invoke($this->engine, $stateStrongFx, 0.05, 0.02, 0.25, 1.0);
        $gapNeutralFx = $reflectionMethod->invoke($this->engine, $stateNeutralFx, 0.05, 0.02, 0.25, 1.0);

        $this->assertLessThan($gapNeutralFx, $gapStrongFx, 'Marshall-Lerner condition: Strong FX must drag down output gap.');
    }

    public function testInterbankContagionWidensMacroCreditSpread(): void
    {
        $stateStressed = new \App\Service\Macro\MacroState();
        $stateStressed->outputGapEma = 0.0;
        $stateStressed->marketVolatilityEma = 0.15;
        $stateStressed->interbankLiquiditySpreadEma = 0.03; // 300 bps interbank stress

        $stateNeutral = new \App\Service\Macro\MacroState();
        $stateNeutral->outputGapEma = 0.0;
        $stateNeutral->marketVolatilityEma = 0.15;
        $stateNeutral->interbankLiquiditySpreadEma = 0.0025; // Normal

        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateMacroCreditSpread');

        $reflectionMethod->invoke($this->engine, $stateStressed);
        $reflectionMethod->invoke($this->engine, $stateNeutral);

        $this->assertGreaterThan($stateNeutral->macroCreditSpread, $stateStressed->macroCreditSpread, 'Interbank stress must contagion into corporate credit spreads.');
        $this->assertEqualsWithDelta(
            $stateNeutral->macroCreditSpread + ((0.03 - 0.0025) * MacroEngine::INTERBANK_CREDIT_CONTAGION_SENSITIVITY),
            $stateStressed->macroCreditSpread,
            0.0001
        );
    }

    public function testSustainedInflationElevatesExpectations(): void
    {
        $stateHighEma = new \App\Service\Macro\MacroState();
        $stateHighEma->inflation = 0.04;
        $stateHighEma->inflationEma = 0.06; // Persistently high
        $stateHighEma->outputGap = 0.0;

        $stateAnchored = new \App\Service\Macro\MacroState();
        $stateAnchored->inflation = 0.04;
        $stateAnchored->inflationEma = 0.02; // Firmly anchored
        $stateAnchored->outputGap = 0.0;

        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateInflation');
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $infHigh = $reflectionMethod->invoke($this->engine, $stateHighEma, 0.02, 1.0, 0.25);
        $infAnchored = $reflectionMethod->invoke($this->engine, $stateAnchored, 0.02, 1.0, 0.25);

        $this->assertGreaterThan($infAnchored, $infHigh, 'Un-anchored expectations must result in higher inflation drift.');
    }

    public function testTipsBreakevenInflationExpectationsResponseToOutputGapAndVolatility(): void
    {
        $stateOverheated = new \App\Service\Macro\MacroState();
        $stateOverheated->outputGapEma = 0.04;
        $stateOverheated->inflationEma = 0.04;
        $stateOverheated->marketVolatilityEma = 0.25;

        $stateNeutral = new \App\Service\Macro\MacroState();
        $stateNeutral->outputGapEma = 0.00;
        $stateNeutral->inflationEma = 0.02;
        $stateNeutral->marketVolatilityEma = 0.15;

        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateTipsBreakeven');

        $breakevenOverheated = $reflectionMethod->invoke($this->engine, $stateOverheated, MacroEngine::TARGET_INFLATION, 0.25);
        $breakevenNeutral = $reflectionMethod->invoke($this->engine, $stateNeutral, MacroEngine::TARGET_INFLATION, 0.25);

        $this->assertEqualsWithDelta(MacroEngine::TARGET_INFLATION, $breakevenNeutral, 0.0001, 'Neutral state must produce 2.0% TIPS breakeven expectation.');
        $this->assertGreaterThan($breakevenNeutral, $breakevenOverheated, 'Overheated economy and elevated volatility must drive forward TIPS breakeven inflation above neutral.');
    }

    public function testSvenssonYieldCurveSecondaryCurvatureFiscalSupply(): void
    {
        $stateHighDeficit = new \App\Service\Macro\MacroState();
        $stateHighDeficit->policyRate = 0.03;
        $stateHighDeficit->tipsBreakeven = 0.02;
        $stateHighDeficit->governmentSpendingIndexEma = 150.0; // 50% spending surge (deficit)
        $stateHighDeficit->outputGap = 0.0;

        $stateNeutralFiscal = new \App\Service\Macro\MacroState();
        $stateNeutralFiscal->policyRate = 0.03;
        $stateNeutralFiscal->tipsBreakeven = 0.02;
        $stateNeutralFiscal->governmentSpendingIndexEma = 100.0; // Neutral baseline
        $stateNeutralFiscal->outputGap = 0.0;

        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateYieldCurveAndQE');

        $yieldDataHighDeficit = $reflectionMethod->invoke($this->engine, $stateHighDeficit, MacroEngine::TARGET_INFLATION, MacroEngine::NATURAL_RATE, 0.25);
        $yieldDataNeutral = $reflectionMethod->invoke($this->engine, $stateNeutralFiscal, MacroEngine::TARGET_INFLATION, MacroEngine::NATURAL_RATE, 0.25);

        $this->assertGreaterThan($yieldDataNeutral['curvature2'], $yieldDataHighDeficit['curvature2'], 'Secondary Svensson curvature beta3 must increase with fiscal debt issuance.');
        $this->assertGreaterThan($yieldDataNeutral['yield_30y'], $yieldDataHighDeficit['yield_30y'], 'Long-end 30Y Treasury yield should steepen under fiscal supply expansion.');
    }

    public function testEnergyCostPushDistributedLagStickiness(): void
    {
        $state = new \App\Service\Macro\MacroState();
        $state->inflation = 0.02;
        $state->inflationEma = 0.02;
        $state->outputGap = 0.0;
        $state->energyPriceShock = 50.0; // +50% energy price spike
        $state->energyCostPushLag = 0.0;

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateInflation');

        // Quarter 1 step
        $infQ1 = $reflectionMethod->invoke($this->engine, $state, MacroEngine::TARGET_INFLATION, 1.0, 0.25);
        $lagQ1 = $state->energyCostPushLag;

        // The energy lag should have partially transmitted, but not fully reached raw transmission
        $rawTransmission = (50.0 / 100.0) * MacroEngine::ENERGY_COST_PUSH_TRANSMISSION;
        $this->assertGreaterThan(0.0, $lagQ1, 'Energy shock should immediately start transmitting through lag filter.');
        $this->assertLessThan($rawTransmission, $lagQ1, 'Energy shock should be sticky and not instantly transmit at 100% in first quarter.');

        // Advance to Quarter 2 with persistent shock
        $infQ2 = $reflectionMethod->invoke($this->engine, $state, MacroEngine::TARGET_INFLATION, 1.0, 0.25);
        $lagQ2 = $state->energyCostPushLag;

        $this->assertGreaterThan($lagQ1, $lagQ2, 'Sticky cost lag should accumulate and rise across successive quarters of elevated energy.');
    }

    public function testAsymmetricInterestRateSmoothingPacing(): void
    {
        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'updatePolicyRate');

        // 1. Hiking scenario (target 5.0%, current 2.0%, inflation benign at 2.0%)
        $hikingState = new \App\Service\Macro\MacroState();
        $hikingState->policyRate = 0.02;
        $hikingState->inflation = 0.02;
        $hikingState->outputGap = 0.02;

        $targetRateHike = 0.05; // +300 bps gap
        $dt = 0.25; // 1 quarter

        $newHikedRate = $reflectionMethod->invoke($this->engine, $hikingState, $targetRateHike, $dt);
        $quarterlyHike = $newHikedRate - $hikingState->policyRate;

        // 2. Cutting scenario (target 1.0%, current 4.0%, recession gap -3%)
        $cuttingState = new \App\Service\Macro\MacroState();
        $cuttingState->policyRate = 0.04;
        $cuttingState->inflation = 0.015;
        $cuttingState->outputGap = -0.03;

        $targetRateCut = 0.01; // -300 bps gap

        $newCutRate = $reflectionMethod->invoke($this->engine, $cuttingState, $targetRateCut, $dt);
        $quarterlyCut = $cuttingState->policyRate - $newCutRate;

        // Rate cut should be significantly swifter than rate hike for an identical 300 bps gap
        $this->assertGreaterThan($quarterlyHike, $quarterlyCut, 'Central bank must ease rates faster during recession than it hikes during expansion (asymmetric smoothing).');
    }

    public function testRateHikesDuringNormalExpansionDoNotExceedNormalVelocity(): void
    {
        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'updatePolicyRate');

        $expansionState = new \App\Service\Macro\MacroState();
        $expansionState->policyRate = 0.01;
        $expansionState->inflation = 0.025; // Mild healthy expansion inflation (below 3.5% panic threshold)
        $expansionState->outputGap = 0.03;

        $highTargetRate = 0.08; // High target
        $dt = 1.0; // 1 full year

        $newRate = $reflectionMethod->invoke($this->engine, $expansionState, $highTargetRate, $dt);
        $annualHike = $newRate - $expansionState->policyRate;

        // Annual hike during normal expansion must be bounded by CB_MAX_NORMAL_HIKE_VELOCITY (200 bps/year)
        $this->assertLessThanOrEqual(MacroEngine::CB_MAX_NORMAL_HIKE_VELOCITY + 0.0001, $annualHike, 'Normal expansion rate hike velocity must not exceed 200 bps/year.');
    }

    public function testInflationPanicAcceleratesHikesAboveEmergencyThreshold(): void
    {
        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'updatePolicyRate');

        // Severe stagflation / runaway inflation shock (8.0% inflation)
        $panicState = new \App\Service\Macro\MacroState();
        $panicState->policyRate = 0.02;
        $panicState->inflation = 0.080;
        $panicState->outputGap = 0.03;

        $highTargetRate = 0.12;
        $dt = 1.0; // 1 full year

        $newPanicRate = $reflectionMethod->invoke($this->engine, $panicState, $highTargetRate, $dt);
        $annualPanicHike = $newPanicRate - $panicState->policyRate;

        // In panic mode, rate hike velocity should exceed normal 200 bps cap up to panic cap (400 bps/year)
        $this->assertGreaterThan(MacroEngine::CB_MAX_NORMAL_HIKE_VELOCITY, $annualPanicHike, 'Severe runaway inflation must trigger emergency panic rate hiking acceleration.');
        $this->assertLessThanOrEqual(MacroEngine::CB_MAX_PANIC_HIKE_VELOCITY + 0.0001, $annualPanicHike, 'Panic rate hiking must remain bounded by CB_MAX_PANIC_HIKE_VELOCITY.');
    }

    public function testDynamicNaturalRateDriftsWithTfpGrowth(): void
    {
        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateNaturalRate');

        $state = new \App\Service\Macro\MacroState();
        $state->naturalRate = MacroEngine::BASE_NATURAL_RATE; // 1.5%

        // 1. Accelerating TFP growth (3.5% vs baseline 1.5%) -> r* should drift upward
        $acceleratingTfp = 0.035;
        $dt = 1.0;
        $reflectionMethod->invoke($this->engine, $state, $acceleratingTfp, $dt);

        $this->assertGreaterThan(MacroEngine::BASE_NATURAL_RATE, $state->naturalRate, 'Natural real rate r* must drift upward when TFP productivity growth accelerates.');
        $this->assertLessThanOrEqual(MacroEngine::MAX_NATURAL_RATE, $state->naturalRate, 'Natural real rate must respect statutory upper bound.');

        // 2. Depressed TFP growth (0.0% secular stagnation) -> r* should drift downward
        $stagnationState = new \App\Service\Macro\MacroState();
        $stagnationState->naturalRate = MacroEngine::BASE_NATURAL_RATE;
        $reflectionMethod->invoke($this->engine, $stagnationState, 0.00, $dt);

        $this->assertLessThan(MacroEngine::BASE_NATURAL_RATE, $stagnationState->naturalRate, 'Natural real rate r* must drift downward during secular productivity stagnation.');
        $this->assertGreaterThanOrEqual(MacroEngine::MIN_NATURAL_RATE, $stagnationState->naturalRate, 'Natural real rate must respect statutory floor.');
    }

    public function testQuantitativeTighteningActivatesDuringOverheating(): void
    {
        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateYieldCurveAndQE');

        // Overheating expansion: output gap +2.5% (> 1.0%), inflation 3.5% (> 2.5%)
        $overheatingState = new \App\Service\Macro\MacroState();
        $overheatingState->outputGap = 0.025;
        $overheatingState->inflation = 0.035;
        $overheatingState->policyRate = 0.035;
        $overheatingState->tipsBreakeven = 0.030;
        $overheatingState->balanceSheetIntensity = 0.0;

        $yieldData = $reflectionMethod->invoke($this->engine, $overheatingState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 1.0);

        // Balance sheet intensity should be negative (QT active)
        $this->assertLessThan(0.0, $yieldData['new_balance_sheet_intensity'], 'Central bank balance sheet intensity must turn negative (QT) during economic overheating.');
        $this->assertGreaterThan(0.0, $yieldData['new_qt_intensity'], 'QT intensity must be positive during overheating.');
        $this->assertEquals(0.0, $yieldData['new_qe_intensity'], 'QE asset purchases must be inactive during overheating.');
    }

    public function testACMTermPremiumDecompositionIntegrity(): void
    {
        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateYieldCurveAndQE');

        $state = new \App\Service\Macro\MacroState();
        $state->outputGap = 0.01;
        $state->inflation = 0.02;
        $state->policyRate = 0.025;
        $state->tipsBreakeven = 0.02;

        $yieldData = $reflectionMethod->invoke($this->engine, $state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        $yield10y = $yieldData['yield_10y'];
        $riskNeutral10y = $yieldData['risk_neutral_10y'];
        $termPremium10y = $yieldData['term_premium_10y'];

        // Identity check: 10Y Yield == Risk Neutral Path + Term Premium
        $this->assertEqualsWithDelta($yield10y, $riskNeutral10y + $termPremium10y, 0.0001, 'ACM decomposition identity must hold: y10 = RN10 + TP10.');
    }

    public function testForwardLookingBetaCurvatureLeadsPolicyRate(): void
    {
        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateYieldCurveAndQE');

        // 1. Hiking cycle expectation: policyRate = 2.5%, but Taylor targetRate = 5.0%
        $hikingState = new \App\Service\Macro\MacroState();
        $hikingState->policyRate = 0.025;
        $hikingState->targetRate = 0.050; // +250 bps hike signaled
        $hikingState->outputGap = 0.0;
        $hikingState->tipsBreakeven = 0.02;

        // 2. Neutral policy expectation: policyRate = 2.5%, targetRate = 2.5%
        $neutralState = new \App\Service\Macro\MacroState();
        $neutralState->policyRate = 0.025;
        $neutralState->targetRate = 0.025;
        $neutralState->outputGap = 0.0;
        $neutralState->tipsBreakeven = 0.02;

        // 3. Easing cycle expectation: policyRate = 4.0%, targetRate = 1.5% (crisis cuts)
        $easingState = new \App\Service\Macro\MacroState();
        $easingState->policyRate = 0.040;
        $easingState->targetRate = 0.015; // -250 bps cut signaled
        $easingState->outputGap = 0.0;
        $easingState->tipsBreakeven = 0.02;

        $yieldDataHiking = $reflectionMethod->invoke($this->engine, $hikingState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $yieldDataNeutral = $reflectionMethod->invoke($this->engine, $neutralState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $yieldDataEasing = $reflectionMethod->invoke($this->engine, $easingState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        // β₂ (curvature) must hump upward during a hiking cycle and dip during an easing cycle
        $this->assertGreaterThan($yieldDataNeutral['curvature'], $yieldDataHiking['curvature'], 'Curvature beta2 must rise during a central bank rate hike cycle.');
        $this->assertLessThan(0.0, $yieldDataEasing['curvature'], 'Curvature beta2 must turn negative during a central bank emergency cutting cycle.');

        // 2Y yield must price in expected hikes ahead of policy rate moves
        $this->assertGreaterThan($yieldDataNeutral['yield_2y'], $yieldDataHiking['yield_2y'], '2Y sovereign yield must rise ahead of central bank rate hikes.');
    }

    public function testInflationRiskPremiumExpandsTermPremiumWhenBreakevenElevated(): void
    {
        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateYieldCurveAndQE');

        // Anchored neutral state: TIPS breakeven = 2.0% (target), macro vol = 15%
        $stateAnchored = new \App\Service\Macro\MacroState();
        $stateAnchored->policyRate = 0.03;
        $stateAnchored->targetRate = 0.03;
        $stateAnchored->tipsBreakeven = 0.02;
        $stateAnchored->marketVolatilityEma = 0.15;
        $stateAnchored->outputGap = 0.0;

        // Un-anchored high-inflation state: TIPS breakeven = 4.0%, elevated macro vol = 28%
        $stateUnanchored = new \App\Service\Macro\MacroState();
        $stateUnanchored->policyRate = 0.03;
        $stateUnanchored->targetRate = 0.03;
        $stateUnanchored->tipsBreakeven = 0.04;
        $stateUnanchored->marketVolatilityEma = 0.28;
        $stateUnanchored->outputGap = 0.0;

        $yieldDataAnchored = $reflectionMethod->invoke($this->engine, $stateAnchored, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $yieldDataUnanchored = $reflectionMethod->invoke($this->engine, $stateUnanchored, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        // Term premium must expand when inflation expectations un-anchor and volatility spikes (Wright 2011 IRP)
        $this->assertGreaterThan(
            $yieldDataAnchored['term_premium_10y'],
            $yieldDataUnanchored['term_premium_10y'],
            '10Y term premium must expand when inflation breakeven exceeds target and macro volatility is elevated.'
        );
    }

    public function testFlightToSafetyCompressesTermPremiumDuringRecession(): void
    {
        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateYieldCurveAndQE');

        // Neutral state: outputGap = 0.0
        $stateNeutral = new \App\Service\Macro\MacroState();
        $stateNeutral->policyRate = 0.03;
        $stateNeutral->targetRate = 0.03;
        $stateNeutral->tipsBreakeven = 0.02;
        $stateNeutral->marketVolatilityEma = 0.15;
        $stateNeutral->outputGap = 0.0;

        // Deep recession: outputGap = -0.05 (-5% GDP gap)
        $stateRecession = new \App\Service\Macro\MacroState();
        $stateRecession->policyRate = 0.03;
        $stateRecession->targetRate = 0.03;
        $stateRecession->tipsBreakeven = 0.02;
        $stateRecession->marketVolatilityEma = 0.15;
        $stateRecession->outputGap = -0.05;

        $yieldDataNeutral = $reflectionMethod->invoke($this->engine, $stateNeutral, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $yieldDataRecession = $reflectionMethod->invoke($this->engine, $stateRecession, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        // Flight-to-safety: recessions compress sovereign term premium as investors seek safe duration
        $this->assertLessThan(
            $yieldDataNeutral['term_premium_10y'],
            $yieldDataRecession['term_premium_10y'],
            'Recessions must compress duration term premium via flight-to-safety demand (Campbell et al. 2017).'
        );
    }

    public function testBeveridgeCurveWagePhillipsTransmission(): void
    {
        $reflectionLabor = new \ReflectionMethod(MacroEngine::class, 'calculateLaborMarketAndWages');
        $reflectionInflation = new \ReflectionMethod(MacroEngine::class, 'calculateInflation');

        // Tight labor market scenario: unemployment 2.5% (< 4.0% NAIRU)
        $tightState = new \App\Service\Macro\MacroState();
        $tightState->unemploymentRate = 0.025;
        $tightState->wageGrowth = 0.035;

        $reflectionLabor->invoke($this->engine, $tightState, MacroEngine::TFP_DRIFT, 0.5);

        // Job vacancies must rise via Beveridge curve (k / U) and labor tightness must exceed 1.125
        $this->assertGreaterThan(MacroEngine::NATURAL_JOB_VACANCIES, $tightState->jobVacanciesRate, 'Job vacancies rate must rise when unemployment drops below NAIRU.');
        $this->assertGreaterThan(MacroEngine::NATURAL_LABOR_TIGHTNESS, $tightState->laborTightness, 'Labor market tightness (V/U) must exceed equilibrium in a labor shortage.');
        $this->assertGreaterThan(0.035, $tightState->wageGrowth, 'Wage growth must accelerate when the labor market is tight.');

        // Inflation pass-through check
        $tightState->outputGap = 0.01;
        $newInflation = $reflectionInflation->invoke($this->engine, $tightState, MacroEngine::TARGET_INFLATION, 1.0, 0.25);
        $this->assertGreaterThan(0.020, $newInflation, 'Excess wage growth must pass through into headline services inflation.');
    }

    public function testStochasticTfpDiffusionAndEndogenousSpillover(): void
    {
        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateTotalFactorProductivity');

        // 1. Positive innovation shock + economic boom -> accelerated TFP growth
        $boomState = new \App\Service\Macro\MacroState();
        $boomState->totalFactorProductivityIndex = 100.0;
        $boomState->outputGapEma = 0.03; // 3% boom

        $this->mathUtilityMock->method('generateStandardNormal')->willReturnOnConsecutiveCalls(1.5, -3.0);
        $reflectionMethod->invoke($this->engine, $boomState, 0.25);

        $baselineDeterministicTfp = 100.0 * exp(MacroEngine::TFP_DRIFT * 0.25);
        $this->assertGreaterThan($baselineDeterministicTfp, $boomState->totalFactorProductivityIndex, 'Positive innovation shock and expansion must accelerate TFP accumulation.');

        // 2. Severe recession + negative productivity shock leads to contraction bounded by MIN_TFP_GROWTH_RATE
        $slumpState = new \App\Service\Macro\MacroState();
        $slumpState->totalFactorProductivityIndex = 100.0;
        $slumpState->outputGapEma = -0.05; // 5% recession

        $reflectionMethod->invoke($this->engine, $slumpState, 0.25);

        $this->assertLessThan(100.0, $slumpState->totalFactorProductivityIndex, 'TFP can and should contract during severe recessions with negative shocks.');
        $minAllowedTfp = 100.0 * exp(MacroEngine::MIN_TFP_GROWTH_RATE * 0.25);
        $this->assertGreaterThanOrEqual($minAllowedTfp, $slumpState->totalFactorProductivityIndex, 'TFP contraction must be bounded by structural MIN_TFP_GROWTH_RATE.');
    }

    public function testNaturalRateEvolvesDynamicallyDuringFullMacroUpdate(): void
    {
        $existingState = [
            'inflation' => 0.02,
            'output_gap' => 0.03,
            'output_gap_ema' => 0.03,
            'natural_rate' => MacroEngine::BASE_NATURAL_RATE,
            'natural_rate_ema' => MacroEngine::BASE_NATURAL_RATE,
            'total_factor_productivity_index' => 100.0,
        ];

        $this->redisMock->method('get')->willReturn(json_encode($existingState));
        $this->redisMock->method('set')->willReturn(true);

        // Positive technology wave
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(2.0);

        $result = $this->engine->updateMacroState(0.5);

        $this->assertNotEquals(MacroEngine::BASE_NATURAL_RATE, $result->naturalRate, 'Natural real rate r* must move dynamically and not remain static.');
        $this->assertGreaterThan(MacroEngine::BASE_NATURAL_RATE, $result->naturalRate, 'Natural rate must rise during a technology/productivity expansion.');
    }

    public function testSymmetricWagePushAndWageDragInflationTransmission(): void
    {
        $reflectionInflation = new \ReflectionMethod(MacroEngine::class, 'calculateInflation');

        // Zero standard normal shocks to isolate deterministic wage transmission
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        // 1. Overheating wage scenario (w = 4.5% > 3.5% trend)
        $hotWageState = new \App\Service\Macro\MacroState();
        $hotWageState->inflation = 0.02;
        $hotWageState->inflationEma = 0.02;
        $hotWageState->outputGap = 0.0;
        $hotWageState->wageGrowth = 0.045; // +1.0% excess wage growth
        $hotWageState->energyPriceShock = 0.0;
        $hotWageState->energyCostPushLag = 0.0;

        $hotInflation = $reflectionInflation->invoke($this->engine, $hotWageState, MacroEngine::TARGET_INFLATION, 1.0, 0.25);
        $this->assertGreaterThan(0.02, $hotInflation, 'Excess wage growth above 3.5% must exert positive cost-push inflation pressure.');

        // 2. Slack wage scenario (w = 2.5% < 3.5% trend)
        $slackWageState = new \App\Service\Macro\MacroState();
        $slackWageState->inflation = 0.02;
        $slackWageState->inflationEma = 0.02;
        $slackWageState->outputGap = 0.0;
        $slackWageState->wageGrowth = 0.025; // -1.0% slack wage growth
        $slackWageState->energyPriceShock = 0.0;
        $slackWageState->energyCostPushLag = 0.0;

        $slackInflation = $reflectionInflation->invoke($this->engine, $slackWageState, MacroEngine::TARGET_INFLATION, 1.0, 0.25);
        $this->assertLessThan(0.02, $slackInflation, 'Slack wage growth below 3.5% must exert symmetric disinflationary wage-drag.');

        // 3. Check symmetry of transmission magnitude around 2.0%
        $hotDelta = $hotInflation - 0.02;
        $slackDelta = 0.02 - $slackInflation;
        $this->assertEqualsWithDelta($hotDelta, $slackDelta, 0.0001, 'Wage inflation transmission must be symmetric for equal deviations above and below trend.');
    }

    public function testTfpTrendGrowthRatePassesCleanlyWithoutDiffusionNoise(): void
    {
        $reflectionTfp = new \ReflectionMethod(MacroEngine::class, 'calculateTotalFactorProductivity');

        // Neutral state: output gap = 0, no R&D acceleration
        $neutralState = new \App\Service\Macro\MacroState();
        $neutralState->totalFactorProductivityIndex = 100.0;
        $neutralState->outputGapEma = 0.0;

        // Severe negative diffusion shock
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(-3.0);

        // Calculate TFP with dt = 1/3600 (tick-level)
        $dt = 1.0 / 3600.0;
        $trendRate = $reflectionTfp->invoke($this->engine, $neutralState, $dt);

        // The trend growth rate returned for economic models must be the true structural drift (1.5%), unaffected by tick diffusion
        $this->assertEqualsWithDelta(MacroEngine::TFP_DRIFT, $trendRate, 0.0001, 'TFP trend growth rate must equal structural secular drift at neutral output gap.');
    }

    public function testYieldCurveInvertsAtPeakOfTighteningCycleAndSteepensDuringEasing(): void
    {
        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateYieldCurveAndQE');

        // 1. Peak tightening cycle: policyRate = 5.25%, targetRate = 5.25%, outputGap = +2.5%, elevated inflation
        $peakState = new \App\Service\Macro\MacroState();
        $peakState->policyRate = 0.0525;
        $peakState->targetRate = 0.0525;
        $peakState->outputGap = 0.025;
        $peakState->inflation = 0.035;
        $peakState->inflationEma = 0.035;
        $peakState->tipsBreakeven = 0.026;
        $peakState->marketVolatilityEma = 0.16;

        $curvePeak = $reflectionMethod->invoke($this->engine, $peakState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        // 2s10s spread must invert at the peak of monetary policy tightening
        $spreadPeak = $curvePeak['yield_10y'] - $curvePeak['yield_2y'];
        $this->assertLessThan(
            0.0,
            $spreadPeak,
            sprintf('2s10s yield curve spread must invert at the peak of a monetary policy tightening campaign (got %0.2f bps).', $spreadPeak * 10000)
        );

        // 2. Recession / easing cycle: policyRate = 1.0%, targetRate = 0.5%, outputGap = -2.5%
        $easingState = new \App\Service\Macro\MacroState();
        $easingState->policyRate = 0.010;
        $easingState->targetRate = 0.005;
        $easingState->outputGap = -0.025;
        $easingState->inflation = 0.015;
        $easingState->inflationEma = 0.015;
        $easingState->tipsBreakeven = 0.018;
        $easingState->marketVolatilityEma = 0.22;

        $curveEasing = $reflectionMethod->invoke($this->engine, $easingState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        // 2s10s spread must exhibit strong bull steepening (> +100 bps) during emergency rate cutting
        $spreadEasing = $curveEasing['yield_10y'] - $curveEasing['yield_2y'];
        $this->assertGreaterThan(
            0.0100,
            $spreadEasing,
            sprintf('2s10s yield curve spread must bull-steepen significantly during monetary easing (got %0.2f bps).', $spreadEasing * 10000)
        );
    }

    public function testBggFinancialAcceleratorAmplifiesCreditCrunch(): void
    {
        $reflectionMethod = new \ReflectionMethod(MacroEngine::class, 'calculateOutputGap');
        $dt = 0.25;
        $stressMultiplier = 1.0;
        $naturalRate = MacroEngine::BASE_NATURAL_RATE;
        $yield5y = 0.04;

        // Baseline state: credit spreads at equilibrium (200 bps credit, 10 bps interbank)
        $stateBaseline = new \App\Service\Macro\MacroState();
        $stateBaseline->outputGap = 0.0;
        $stateBaseline->macroCreditSpreadEma = MacroEngine::BASE_CREDIT_SPREAD;
        $stateBaseline->interbankLiquiditySpreadEma = MacroEngine::INTERBANK_BASELINE_SPREAD;

        // Crisis state: credit spreads blown out (600 bps credit, 150 bps interbank)
        $stateCrisis = clone $stateBaseline;
        $stateCrisis->macroCreditSpreadEma = 0.06;
        $stateCrisis->interbankLiquiditySpreadEma = 0.015;

        $gapBaseline = $reflectionMethod->invoke($this->engine, $stateBaseline, $yield5y, $naturalRate, $dt, $stressMultiplier);
        $gapCrisis   = $reflectionMethod->invoke($this->engine, $stateCrisis, $yield5y, $naturalRate, $dt, $stressMultiplier);

        $this->assertLessThan(
            $gapBaseline,
            $gapCrisis,
            'BGG financial accelerator: blown-out credit & interbank spreads must contract output gap more than baseline.'
        );
    }

    public function testStagflationFromEnergyShock(): void
    {
        $reflectionOutputGap = new \ReflectionMethod(MacroEngine::class, 'calculateOutputGap');
        $reflectionInflation = new \ReflectionMethod(MacroEngine::class, 'calculateInflation');
        $dt = 0.25;
        $stressMultiplier = 1.0;
        $yield5y = 0.04;

        $stateNormal = new \App\Service\Macro\MacroState();
        $stateNormal->energyPriceShock = 0.0;

        $stateShock = clone $stateNormal;
        $stateShock->energyPriceShock = 100.0; // 100% price surge

        $gapNormal = $reflectionOutputGap->invoke($this->engine, $stateNormal, $yield5y, MacroEngine::BASE_NATURAL_RATE, $dt, $stressMultiplier);
        $gapShock  = $reflectionOutputGap->invoke($this->engine, $stateShock, $yield5y, MacroEngine::BASE_NATURAL_RATE, $dt, $stressMultiplier);

        $this->assertLessThan($gapNormal, $gapShock, 'Supply-side stagflation: energy price spike must drag down output gap.');

        $infNormal = $reflectionInflation->invoke($this->engine, $stateNormal, MacroEngine::TARGET_INFLATION, $stressMultiplier, $dt);
        $infShock  = $reflectionInflation->invoke($this->engine, $stateShock, MacroEngine::TARGET_INFLATION, $stressMultiplier, $dt);

        $this->assertGreaterThan($infNormal, $infShock, 'Cost-push channel: energy price spike must raise headline inflation.');
    }

    public function testFoodCpiChannelFromAgriculturalSpike(): void
    {
        $reflectionInflation = new \ReflectionMethod(MacroEngine::class, 'calculateInflation');
        $dt = 0.25;
        $stressMultiplier = 1.0;

        $stateNormal = new \App\Service\Macro\MacroState();
        $stateNormal->agriculturalCommodityIndex = 100.0;

        $stateSpike = clone $stateNormal;
        $stateSpike->agriculturalCommodityIndex = 200.0; // 100% agri commodity surge

        $infNormal = $reflectionInflation->invoke($this->engine, $stateNormal, MacroEngine::TARGET_INFLATION, $stressMultiplier, $dt);
        $infSpike  = $reflectionInflation->invoke($this->engine, $stateSpike, MacroEngine::TARGET_INFLATION, $stressMultiplier, $dt);

        $this->assertGreaterThan($infNormal, $infSpike, 'Food CPI channel: agricultural commodity spike must increase headline inflation.');
        $this->assertGreaterThan(0.0, $stateSpike->agriCostPushLag, 'Distributed lag accumulator for food CPI must be positive.');
    }

    public function testNairuHysteresisScarsAfterDeepRecession(): void
    {
        $laborSubsystem = new \App\Service\Macro\Subsystem\LaborMarketSubsystem();

        $state = new \App\Service\Macro\MacroState();
        $state->nairu = 0.04;
        $state->unemploymentRate = 0.08;
        $state->unemploymentRateEma = 0.08; // Sustained deep labor market slack

        $laborSubsystem->calculateUnemployment($state, 1.0); // 1 year of deep recession

        $this->assertGreaterThan(
            0.04,
            $state->nairu,
            'Sustained high unemployment must cause structural scarring, drifting NAIRU upward.'
        );
    }

    public function testNairuRecoveryDuringTightLaborMarket(): void
    {
        $laborSubsystem = new \App\Service\Macro\Subsystem\LaborMarketSubsystem();

        $state = new \App\Service\Macro\MacroState();
        $state->nairu = 0.06; // Scarred from prior downturn
        $state->unemploymentRate = 0.03;
        $state->unemploymentRateEma = 0.03; // Booming tight labor market

        $laborSubsystem->calculateUnemployment($state, 1.0); // 1 year of tight labor market

        $this->assertLessThan(
            0.06,
            $state->nairu,
            'Prolonged expansion with unemployment below NAIRU must heal structural scarring, drifting NAIRU downward.'
        );
    }

    public function testDownwardWageRigidity(): void
    {
        $laborSubsystem = new \App\Service\Macro\Subsystem\LaborMarketSubsystem();
        $dt = 0.25;
        $tfpGrowth = MacroEngine::TFP_DRIFT;

        // Baseline: wageGrowth at equilibrium (3.5%)
        $stateUp = new \App\Service\Macro\MacroState();
        $stateUp->unemploymentRate = 0.025; // Tight -> high vacancies -> high tightness -> wage growth wants to rise
        $stateUp->wageGrowth = 0.035;

        $laborSubsystem->calculateLaborMarketAndWages($stateUp, $tfpGrowth, $dt);
        $upwardDelta = $stateUp->wageGrowth - 0.035;

        $stateDown = new \App\Service\Macro\MacroState();
        $stateDown->unemploymentRate = 0.08; // Slack -> low vacancies -> low tightness -> wage growth wants to fall
        $stateDown->wageGrowth = 0.035;

        $laborSubsystem->calculateLaborMarketAndWages($stateDown, $tfpGrowth, $dt);
        $downwardDelta = 0.035 - $stateDown->wageGrowth;

        $this->assertGreaterThan(0.0, $upwardDelta, 'Wages must rise during tight labor conditions.');
        $this->assertGreaterThan(0.0, $downwardDelta, 'Wages must fall during slack labor conditions.');
        $this->assertGreaterThan(
            $downwardDelta,
            $upwardDelta,
            'Bewley downward wage rigidity: wages must resist falling faster than they rise.'
        );
    }

    public function testForwardLookingTaylorHikesOnUnanchoredExpectations(): void
    {
        $monetarySubsystem = new \App\Service\Macro\Subsystem\MonetaryPolicySubsystem($this->mathUtilityMock);

        $stateAnchored = new \App\Service\Macro\MacroState();
        $stateAnchored->inflationEma = 0.020;
        $stateAnchored->tipsBreakeven = 0.020;
        $stateAnchored->outputGap = 0.0;

        $stateUnanchored = clone $stateAnchored;
        $stateUnanchored->tipsBreakeven = 0.040; // Forward expectations unanchored to 4.0%

        $targetAnchored   = $monetarySubsystem->calculateTargetRate($stateAnchored, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $targetUnanchored = $monetarySubsystem->calculateTargetRate($stateUnanchored, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $this->assertGreaterThan(
            $targetAnchored,
            $targetUnanchored,
            'Forward-looking Taylor rule must hike aggressively when TIPS breakeven expectations unanchor.'
        );
    }

    public function testSovereignDebtAccumulatesDuringFiscalDeficit(): void
    {
        $creditFiscalSubsystem = new \App\Service\Macro\Subsystem\CreditFiscalSubsystem($this->mathUtilityMock);

        $state = new \App\Service\Macro\MacroState();
        $state->sovereignDebtToGdp = 0.60;
        $state->governmentSpendingIndex = 140.0; // High fiscal spending
        $state->outputGap = -0.02; // Contraction reducing corporate tax base
        $state->corporateTaxRate = 0.18;
        $state->yield10yEma = 0.04;
        $state->inflation = 0.015;

        $creditFiscalSubsystem->calculateSovereignDebt($state, 1.0);

        $this->assertGreaterThan(
            0.60,
            $state->sovereignDebtToGdp,
            'Persistent primary fiscal deficit must accumulate sovereign debt-to-GDP stock.'
        );
    }

    public function testSovereignDebtSteepensYieldCurve(): void
    {
        $monetarySubsystem = new \App\Service\Macro\Subsystem\MonetaryPolicySubsystem($this->mathUtilityMock);
        $dt = 0.25;

        $stateLowDebt = new \App\Service\Macro\MacroState();
        $stateLowDebt->sovereignDebtToGdpEma = 0.60;

        $stateHighDebt = clone $stateLowDebt;
        $stateHighDebt->sovereignDebtToGdpEma = 1.20; // 120% of GDP

        $curveLow  = $monetarySubsystem->calculateYieldCurveAndQE($stateLowDebt, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, $dt);
        $curveHigh = $monetarySubsystem->calculateYieldCurveAndQE($stateHighDebt, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, $dt);

        $this->assertGreaterThan(
            $curveLow['yield_30y'],
            $curveHigh['yield_30y'],
            'Greenwood-Vayanos preferred habitat: higher sovereign debt must increase long-end yields.'
        );
        $this->assertGreaterThan(
            $curveLow['term_premium_10y'],
            $curveHigh['term_premium_10y'],
            'Excess sovereign debt supply must expand the 10Y term premium.'
        );
    }

    public function testFinancialConditionsIndexTightensDuringCrisis(): void
    {
        $assetSubsystem = new \App\Service\Macro\Subsystem\AssetMarketSubsystem($this->mathUtilityMock);

        $stateCrisis = new \App\Service\Macro\MacroState();
        $stateCrisis->macroCreditSpreadEma = 0.05; // 500 bps
        $stateCrisis->equityRiskPremium = 0.08;    // 800 bps
        $stateCrisis->marketVolatilityEma = 0.28;  // High vol
        $stateCrisis->nsSlopeEma = -0.015;         // Inverted curve
        $stateCrisis->exchangeRateIndexEma = 115.0; // Strong dollar

        $assetSubsystem->calculateFinancialConditionsIndex($stateCrisis, 0.25);

        $this->assertGreaterThan(
            0.20,
            $stateCrisis->financialConditionsIndex,
            'Crisis conditions (wide spreads, inverted curve, high vol) must produce positive (restrictive) FCI.'
        );
    }

    public function testFinancialConditionsIndexLooseDuringGoldilocks(): void
    {
        $assetSubsystem = new \App\Service\Macro\Subsystem\AssetMarketSubsystem($this->mathUtilityMock);

        $stateGoldilocks = new \App\Service\Macro\MacroState();
        $stateGoldilocks->macroCreditSpreadEma = 0.012; // Narrow spreads
        $stateGoldilocks->equityRiskPremium = 0.040;   // Narrow ERP
        $stateGoldilocks->marketVolatilityEma = 0.11;  // Low vol
        $stateGoldilocks->nsSlopeEma = 0.020;          // Steep healthy curve
        $stateGoldilocks->exchangeRateIndexEma = 95.0; // Mildly soft currency

        $assetSubsystem->calculateFinancialConditionsIndex($stateGoldilocks, 0.25);

        $this->assertLessThan(
            -0.10,
            $stateGoldilocks->financialConditionsIndex,
            'Goldilocks conditions (tight spreads, steep curve, low vol) must produce negative (accommodative) FCI.'
        );
    }

    public function testPfluegerViceiraTipsBreakevenDropsDuringDeflationaryRecessionDespiteHighEquityVolatility(): void
    {
        $aggregateSubsystem = new \App\Service\Macro\Subsystem\MacroAggregateSubsystem($this->mathUtilityMock);

        // Recession state with market panic (high VIX = 40%), but low inflation (1.2%) and slack output gap (-2.5%)
        $stateRecessionPanic = new \App\Service\Macro\MacroState();
        $stateRecessionPanic->inflationEma = 0.012;
        $stateRecessionPanic->outputGapEma = -0.025;
        $stateRecessionPanic->marketVolatilityEma = 0.40; // Panic volatility
        $stateRecessionPanic->energyCostPushLag = 0.0;
        $stateRecessionPanic->agriCostPushLag = 0.0;

        $tipsRecession = $aggregateSubsystem->calculateTipsBreakeven($stateRecessionPanic, MacroEngine::TARGET_INFLATION, 0.25);

        // TIPS breakeven must drop below 2.0% target reflecting deflation/slack risk, NOT spike from equity panic
        $this->assertLessThan(
            MacroEngine::TARGET_INFLATION,
            $tipsRecession,
            'Under Pflueger & Viceira (2011), TIPS breakeven must drop below 2.0% during deflationary recessions even if equity VIX is high.'
        );

        // Inflation shock state (inflation 4.5% + energy spike)
        $stateInflationShock = new \App\Service\Macro\MacroState();
        $stateInflationShock->inflationEma = 0.045;
        $stateInflationShock->outputGapEma = 0.020;
        $stateInflationShock->marketVolatilityEma = 0.20;
        $stateInflationShock->energyCostPushLag = 0.010; // 100 bps energy cost push
        $stateInflationShock->agriCostPushLag = 0.005;

        $tipsShock = $aggregateSubsystem->calculateTipsBreakeven($stateInflationShock, MacroEngine::TARGET_INFLATION, 0.25);

        // TIPS breakeven must price in both fundamental expected inflation and positive inflation risk premium
        $this->assertGreaterThan(
            0.040,
            $tipsShock,
            'TIPS breakeven must incorporate substantial inflation risk premium when inflation and cost-push shocks are elevated.'
        );
    }
}
