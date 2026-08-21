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
    private MathUtility|MockObject $mathUtilityMock;
    private \Redis|MockObject $redisMock;
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

    public function testMacroEngineCanBeInstantiated()
    {
        $this->assertInstanceOf(MacroEngine::class, $this->engine);
    }

    public function testUpdateMacroStateInitializesFromEmptyRedis()
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

    public function testUpdateMacroStateWithExistingStateAndRecessionShock()
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

    public function testUpdateMacroStateDuringSevereInflationBoom()
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
}
