<?php

namespace App\Tests\Service\Macro;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\MathUtility;
use App\Data\SectorPE;
use App\Data\Macro\EconomicCycle;

#[AllowMockObjectsWithoutExpectations]
class MacroEngineTest extends TestCase
{
    private MathUtility&MockObject $mathUtilityMock;
    private \Redis&MockObject $redisMock;
    private MacroSnapshotRecorder $snapshotRecorder;
    private MonetaryPolicySubsystem $monetarySubsystem;
    private LaborMarketSubsystem $laborSubsystem;
    private MacroAggregateSubsystem $aggregateSubsystem;
    private CommodityLogisticsSubsystem $commoditySubsystem;
    private AssetMarketSubsystem $assetSubsystem;
    private CreditFiscalSubsystem $creditFiscalSubsystem;
    private MacroEngine $engine;

    protected function setUp(): void
    {
        // Use a partial mock so the actual math methods run, but we can control randomness
        $this->mathUtilityMock = $this->getMockBuilder(MathUtility::class)
            // Storm arrivals are a Poisson count, mocked to zero so a neutral tick stays neutral.
            ->onlyMethods(['generateStandardNormal', 'checkProbability', 'generatePoissonCount'])
            ->getMock();
        $this->redisMock = $this->createMock(\Redis::class);

        $this->snapshotRecorder = new MacroSnapshotRecorder();
        $this->monetarySubsystem = new MonetaryPolicySubsystem($this->mathUtilityMock);
        $this->laborSubsystem = new LaborMarketSubsystem();
        $this->aggregateSubsystem = new MacroAggregateSubsystem($this->mathUtilityMock);
        $this->commoditySubsystem = new CommodityLogisticsSubsystem($this->mathUtilityMock);
        $this->assetSubsystem = new AssetMarketSubsystem($this->mathUtilityMock);
        $this->creditFiscalSubsystem = new CreditFiscalSubsystem($this->mathUtilityMock);

        $this->engine = new MacroEngine(
            $this->mathUtilityMock,
            $this->redisMock,
            $this->snapshotRecorder,
            $this->monetarySubsystem,
            $this->laborSubsystem,
            $this->aggregateSubsystem,
            $this->commoditySubsystem,
            $this->assetSubsystem,
            $this->creditFiscalSubsystem,
        );
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

        // A cold start opens at trend, where the rule's target is the opening policy rate: a year's step barely moves it.
        $opening = new \App\DTO\MacroStateDTO();
        $this->assertEqualsWithDelta($opening->policyRate, $result->targetRate, 0.005, 'At trend the rule asks for the rate it opens at.');
        $this->assertEqualsWithDelta($opening->policyRate, $result->policyRate, 0.005, 'So the first year does not jolt the policy rate.');
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

    public function testAStrategicDividendIsReadForItsOwnTickAndNeverReplayed(): void
    {
        $saved = false;
        $this->redisMock->method('get')->willReturnCallback(static function () use (&$saved) {
            return $saved;
        });
        $this->redisMock->method('set')->willReturnCallback(static function (string $key, string $value) use (&$saved): bool {
            $saved = $value;

            return true;
        });
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $paid = $this->engine->updateMacroState(1.0 / 360.0, strategicStakeCash: 2.5e8);
        $this->assertSame(2.5e8, $paid->strategicStakeCash);

        $next = $this->engine->updateMacroState(1.0 / 360.0);
        $this->assertSame(0.0, $next->strategicStakeCash, 'A flow the ticker did not report is zero, not the last one again.');
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

    /** A productivity level shock opens a supply gap but leaves r* alone: it moves potential, not its trend growth (HLW's sigma_y*). */
    public function testAProductivityLevelShockLeavesTheNaturalRateAlone(): void
    {
        $calm = ['natural_rate' => MacroEngine::BASE_NATURAL_RATE, 'tfp_shock_level' => 0.0];
        $shocked = ['tfp_shock_level' => 0.05] + $calm;
        $this->redisMock->method('get')->willReturnOnConsecutiveCalls(json_encode($calm), json_encode($shocked));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $withoutShock = $this->engine->updateMacroState(0.25);
        $withShock = $this->engine->updateMacroState(0.25);

        $this->assertGreaterThan($withoutShock->productivitySupplyGap, $withShock->productivitySupplyGap, 'The shock reaches output before potential.');
        $this->assertSame($withoutShock->naturalRate, $withShock->naturalRate);
    }

    /** The tick a purchase programme starts is stamped, and that is the tick its one headline goes out. */
    public function testTheTickAPurchaseProgrammeStartsIsStampedAndReported(): void
    {
        $this->redisMock->method('get')->willReturn(json_encode([
            'output_gap' => -0.06,
            'output_gap_ema' => -0.05,
            'inflation' => 0.005,
            'inflation_ema' => 0.005,
            'policy_rate' => MacroEngine::EFFECTIVE_LOWER_BOUND,
            'policy_rate_ema' => MacroEngine::EFFECTIVE_LOWER_BOUND,
            'balance_sheet_intensity' => 0.0,
            'qe_active' => false,
        ]));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertLessThan(MacroEngine::EFFECTIVE_LOWER_BOUND, $result->targetRate, 'The slump asks for a rate below the floor.');
        $this->assertTrue($result->qeActive);
        $this->assertSame($result->totalTime, $result->lastQeLaunchAt);
        $this->assertSame(\App\Service\Event\ShockEvent::TITAN_INTERVENTION, $result->eventType);
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
            'policy_rate' => 0.06, // 6% policy rate, well above the mainland Fed's 3.5% neutral
            'policy_rate_ema' => 0.06,
            'exchange_rate_index' => 100.0,
            'exchange_rate_index_ema' => 100.0,
            'exchange_rate_deviation' => 1.0, // at its fundamental, so the index is parity alone
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'output_gap' => 0.0,
            'output_gap_ema' => 0.0,
            'yield_5y' => 0.06,
            'yield_10y' => 0.065,
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
            'policy_rate' => 0.005, // 0.5% policy rate, well below the mainland Fed's 3.5% neutral
            'policy_rate_ema' => 0.005,
            'exchange_rate_index' => 100.0,
            'exchange_rate_index_ema' => 100.0,
            'exchange_rate_deviation' => 1.0, // at its fundamental, so the index is parity alone
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'output_gap' => 0.0,
            'output_gap_ema' => 0.0,
            'yield_5y' => 0.01,
            'yield_10y' => 0.02,
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
            // Metals clear on world demand; the district's boom is its weighted share of it, as the EMA barrier will publish it.
            'global_demand_gap' => 0.04 * MacroEngine::DOMESTIC_DEMAND_WEIGHT,
            'global_demand_gap_ema' => 0.04 * MacroEngine::DOMESTIC_DEMAND_WEIGHT,
            'industrial_metals_index' => 100.0,
            'industrial_metals_index_ema' => 100.0,
            'metals_chi' => 0.0,
            'metals_xi' => 4.60517, // ln(100)
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'policy_rate' => 0.03,
            'policy_rate_ema' => 0.03,
            'yield_5y' => 0.035,
            'yield_10y' => 0.04,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($existingState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.50);

        $this->assertGreaterThan(100.0, $result->industrialMetalsIndex, 'Positive output gap should drive positive supercycle drift in metals.');
    }

    public function testGoldRalliesWhenRealRatesFallAndConfidenceBreaks(): void
    {
        // Long real yields two points under their resting level and consumers braced for bad times: the
        // refuge's equilibrium sits well above its baseline, so the engine's step moves the price up to it.
        $existingState = [
            'yield_10y' => 0.030,
            'yield_10y_ema' => 0.030,
            'tips_breakeven' => 0.022,
            'tips_breakeven_ema' => 0.022,
            'consumer_sentiment_index' => 70.0,
            'consumer_sentiment_index_ema' => 70.0,
            'gold_price_index' => 100.0,
            'gold_price_index_ema' => 100.0,
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'policy_rate' => 0.02,
            'policy_rate_ema' => 0.02,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($existingState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.50);

        $this->assertGreaterThan(105.0, $result->goldPriceIndex, 'Falling real rates and pessimism must lift gold.');
    }

    public function testCommercialPropertyCollapsesDuringHighRatesAndUnemployment(): void
    {
        $existingState = [
            'unemployment_rate' => 0.08,
            'unemployment_rate_ema' => 0.08,
            'yield_10y' => 0.08,
            'yield_10y_ema' => 0.08,
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
            'yield_5y' => 0.075,
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
            'yield_5y' => 0.07,
            'yield_10y' => 0.075,
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
            'yield_5y' => 0.04,
            'yield_10y' => 0.045,
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
            'yield_5y' => 0.05,
            'yield_10y' => 0.0564,
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
            'yield_5y' => 0.04,
            'yield_10y' => 0.045,
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
            'yield_5y' => 0.04,
            'yield_10y' => 0.045,
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
            'nairu' => MacroEngine::NATURAL_UNEMPLOYMENT,
            'nairu_ema' => MacroEngine::NATURAL_UNEMPLOYMENT,
            'yield_10y' => 0.0504,
            'yield_10y_ema' => 0.0504,
            // The cap rate at its neutral: 5.04% less 2% expected rent inflation, plus a 1.36% spread and the 2% premium.
            'macro_credit_spread' => 0.0136,
            'macro_credit_spread_ema' => 0.0136,
            'tips_breakeven' => MacroEngine::TARGET_INFLATION,
            'tips_breakeven_ema' => MacroEngine::TARGET_INFLATION,
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
            'policy_rate' => MacroEngine::EFFECTIVE_LOWER_BOUND,
            'policy_rate_ema' => MacroEngine::EFFECTIVE_LOWER_BOUND,
            'yield_5y' => 0.02,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($recessionRecoveryState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        // Evans Rule holds the policy rate at the ZLB (does not hike), while targetRate reflects the unconstrained shadow rate
        $this->assertEquals(MacroEngine::EFFECTIVE_LOWER_BOUND, $result->policyRate, 'Evans Rule must lock policy rate at ZLB while unemployment exceeds 5.0% and inflation is below 2.5%.');
        $this->assertGreaterThan(0.00, $result->targetRate, 'Wu-Xia shadow target rate remains continuous and positive.');
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
        // When unemployment is > 5.0% and inflation < 2.5%, Evans Rule prevents premature policy rate liftoff
        $recoveryState = [
            'unemployment_rate' => 0.052,
            'unemployment_rate_ema' => 0.052,
            'inflation' => 0.021,
            'inflation_ema' => 0.021,
            'output_gap' => -0.010,
            'output_gap_ema' => -0.010,
            'policy_rate' => MacroEngine::EFFECTIVE_LOWER_BOUND,
            'policy_rate_ema' => MacroEngine::EFFECTIVE_LOWER_BOUND,
            'yield_5y' => 0.025,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($recoveryState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertLessThanOrEqual(MacroEngine::EFFECTIVE_LOWER_BOUND, $result->policyRate, 'Evans Rule must prevent rate hikes while unemployment is above 5.0% and inflation is below 2.5%.');
        $this->assertGreaterThan(0.00, $result->targetRate, 'Taylor shadow target rate calculates continuously without artificial zero clamping.');
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
            'policy_rate' => MacroEngine::EFFECTIVE_LOWER_BOUND,
            'policy_rate_ema' => MacroEngine::EFFECTIVE_LOWER_BOUND,
            'yield_5y' => 0.02,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($noisyRecoveryState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(0.25);

        $this->assertEquals(MacroEngine::EFFECTIVE_LOWER_BOUND, $result->policyRate, 'Evans Rule must anchor on trend unemployment (EMA) to avoid premature liftoff from single-period noise.');
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
            'yield_10y' => 0.0475,
            'yield_10y_ema' => 0.0475,
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
            'yield_10y' => 0.07,
            'yield_10y_ema' => 0.06,
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

        $aggregateSubsystem = new MacroAggregateSubsystem($mathUtility);

        // Create a perfectly neutral baseline state
        $stateNeutral = new \App\Service\Macro\MacroState();
        $stateNeutral->outputGap = 0.0;
        $stateNeutral->inflation = 0.02;
        $stateNeutral->policyRate = 0.035; // Natural rate (1.5%) + Target Inflation (2%)
        $stateNeutral->corporateTaxRate = 0.21;
        $stateNeutral->capitalStockOverhang = 0.0;

        // Neutral credit: spreads at the baselines the credit legs measure their drag from, and the compensated
        // crisis drag at its long-run average
        $stateNeutral->macroCreditSpreadEma = MacroEngine::BASE_CREDIT_SPREAD;
        $stateNeutral->interbankLiquiditySpreadEma = MacroEngine::INTERBANK_BASELINE_SPREAD;
        $stateNeutral->creditCrisisDrag = $aggregateSubsystem->stationaryCrisisDrag();

        // Neutral Housing & Commodity Markets
        $stateNeutral->residentialPropertyIndexEma = 100.0;
        $stateNeutral->energyPriceIndexEma = MacroEngine::ENERGY_BASELINE;
        $stateNeutral->freightRateIndexEma = MacroEngine::FREIGHT_BASELINE;

        // Create an identical state, but with a collapsed housing market (20% crash)
        $stateCrash = clone $stateNeutral;
        $stateCrash->residentialPropertyIndexEma = 80.0;

        // Create an identical state, but with a booming housing market (20% surge)
        $stateBoom = clone $stateNeutral;
        $stateBoom->residentialPropertyIndexEma = 120.0;

        // 2. Act: Calculate Output Gap using Aggregate Subsystem
        // Assume 5Y Yield is neutral (naturalRate + targetInflation + NS_BASE_TERM_PREMIUM * durationScale)
        $neutral5yDurationScale = (1.0 - exp(-5.0 / 10.0)) / (1.0 - exp(-1.0));
        $neutral5yYield = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION + (MacroEngine::NS_BASE_TERM_PREMIUM * $neutral5yDurationScale);
        $dt = 0.25;
        $stressMultiplier = 1.0;

        $gapNeutral = $aggregateSubsystem->calculateOutputGap($stateNeutral, $neutral5yYield, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, $stressMultiplier);
        $gapCrash   = $aggregateSubsystem->calculateOutputGap($stateCrash, $neutral5yYield, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, $stressMultiplier);
        $gapBoom    = $aggregateSubsystem->calculateOutputGap($stateBoom, $neutral5yYield, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, $stressMultiplier);

        // 3. Assert, against the neutral step: this state sets only the fields the housing channel reads, so the
        // other channels need not net to zero (the credit premium's compensator does not), and the wealth effect is
        // the move housing alone adds to that step.
        // Direction and symmetry only. The size of the step is the aggregate subsystem's own dial, asserted
        // against the elasticity in the suite that owns it; naming that constant here would cost this file the
        // single-subsystem exemption MacroConstantOwnershipTest grants it.
        $this->assertLessThan($gapNeutral, $gapCrash, 'Housing crash must create a negative drag on the output gap.');
        $this->assertGreaterThan($gapNeutral, $gapBoom, 'Housing boom must create a positive stimulus on the output gap.');
        $this->assertEqualsWithDelta($gapNeutral - $gapCrash, $gapBoom - $gapNeutral, 1e-12, 'A crash and a boom of the same size move demand by the same amount.');
    }

    public function testInterbankLiquiditySpreadMeanRevertsViaCIR(): void
    {
        $mathUtility = $this->createMock(MathUtility::class);

        // Suppress jump diffusion and noise to test pure structural drift
        $mathUtility->method('generateStandardNormal')->willReturn(0.0);
        $mathUtility->method('calculateJumpDiffusion')->willReturn([
            'multiplier' => 1.0,
            'shock_pct' => null,
            'exponent' => null,
        ]);

        // Expect the CIR method to be called with the engine's constants, its reversion stiffened by the panic jumps'
        // Merton compensator c: kappa + c towards kappa theta / (kappa + c), the same pull towards theta net of the jumps.
        $cir = null;
        $mathUtility->expects($this->once())
            ->method('calculateCIR')
            ->willReturnCallback(function (float ...$args) use (&$cir): float {
                $cir = $args;

                return 0.035; // Mock a reversion down to 350 bps
            });

        $creditFiscalSubsystem = new CreditFiscalSubsystem($mathUtility);

        $state = new \App\Service\Macro\MacroState();
        $state->interbankLiquiditySpread = 0.05;
        $state->marketVolatilityEma = 0.15; // Neutral VIX

        $creditFiscalSubsystem->calculateInterbankLiquiditySpread($state, 0.25);

        $this->assertNotNull($cir);
        [$current, $kappa, $theta, $sigma, $dt, $dW] = $cir;
        $this->assertSame([0.05, MacroEngine::INTERBANK_SPREAD_SIGMA, 0.25, 0.0], [$current, $sigma, $dt, $dW]);
        $this->assertGreaterThan(MacroEngine::INTERBANK_SPREAD_KAPPA, $kappa, 'The jumps are compensated in the drift.');
        $this->assertEqualsWithDelta(MacroEngine::INTERBANK_SPREAD_KAPPA * MacroEngine::INTERBANK_BASELINE_SPREAD, $kappa * $theta, 1e-15);
        $this->assertEquals(0.035, $state->interbankLiquiditySpread, 'Interbank spread must mean-revert using CIR.');
    }

    public function testInterbankLiquiditySpreadBlowsOutDuringMarketPanicJump(): void
    {
        $mathUtility = $this->createMock(MathUtility::class);

        $mathUtility->method('generateStandardNormal')->willReturn(0.0);
        // Mock CIR base process staying at 15 bps baseline
        $mathUtility->method('calculateCIR')->willReturn(MacroEngine::INTERBANK_BASELINE_SPREAD);
        // Simulate a 5x blowout jump
        $mathUtility->method('calculateJumpDiffusion')->willReturn([
            'multiplier' => 5.0,
            'shock_pct' => 400.0,
            'exponent' => 1.60,
        ]);

        $creditFiscalSubsystem = new CreditFiscalSubsystem($mathUtility);

        $state = new \App\Service\Macro\MacroState();
        $state->interbankLiquiditySpread = MacroEngine::INTERBANK_BASELINE_SPREAD;
        $state->marketVolatilityEma = 0.35; // Panicked VIX

        $creditFiscalSubsystem->calculateInterbankLiquiditySpread($state, 0.25);

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
        $mathUtility->method('generateStandardNormal')->willReturn(0.0);

        $aggregateSubsystem = new MacroAggregateSubsystem($mathUtility);

        $state = new \App\Service\Macro\MacroState();
        $state->totalFactorProductivityIndex = 100.0;
        $state->potentialGdpIndex = 1.0;
        $state->nominalGdpIndex = 1.0;
        $state->outputGap = 0.0;
        $state->outputGapEma = 0.0;
        $state->inflation = 0.02;
        $state->inflationEma = 0.02;

        $aggregateSubsystem->calculatePotentialAndNominalGdp($state, 0.25);

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
        $mathUtility->method('generateStandardNormal')->willReturn(0.0);

        $aggregateSubsystem = new MacroAggregateSubsystem($mathUtility);

        $state = new \App\Service\Macro\MacroState();
        $state->totalFactorProductivityIndex = 100.0;
        $state->potentialGdpIndex = 1.0;
        $state->nominalGdpIndex = 1.0;
        $state->outputGap = 0.05; // 5% positive output gap (economic boom)
        $state->outputGapEma = 0.0;
        $state->inflation = 0.02;
        $state->inflationEma = 0.02;

        $aggregateSubsystem->calculatePotentialAndNominalGdp($state, 0.25);

        $expectedPotential = 1.0 * exp((MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + MacroEngine::TFP_DRIFT) * 0.25);
        $expectedNominal = 1.0 * exp((MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + MacroEngine::TFP_DRIFT + 0.02) * 0.25) * 1.05;

        $this->assertEqualsWithDelta($expectedPotential, $state->potentialGdpIndex, 0.0001);
        $this->assertEqualsWithDelta($expectedNominal, $state->nominalGdpIndex, 0.0001, 'Nominal GDP must scale with cyclical output gap and inflation.');
    }

    public function testSolowSwanTfpCanContractDuringSevereRecession(): void
    {
        $mathUtility = $this->createMock(MathUtility::class);

        // Severe negative productivity shock (e.g. supply chain disruption)
        $mathUtility->method('generateStandardNormal')->willReturn(-3.0);

        $aggregateSubsystem = new MacroAggregateSubsystem($mathUtility);

        $state = new \App\Service\Macro\MacroState();
        $state->totalFactorProductivityIndex = 120.0;
        $state->outputGapEma = -0.06; // Deep 6% economic recession

        $aggregateSubsystem->calculateTotalFactorProductivity($state, 0.25);

        $this->assertLessThan(120.0, $state->totalFactorProductivityIndex, 'TFP index can and should contract during deep recessions with negative innovation shocks.');

        // MIN_TFP_GROWTH_RATE floors the trend GROWTH RATE, and cannot also floor the realized path: a
        // single log increment is a rate scaled by dt plus an innovation scaled by sqrt(dt), so a band in
        // annual rate units is narrower than the innovation it would be clipping and biases the drift onto
        // its own midpoint. A three-sigma quarter must therefore land below that band, not on it.
        $rateFloorOverTheQuarter = 120.0 * exp(MacroEngine::MIN_TFP_GROWTH_RATE * 0.25);
        $this->assertLessThan($rateFloorOverTheQuarter, $state->totalFactorProductivityIndex, 'A three-sigma innovation must reach the index at full size, not be clipped to the annual growth-rate band.');
    }

    public function testExchangeRateAppreciationDragsDownOutputGap(): void
    {
        $stateStrongFx = new \App\Service\Macro\MacroState();
        $stateStrongFx->exchangeRateIndexEma = 120.0; // 20% appreciation

        $stateNeutralFx = new \App\Service\Macro\MacroState();
        $stateNeutralFx->exchangeRateIndexEma = 100.0; // Neutral

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $gapStrongFx = $this->aggregateSubsystem->calculateOutputGap($stateStrongFx, 0.05, 0.02, MacroEngine::TARGET_INFLATION, 0.25, 1.0);
        $gapNeutralFx = $this->aggregateSubsystem->calculateOutputGap($stateNeutralFx, 0.05, 0.02, MacroEngine::TARGET_INFLATION, 0.25, 1.0);

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

        $this->creditFiscalSubsystem->calculateMacroCreditSpread($stateStressed);
        $this->creditFiscalSubsystem->calculateMacroCreditSpread($stateNeutral);

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

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $infHigh = $this->aggregateSubsystem->calculateInflation($stateHighEma, 0.02, MacroEngine::TFP_DRIFT, 0.25);
        $infAnchored = $this->aggregateSubsystem->calculateInflation($stateAnchored, 0.02, MacroEngine::TFP_DRIFT, 0.25);

        $this->assertGreaterThan($infAnchored, $infHigh, 'Un-anchored expectations must result in higher inflation drift.');
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

        $yieldDataHighDeficit = $this->monetarySubsystem->calculateYieldCurveAndQE($stateHighDeficit, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $yieldDataNeutral = $this->monetarySubsystem->calculateYieldCurveAndQE($stateNeutralFiscal, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

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

        // Quarter 1 step
        $infQ1 = $this->aggregateSubsystem->calculateInflation($state, MacroEngine::TARGET_INFLATION, MacroEngine::TFP_DRIFT, 0.25);
        $lagQ1 = $state->energyCostPushLag;

        // The energy lag should have partially transmitted, but not fully reached raw transmission
        $rawTransmission = (50.0 / 100.0) * MacroEngine::ENERGY_COST_PUSH_TRANSMISSION;
        $this->assertGreaterThan(0.0, $lagQ1, 'Energy shock should immediately start transmitting through lag filter.');
        $this->assertLessThan($rawTransmission, $lagQ1, 'Energy shock should be sticky and not instantly transmit at 100% in first quarter.');

        // Advance to Quarter 2 with persistent shock
        $infQ2 = $this->aggregateSubsystem->calculateInflation($state, MacroEngine::TARGET_INFLATION, MacroEngine::TFP_DRIFT, 0.25);
        $lagQ2 = $state->energyCostPushLag;

        $this->assertGreaterThan($lagQ1, $lagQ2, 'Sticky cost lag should accumulate and rise across successive quarters of elevated energy.');
    }

    public function testRateSmoothingClosesTheSameShareCuttingAsHiking(): void
    {
        // US 1987-2008: 15% of the distance closed a quarter cutting, 13% hiking, not distinguishable (se 4-6%).
        $hikingState = new \App\Service\Macro\MacroState();
        $hikingState->policyRate = 0.02;
        $hikingState->inflation = 0.02;
        $hikingState->outputGap = 0.02;
        $hike = $this->monetarySubsystem->updatePolicyRate($hikingState, 0.03, 0.25) - $hikingState->policyRate;

        $cuttingState = new \App\Service\Macro\MacroState();
        $cuttingState->policyRate = 0.04;
        $cuttingState->inflation = 0.015;
        $cuttingState->outputGap = -0.03;
        $cut = $cuttingState->policyRate - $this->monetarySubsystem->updatePolicyRate($cuttingState, 0.03, 0.25);

        $this->assertEqualsWithDelta($hike, $cut, 1e-12, 'Below the panic threshold and the hike ceiling a 100bp distance must close by the same amount either way.');
    }

    public function testPolicyRatePaceIsProportionalToTheDistanceBelowTheHikeCeiling(): void
    {
        $state = new \App\Service\Macro\MacroState();
        $state->policyRate = 0.02; // clear of the lower bound, so forward guidance plays no part
        $state->inflation = 0.025; // below the panic threshold
        $state->outputGap = 0.03;
        $dt = 0.25;

        $smallMove = $this->monetarySubsystem->updatePolicyRate($state, 0.0225, $dt) - $state->policyRate;
        $largeMove = $this->monetarySubsystem->updatePolicyRate($state, 0.0325, $dt) - $state->policyRate;

        // Partial adjustment: a 125bp distance moves the rate five times as far as a 25bp one in the same quarter.
        $this->assertEqualsWithDelta(5.0 * $smallMove, $largeMove, 1e-12, 'Below the ceiling the pace must scale with the distance to the target.');
        $this->assertEqualsWithDelta((1.0 - exp(-MonetaryPolicySubsystem::CB_SMOOTHING_SPEED * $dt)) * 0.0125, $largeMove, 1e-12);
    }

    public function testHikesAreCappedAtTheFastestModernPaceButCutsAreNot(): void
    {
        $dt = 0.25;
        $hikingState = new \App\Service\Macro\MacroState();
        $hikingState->policyRate = 0.02;
        $hikingState->inflation = 0.025;
        $hikingState->outputGap = 0.04;
        $hike = $this->monetarySubsystem->updatePolicyRate($hikingState, 0.09, $dt) - $hikingState->policyRate;

        $cuttingState = new \App\Service\Macro\MacroState();
        $cuttingState->policyRate = 0.09;
        $cuttingState->inflation = 0.025;
        $cuttingState->outputGap = -0.04;
        $cut = $cuttingState->policyRate - $this->monetarySubsystem->updatePolicyRate($cuttingState, 0.02, $dt);

        // A 700bp jump in the target: the partial adjustment alone would hike 440bp in the quarter; the FOMC's fastest is 150bp (2022Q3).
        $this->assertEqualsWithDelta(MonetaryPolicySubsystem::CB_MAX_HIKE_VELOCITY * $dt, $hike, 1e-12, 'A hike must not outrun 75bp at each of the quarter\'s two meetings.');
        $this->assertEqualsWithDelta((1.0 - exp(-MonetaryPolicySubsystem::CB_SMOOTHING_SPEED * $dt)) * 0.07, $cut, 1e-12, 'Cuts keep the partial-adjustment pace (the Fed cut 200bp in 2008Q1).');
    }

    public function testInflationPanicAcceleratesHikesAboveEmergencyThreshold(): void
    {
        $dt = 0.25;
        $calmState = new \App\Service\Macro\MacroState();
        $calmState->policyRate = 0.02;
        $calmState->inflation = 0.025;
        $calmState->outputGap = 0.03;
        $calmHike = $this->monetarySubsystem->updatePolicyRate($calmState, 0.025, $dt) - $calmState->policyRate;

        // Severe stagflation / runaway inflation shock (8.0% inflation), the same 50bp distance, under the hike ceiling at either speed.
        $panicState = new \App\Service\Macro\MacroState();
        $panicState->policyRate = 0.02;
        $panicState->inflation = 0.080;
        $panicState->outputGap = 0.03;
        $panicHike = $this->monetarySubsystem->updatePolicyRate($panicState, 0.025, $dt) - $panicState->policyRate;

        $this->assertGreaterThan($calmHike, $panicHike, 'Severe runaway inflation must trigger emergency panic rate hiking acceleration.');
        $maxSpeed = MonetaryPolicySubsystem::CB_SMOOTHING_SPEED + MonetaryPolicySubsystem::CB_MAX_HIKE_PANIC_SPEED;
        $this->assertLessThanOrEqual((1.0 - exp(-$maxSpeed * $dt)) * 0.005 + 1e-12, $panicHike, 'Panic hiking must stay within the most the panic adds to the speed.');
    }

    public function testDynamicNaturalRateDriftsWithTfpGrowth(): void
    {
        $state = new \App\Service\Macro\MacroState();
        $state->naturalRate = MacroEngine::BASE_NATURAL_RATE; // 1.5%

        // 1. Accelerating TFP growth (3.5% vs baseline 1.5%) -> r* should drift upward
        $acceleratingTfp = 0.035;
        $dt = 1.0;
        $this->aggregateSubsystem->calculateNaturalRate($state, $acceleratingTfp, $dt);

        $this->assertGreaterThan(MacroEngine::BASE_NATURAL_RATE, $state->naturalRate, 'Natural real rate r* must drift upward when TFP productivity growth accelerates.');
        $this->assertLessThanOrEqual(MacroEngine::MAX_NATURAL_RATE, $state->naturalRate, 'Natural real rate must respect statutory upper bound.');

        // 2. Depressed TFP growth (0.0% secular stagnation) -> r* should drift downward
        $stagnationState = new \App\Service\Macro\MacroState();
        $stagnationState->naturalRate = MacroEngine::BASE_NATURAL_RATE;
        $this->aggregateSubsystem->calculateNaturalRate($stagnationState, 0.00, $dt);

        $this->assertLessThan(MacroEngine::BASE_NATURAL_RATE, $stagnationState->naturalRate, 'Natural real rate r* must drift downward during secular productivity stagnation.');
        $this->assertGreaterThanOrEqual(MacroEngine::MIN_NATURAL_RATE, $stagnationState->naturalRate, 'Natural real rate must respect statutory floor.');
    }

    public function testQuantitativeTighteningRunsOffAPortfolioAndNeverGoesNetShort(): void
    {
        // Overheating expansion: output gap +2.5% (> 1.0%), inflation 3.5% (> 2.2%)
        $overheatingState = new \App\Service\Macro\MacroState();
        $overheatingState->outputGap = 0.025;
        $overheatingState->inflation = 0.035;
        $overheatingState->policyRate = 0.035;
        $overheatingState->tipsBreakeven = 0.030;
        $overheatingState->balanceSheetIntensity = 0.0;

        $noPortfolio = $this->monetarySubsystem->calculateBalanceSheetOperations($overheatingState, 1.0);

        // A bank that never bought anything has nothing to sell. Letting the intensity go negative here made it
        // net short duration -- a 60bp+ lift on the thirty-year out of a portfolio that was never acquired.
        $this->assertEquals(0.0, $noPortfolio['new_balance_sheet_intensity'], 'Overheating must not create a tightening position from a zero balance sheet.');
        $this->assertEquals(0.0, $noPortfolio['new_qt_intensity'], 'QT intensity requires a portfolio to run off.');

        // With a portfolio in hand, the same overheating runs it down toward zero without overshooting.
        $withPortfolio = clone $overheatingState;
        $withPortfolio->balanceSheetIntensity = 0.008;
        $withPortfolio->balanceSheetHoldTimer = 0.0;

        $runoff = $this->monetarySubsystem->calculateBalanceSheetOperations($withPortfolio, 0.25);

        $this->assertLessThan(0.008, $runoff['new_balance_sheet_intensity'], 'Overheating must cut the reinvestment hold short and start the runoff.');
        $this->assertGreaterThan(0.0, $runoff['new_balance_sheet_intensity'], 'A quarter of runoff must not empty the whole portfolio.');
        $this->assertGreaterThan(0.0, $runoff['new_qt_intensity'], 'QT intensity reports the portfolio being unwound.');

        // Runoff is monotone to zero and stops there, however long the overheating lasts.
        $state = clone $withPortfolio;
        for ($i = 0; $i < 200; $i++) {
            $step = $this->monetarySubsystem->calculateBalanceSheetOperations($state, 0.25);
            $this->assertGreaterThanOrEqual(0.0, $step['new_balance_sheet_intensity'], 'The balance sheet is a stock and can never go net short.');
            $this->assertLessThanOrEqual($state->balanceSheetIntensity + 1e-12, $step['new_balance_sheet_intensity'], 'Runoff must be monotone while overheating persists.');
            $state->balanceSheetIntensity = $step['new_balance_sheet_intensity'];
            $state->balanceSheetHoldTimer = $step['new_hold_timer'];
        }
        $this->assertEqualsWithDelta(0.0, $state->balanceSheetIntensity, 1e-6, 'A sustained runoff settles at zero, not below it.');
    }

    public function testBalanceSheetHoldsThroughReinvestmentThenRunsOff(): void
    {
        // Purchases first: a deep recession at the lower bound, the rule asking for two points the floor forbids.
        $state = new \App\Service\Macro\MacroState();
        $state->outputGap = -0.025;
        $state->inflation = 0.010;
        $state->policyRate = MacroEngine::EFFECTIVE_LOWER_BOUND;
        $state->targetRate = -0.02;
        $state->tipsBreakeven = 0.015;

        for ($i = 0; $i < 8; $i++) {
            $step = $this->monetarySubsystem->calculateBalanceSheetOperations($state, 0.25);
            $state->balanceSheetIntensity = $step['new_balance_sheet_intensity'];
            $state->balanceSheetHoldTimer = $step['new_hold_timer'];
        }
        $peak = $state->balanceSheetIntensity;
        $this->assertGreaterThan(0.0, $peak, 'QE must build a portfolio during a lower-bound recession.');

        // Recovery with no overheating: the stock is held through the reinvestment window, then shed.
        $state->outputGap = 0.002;
        $state->inflation = 0.019;
        $state->policyRate = 0.02;
        $state->targetRate = 0.02;

        $step = $this->monetarySubsystem->calculateBalanceSheetOperations($state, 0.25);
        $this->assertEqualsWithDelta($peak, $step['new_balance_sheet_intensity'], 1e-9, 'The reinvestment hold keeps the portfolio flat.');
        $this->assertEquals(0.0, $step['new_qt_intensity'], 'Holding is not runoff.');

        $state->balanceSheetHoldTimer = \App\Service\Macro\Subsystem\MonetaryPolicySubsystem::BALANCE_SHEET_REINVESTMENT_HOLD_YEARS;
        $step = $this->monetarySubsystem->calculateBalanceSheetOperations($state, 0.25);
        $this->assertLessThan($peak, $step['new_balance_sheet_intensity'], 'Once the hold expires the portfolio runs off.');
        $this->assertGreaterThan(0.0, $step['new_qt_intensity'], 'Passive runoff still reports QT intensity.');
    }

    public function testOverheatingAcceleratesRunoffWithoutChangingItsFloor(): void
    {
        $base = new \App\Service\Macro\MacroState();
        $base->balanceSheetIntensity = 0.008;
        $base->balanceSheetHoldTimer = \App\Service\Macro\Subsystem\MonetaryPolicySubsystem::BALANCE_SHEET_REINVESTMENT_HOLD_YEARS;
        $base->policyRate = 0.035;
        $base->tipsBreakeven = 0.02;

        $calm = clone $base;
        $calm->outputGap = 0.0;
        $calm->inflation = 0.02;

        $hot = clone $base;
        $hot->outputGap = 0.030;
        $hot->inflation = 0.040;

        $calmStep = $this->monetarySubsystem->calculateBalanceSheetOperations($calm, 0.25);
        $hotStep = $this->monetarySubsystem->calculateBalanceSheetOperations($hot, 0.25);

        $this->assertLessThan($calmStep['new_balance_sheet_intensity'], $hotStep['new_balance_sheet_intensity'], 'Overheating must shed duration faster than a passive runoff.');
        $this->assertGreaterThan(0.0, $hotStep['new_balance_sheet_intensity'], 'Faster is not unbounded: the floor is still zero.');
    }

    public function testACMTermPremiumDecompositionIntegrity(): void
    {
        $state = new \App\Service\Macro\MacroState();
        $state->outputGap = 0.01;
        $state->inflation = 0.02;
        $state->policyRate = 0.025;
        $state->tipsBreakeven = 0.02;

        $yieldData = $this->monetarySubsystem->calculateYieldCurveAndQE($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        $yield10y = $yieldData['yield_10y'];
        $riskNeutral10y = $yieldData['risk_neutral_10y'];
        $termPremium10y = $yieldData['term_premium_10y'];

        // Identity check: 10Y Yield == Risk Neutral Path + Term Premium
        $this->assertEqualsWithDelta($yield10y, $riskNeutral10y + $termPremium10y, 0.0001, 'ACM decomposition identity must hold: y10 = RN10 + TP10.');
    }

    public function testForwardLookingBetaCurvatureLeadsPolicyRate(): void
    {
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

        $yieldDataHiking = $this->monetarySubsystem->calculateYieldCurveAndQE($hikingState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $yieldDataNeutral = $this->monetarySubsystem->calculateYieldCurveAndQE($neutralState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $yieldDataEasing = $this->monetarySubsystem->calculateYieldCurveAndQE($easingState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        // β₂ (curvature) must hump upward during a hiking cycle and dip during an easing cycle
        $this->assertGreaterThan($yieldDataNeutral['curvature'], $yieldDataHiking['curvature'], 'Curvature beta2 must rise during a central bank rate hike cycle.');
        $this->assertLessThan(0.0, $yieldDataEasing['curvature'], 'Curvature beta2 must turn negative during a central bank emergency cutting cycle.');

        // 2Y yield must price in expected hikes ahead of policy rate moves
        $this->assertGreaterThan($yieldDataNeutral['yield_2y'], $yieldDataHiking['yield_2y'], '2Y sovereign yield must rise ahead of central bank rate hikes.');
    }

    public function testInflationRiskPremiumExpandsTermPremiumWhenBreakevenElevated(): void
    {
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

        $yieldDataAnchored = $this->monetarySubsystem->calculateYieldCurveAndQE($stateAnchored, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $yieldDataUnanchored = $this->monetarySubsystem->calculateYieldCurveAndQE($stateUnanchored, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        // Term premium must expand when inflation expectations un-anchor and volatility spikes (Wright 2011 IRP)
        $this->assertGreaterThan(
            $yieldDataAnchored['term_premium_10y'],
            $yieldDataUnanchored['term_premium_10y'],
            '10Y term premium must expand when inflation breakeven exceeds target and macro volatility is elevated.'
        );
    }

    public function testFlightToSafetyCompressesTermPremiumDuringRecession(): void
    {
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

        $yieldDataNeutral = $this->monetarySubsystem->calculateYieldCurveAndQE($stateNeutral, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $yieldDataRecession = $this->monetarySubsystem->calculateYieldCurveAndQE($stateRecession, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        // Flight-to-safety: recessions compress sovereign term premium as investors seek safe duration
        $this->assertLessThan(
            $yieldDataNeutral['term_premium_10y'],
            $yieldDataRecession['term_premium_10y'],
            'Recessions must compress duration term premium via flight-to-safety demand (Campbell et al. 2017).'
        );
    }

    public function testBeveridgeCurveWagePhillipsTransmission(): void
    {
        // Tight labor market scenario: unemployment 2.5% (< 4.0% NAIRU)
        $tightState = new \App\Service\Macro\MacroState();
        $tightState->unemploymentRate = 0.025;
        $tightState->wageGrowth = 0.035;

        $this->laborSubsystem->calculateLaborMarketAndWages($tightState, MacroEngine::TFP_DRIFT, 0.5);

        // Job vacancies must rise via Beveridge curve (k / U) and labor tightness must exceed 1.125
        $this->assertGreaterThan(MacroEngine::NATURAL_JOB_VACANCIES, $tightState->jobVacanciesRate, 'Job vacancies rate must rise when unemployment drops below NAIRU.');
        $this->assertGreaterThan(MacroEngine::NATURAL_LABOR_TIGHTNESS, $tightState->laborTightness, 'Labor market tightness (V/U) must exceed equilibrium in a labor shortage.');
        $this->assertGreaterThan(0.035, $tightState->wageGrowth, 'Wage growth must accelerate when the labor market is tight.');

        // Inflation pass-through check
        $tightState->outputGap = 0.01;
        $newInflation = $this->aggregateSubsystem->calculateInflation($tightState, MacroEngine::TARGET_INFLATION, MacroEngine::TFP_DRIFT, 0.25);
        $this->assertGreaterThan(0.020, $newInflation, 'Excess wage growth must pass through into headline services inflation.');
    }

    public function testStochasticTfpDiffusionAndEndogenousSpillover(): void
    {
        // 1. Positive innovation shock + economic boom -> accelerated TFP growth
        $boomState = new \App\Service\Macro\MacroState();
        $boomState->totalFactorProductivityIndex = 100.0;
        $boomState->outputGapEma = 0.03; // 3% boom

        $this->mathUtilityMock->method('generateStandardNormal')->willReturnOnConsecutiveCalls(1.5, -3.0);
        $this->aggregateSubsystem->calculateTotalFactorProductivity($boomState, 0.25);

        $baselineDeterministicTfp = 100.0 * exp(MacroEngine::TFP_DRIFT * 0.25);
        $this->assertGreaterThan($baselineDeterministicTfp, $boomState->totalFactorProductivityIndex, 'Positive innovation shock and expansion must accelerate TFP accumulation.');

        // 2. Severe recession + negative productivity shock: the contraction arrives at its drawn size
        $slumpState = new \App\Service\Macro\MacroState();
        $slumpState->totalFactorProductivityIndex = 100.0;
        $slumpState->outputGapEma = -0.05; // 5% recession

        $this->aggregateSubsystem->calculateTotalFactorProductivity($slumpState, 0.25);

        $this->assertLessThan(100.0, $slumpState->totalFactorProductivityIndex, 'TFP can and should contract during severe recessions with negative shocks.');

        // As above: the annual growth-rate band bounds the trend rate, never the realized increment.
        $rateFloorOverTheQuarter = 100.0 * exp(MacroEngine::MIN_TFP_GROWTH_RATE * 0.25);
        $this->assertLessThan($rateFloorOverTheQuarter, $slumpState->totalFactorProductivityIndex, 'A three-sigma innovation must reach the index at full size, not be clipped to the annual growth-rate band.');
    }

    /** r* follows the trend growth of potential, which the labour force sets beside productivity, and not the cycle. */
    public function testNaturalRateFollowsTrendGrowthAndNotTheCycle(): void
    {
        $aggregate = new \App\Service\Macro\Subsystem\MacroAggregateSubsystem(new MathUtility());

        $boom = new \App\Service\Macro\MacroState();
        $boom->outputGap = 0.03;
        $boom->outputGapEma = 0.03;
        $aggregate->calculateNaturalRate($boom, MacroEngine::TFP_DRIFT, 0.5);
        $this->assertSame(MacroEngine::BASE_NATURAL_RATE, $boom->naturalRate, 'A boom is not a higher natural rate.');

        $growing = new \App\Service\Macro\MacroState();
        $growing->laborForceGrowthRate = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + 0.01;
        $aggregate->calculateNaturalRate($growing, MacroEngine::TFP_DRIFT, 0.5);
        $this->assertGreaterThan(MacroEngine::BASE_NATURAL_RATE, $growing->naturalRate, 'Faster trend growth raises r*.');
    }

    public function testSymmetricWagePushAndWageDragInflationTransmission(): void
    {
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

        $hotInflation = $this->aggregateSubsystem->calculateInflation($hotWageState, MacroEngine::TARGET_INFLATION, MacroEngine::TFP_DRIFT, 0.25);
        $this->assertGreaterThan(0.02, $hotInflation, 'Excess wage growth above 3.5% must exert positive cost-push inflation pressure.');

        // 2. Slack wage scenario (w = 2.5% < 3.5% trend)
        $slackWageState = new \App\Service\Macro\MacroState();
        $slackWageState->inflation = 0.02;
        $slackWageState->inflationEma = 0.02;
        $slackWageState->outputGap = 0.0;
        $slackWageState->wageGrowth = 0.025; // -1.0% slack wage growth
        $slackWageState->energyPriceShock = 0.0;
        $slackWageState->energyCostPushLag = 0.0;

        $slackInflation = $this->aggregateSubsystem->calculateInflation($slackWageState, MacroEngine::TARGET_INFLATION, MacroEngine::TFP_DRIFT, 0.25);
        $this->assertLessThan(0.02, $slackInflation, 'Slack wage growth below 3.5% must exert symmetric disinflationary wage-drag.');

        // 3. Check symmetry of transmission magnitude around 2.0%
        $hotDelta = $hotInflation - 0.02;
        $slackDelta = 0.02 - $slackInflation;
        $this->assertEqualsWithDelta($hotDelta, $slackDelta, 0.0001, 'Wage inflation transmission must be symmetric for equal deviations above and below trend.');
    }

    public function testTfpTrendGrowthRatePassesCleanlyWithoutDiffusionNoise(): void
    {
        // Neutral state: output gap = 0, no R&D acceleration
        $neutralState = new \App\Service\Macro\MacroState();
        $neutralState->totalFactorProductivityIndex = 100.0;
        $neutralState->outputGapEma = 0.0;

        // Severe negative diffusion shock
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(-3.0);

        // Calculate TFP with dt = 1/3600 (tick-level)
        $dt = 1.0 / 3600.0;
        $trendRate = $this->aggregateSubsystem->calculateTotalFactorProductivity($neutralState, $dt);

        // The trend growth rate returned for economic models must be the true structural drift (1.5%), unaffected by tick diffusion
        $this->assertEqualsWithDelta(MacroEngine::TFP_DRIFT, $trendRate, 0.0001, 'TFP trend growth rate must equal structural secular drift at neutral output gap.');
    }

    public function testYieldCurveInvertsAtPeakOfTighteningCycleAndSteepensDuringEasing(): void
    {
        // 1. Peak tightening cycle: policyRate = 5.25%, targetRate = 5.25%, outputGap = +2.5%, elevated inflation
        $peakState = new \App\Service\Macro\MacroState();
        $peakState->policyRate = 0.0525;
        $peakState->targetRate = 0.0525;
        $peakState->outputGap = 0.025;
        $peakState->inflation = 0.035;
        $peakState->inflationEma = 0.035;
        $peakState->tipsBreakeven = 0.026;
        $peakState->marketVolatilityEma = 0.16;
        $peakState->termPremiumRegime = 0.0; // a low-premium era, as 2006 and 2023 were; at a 2% premium (1995) the peak stayed positive

        $curvePeak = $this->monetarySubsystem->calculateYieldCurveAndQE($peakState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

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

        $curveEasing = $this->monetarySubsystem->calculateYieldCurveAndQE($easingState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

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

        $gapBaseline = $this->aggregateSubsystem->calculateOutputGap($stateBaseline, $yield5y, $naturalRate, MacroEngine::TARGET_INFLATION, $dt, $stressMultiplier);
        $gapCrisis   = $this->aggregateSubsystem->calculateOutputGap($stateCrisis, $yield5y, $naturalRate, MacroEngine::TARGET_INFLATION, $dt, $stressMultiplier);

        $this->assertLessThan(
            $gapBaseline,
            $gapCrisis,
            'BGG financial accelerator: blown-out credit & interbank spreads must contract output gap more than baseline.'
        );
    }

    public function testStagflationFromEnergyShock(): void
    {
        $dt = 0.25;
        $stressMultiplier = 1.0;
        $yield5y = 0.04;

        $stateNormal = new \App\Service\Macro\MacroState();
        $stateNormal->energyPriceShock = 0.0;

        $stateShock = clone $stateNormal;
        $stateShock->energyPriceShock = 100.0; // 100% price surge

        $gapNormal = $this->aggregateSubsystem->calculateOutputGap($stateNormal, $yield5y, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, $stressMultiplier);
        $gapShock  = $this->aggregateSubsystem->calculateOutputGap($stateShock, $yield5y, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, $dt, $stressMultiplier);

        $this->assertLessThan($gapNormal, $gapShock, 'Supply-side stagflation: energy price spike must drag down output gap.');

        $infNormal = $this->aggregateSubsystem->calculateInflation($stateNormal, MacroEngine::TARGET_INFLATION, MacroEngine::TFP_DRIFT, $dt);
        $infShock  = $this->aggregateSubsystem->calculateInflation($stateShock, MacroEngine::TARGET_INFLATION, MacroEngine::TFP_DRIFT, $dt);

        $this->assertGreaterThan($infNormal, $infShock, 'Cost-push channel: energy price spike must raise headline inflation.');
    }

    public function testFoodCpiChannelFromAgriculturalSpike(): void
    {
        $dt = 0.25;

        $stateNormal = new \App\Service\Macro\MacroState();
        $stateNormal->agriculturalCommodityIndex = 100.0;

        $stateSpike = clone $stateNormal;
        $stateSpike->agriculturalCommodityIndex = 200.0; // 100% agri commodity surge

        $infNormal = $this->aggregateSubsystem->calculateInflation($stateNormal, MacroEngine::TARGET_INFLATION, MacroEngine::TFP_DRIFT, $dt);
        $infSpike  = $this->aggregateSubsystem->calculateInflation($stateSpike, MacroEngine::TARGET_INFLATION, MacroEngine::TFP_DRIFT, $dt);

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

        $stateLowDebt->sovereignRiskSpreadEma = 0.0;

        // Debt reaches the curve through the premium the market charges on it (Laubach 2009): ~1pp at 120% of GDP.
        $stateHighDebt = clone $stateLowDebt;
        $stateHighDebt->sovereignDebtToGdpEma = 1.20;
        $stateHighDebt->sovereignRiskSpreadEma = 0.01;

        $curveLow  = $monetarySubsystem->calculateYieldCurveAndQE($stateLowDebt, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, $dt);
        $curveHigh = $monetarySubsystem->calculateYieldCurveAndQE($stateHighDebt, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, $dt);

        $this->assertGreaterThan(
            $curveLow['yield_30y'],
            $curveHigh['yield_30y'],
            'The sovereign premium on high debt must lift long-end yields.'
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

        $assetSubsystem->calculateFinancialConditionsIndex($stateCrisis);

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

        $assetSubsystem->calculateFinancialConditionsIndex($stateGoldilocks);

        $this->assertLessThan(
            -0.10,
            $stateGoldilocks->financialConditionsIndex,
            'Goldilocks conditions (tight spreads, steep curve, low vol) must produce negative (accommodative) FCI.'
        );
    }

    public function testNormalEconomyProducesUpwardSlopingYieldCurveAndTighteningProducesInversion(): void
    {
        $monetarySubsystem = new \App\Service\Macro\Subsystem\MonetaryPolicySubsystem($this->mathUtilityMock);
        $dt = 0.25;

        // 1. Normal, balanced macroeconomic state:
        // Output gap = 0, Inflation = 2%, Policy Rate = 3.5% (neutral r* 1.5% + pi* 2.0%)
        $normalState = new \App\Service\Macro\MacroState();
        $normalState->outputGap = 0.0;
        $normalState->outputGapEma = 0.0;
        $normalState->inflation = MacroEngine::TARGET_INFLATION;
        $normalState->inflationEma = MacroEngine::TARGET_INFLATION;
        $normalState->policyRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;
        $normalState->targetRate = $normalState->policyRate;
        $normalState->tipsBreakeven = MacroEngine::TARGET_INFLATION;
        $normalState->marketVolatilityEma = MacroEngine::MACRO_VOL_BASE_ANCHOR;

        $curveNormal = $monetarySubsystem->calculateYieldCurveAndQE($normalState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, $dt);

        // In a normal economy, the yield curve must be upward sloping (2Y < 5Y < 10Y < 30Y)
        $this->assertLessThan($curveNormal['yield_5y'], $curveNormal['yield_2y'], 'In a normal economy, 2Y yield must be below 5Y yield.');
        $this->assertLessThan($curveNormal['yield_10y'], $curveNormal['yield_5y'], 'In a normal economy, 5Y yield must be below 10Y yield.');
        $this->assertLessThan($curveNormal['yield_30y'], $curveNormal['yield_10y'], 'In a normal economy, 10Y yield must be below 30Y yield.');
        $spread2s10sNormal = $curveNormal['yield_10y'] - $curveNormal['yield_2y'];
        $this->assertGreaterThan(0.0030, $spread2s10sNormal, '2s10s spread must be positive and healthy in normal economic conditions.');

        // 2. Late-cycle restrictive overtightening:
        // Policy rate = 5.25%, inflation slowing to 2.4%, output gap cooling
        $tightState = new \App\Service\Macro\MacroState();
        $tightState->outputGap = 0.010;
        $tightState->outputGapEma = 0.010;
        $tightState->inflation = 0.024;
        $tightState->inflationEma = 0.024;
        $tightState->policyRate = 0.0525;
        $tightState->targetRate = 0.0400; // Target rate cooling as inflation contained
        $tightState->tipsBreakeven = 0.022;
        $tightState->marketVolatilityEma = 0.18;
        $tightState->termPremiumRegime = 0.0; // a low-premium late cycle, as 2006-07 was

        $curveTight = $monetarySubsystem->calculateYieldCurveAndQE($tightState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, $dt);

        // 2s10s yield spread must invert during late-cycle overtightening
        $spread2s10sTight = $curveTight['yield_10y'] - $curveTight['yield_2y'];
        $this->assertLessThan(0.0, $spread2s10sTight, '2s10s spread must invert when policy rate is overtightened.');
    }

    public function testQuantitativeEasingActivatesWhenTheRuleAsksForARateBelowTheFloor(): void
    {
        $monetarySubsystem = new \App\Service\Macro\Subsystem\MonetaryPolicySubsystem($this->mathUtilityMock);
        $dt = 0.25;

        // At the floor in a -2% slump, the rule asking for -1.5%
        $recessionState = new \App\Service\Macro\MacroState();
        $recessionState->outputGap = -0.020;
        $recessionState->policyRate = MacroEngine::EFFECTIVE_LOWER_BOUND;
        $recessionState->targetRate = -0.015;
        $recessionState->inflation = 0.012;
        $recessionState->tipsBreakeven = 0.015;

        $yieldData = $monetarySubsystem->calculateYieldCurveAndQE($recessionState, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, $dt);

        $this->assertGreaterThan(0.0, $yieldData['new_balance_sheet_intensity'], 'QE intensity must expand when the rule asks for a rate below the floor.');
        $this->assertGreaterThan(0.0, $yieldData['new_qe_intensity'], 'QE asset purchase intensity must be positive.');
        $this->assertEquals(0.0, $yieldData['new_qt_intensity'], 'QT runoff must be zero when QE is active.');
    }

    public function testInterbankLiquiditySpreadCoupledToTheBondPremium(): void
    {
        $creditSubsystem = new \App\Service\Macro\Subsystem\CreditFiscalSubsystem($this->mathUtilityMock);
        $dt = 0.25;

        $stateCalm = new \App\Service\Macro\MacroState();
        $stateCalm->interbankLiquiditySpread = MacroEngine::INTERBANK_BASELINE_SPREAD;
        $stateCalm->marketVolatilityEma = MacroEngine::MACRO_VOL_BASE_ANCHOR;

        $stateStressed = new \App\Service\Macro\MacroState();
        $stateStressed->excessBondPremium = 0.030; // a 2008-scale premium
        $stateStressed->interbankLiquiditySpread = MacroEngine::INTERBANK_BASELINE_SPREAD;
        $stateStressed->marketVolatilityEma = 0.30;

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);

        $creditSubsystem->calculateInterbankLiquiditySpread($stateCalm, $dt);
        $creditSubsystem->calculateInterbankLiquiditySpread($stateStressed, $dt);

        $this->assertGreaterThan(
            $stateCalm->interbankLiquiditySpread,
            $stateStressed->interbankLiquiditySpread,
            'Wholesale interbank liquidity spread must widen when lenders reprice credit risk.'
        );
    }

    public function testConsumerSentimentDropsRealisticallyDuringRecession(): void
    {
        $assetSubsystem = new \App\Service\Macro\Subsystem\AssetMarketSubsystem($this->mathUtilityMock);
        $dt = 0.25;

        $stateNormal = new \App\Service\Macro\MacroState();
        $stateNormal->outputGap = 0.0;
        $stateNormal->inflation = MacroEngine::TARGET_INFLATION;
        $stateNormal->inflationEma = MacroEngine::TARGET_INFLATION;
        $stateNormal->unemploymentRate = MacroEngine::NATURAL_UNEMPLOYMENT;
        $stateNormal->unemploymentRateEma = MacroEngine::NATURAL_UNEMPLOYMENT;
        $stateNormal->consumerSentimentIndex = MacroEngine::SENTIMENT_BASELINE;

        $stateCrisis = new \App\Service\Macro\MacroState();
        $stateCrisis->outputGap = -0.025; // -2.5% contraction
        $stateCrisis->inflation = 0.040; // 4% inflation (stagflation shock)
        $stateCrisis->inflationEma = 0.030;
        $stateCrisis->unemploymentRate = 0.055;
        $stateCrisis->unemploymentRateEma = 0.045;
        $stateCrisis->consumerSentimentIndex = MacroEngine::SENTIMENT_BASELINE;

        $assetSubsystem->calculateConsumerSentiment($stateNormal, $dt);
        $assetSubsystem->calculateConsumerSentiment($stateCrisis, $dt);

        $this->assertLessThan(
            $stateNormal->consumerSentimentIndex,
            $stateCrisis->consumerSentimentIndex,
            'Consumer sentiment must decline during economic contractions and inflation surges.'
        );
        $this->assertLessThan(
            90.0,
            $stateCrisis->consumerSentimentIndex,
            'Consumer sentiment must reflect meaningful distress (< 90 pts) during a stagflation contraction.'
        );
    }

    public function testTaylorRuleTargetRateIncorporatesDynamicNaturalRate(): void
    {
        // Setup a state with higher dynamic natural rate (3.0% vs baseline 1.5%)
        $stateHighRstar = [
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'output_gap' => 0.0,
            'output_gap_ema' => 0.0,
            'natural_rate' => 0.030, // Elevated dynamic r*
            'natural_rate_ema' => 0.030,
            'policy_rate' => 0.035,
            'policy_rate_ema' => 0.035,
            'total_factor_productivity_index' => 100.0,
        ];

        // State with neutral baseline natural rate (1.5%)
        $stateBaseRstar = [
            'inflation' => 0.02,
            'inflation_ema' => 0.02,
            'output_gap' => 0.0,
            'output_gap_ema' => 0.0,
            'natural_rate' => MacroEngine::BASE_NATURAL_RATE,
            'natural_rate_ema' => MacroEngine::BASE_NATURAL_RATE,
            'policy_rate' => 0.035,
            'policy_rate_ema' => 0.035,
            'total_factor_productivity_index' => 100.0,
        ];

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $this->redisMock->method('get')->willReturnOnConsecutiveCalls(
            json_encode($stateHighRstar),
            json_encode($stateBaseRstar)
        );

        $dtoHigh = $this->engine->updateMacroState(0.25);
        $dtoBase = $this->engine->updateMacroState(0.25);

        // Target rate with higher dynamic r* must be systematically higher than with baseline r*
        $this->assertGreaterThan(
            $dtoBase->targetRate,
            $dtoHigh->targetRate,
            'Taylor rule target rate must incorporate dynamic natural rate (r*) rather than discarding it.'
        );
        // Specifically, the ~150 bps r* spread should reflect in targetRate
        $this->assertEqualsWithDelta(
            $dtoBase->targetRate + (0.030 - MacroEngine::BASE_NATURAL_RATE),
            $dtoHigh->targetRate,
            0.005,
            'Taylor rule intercept must shift 1:1 with dynamic r*.'
        );
    }

    public function testRedisPersistenceRecoversFromCorruptOrInvalidState(): void
    {
        // 1. Corrupt JSON string
        $this->redisMock->method('get')->willReturnOnConsecutiveCalls(
            '{invalid json payload!!}',
            'null',
            '"not an array"',
            ''
        );

        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        // Each call should gracefully return a valid MacroStateDTO without fatal TypeError
        $dto1 = $this->engine->updateMacroState(0.25);
        $this->assertInstanceOf(\App\DTO\MacroStateDTO::class, $dto1);

        $dto2 = $this->engine->updateMacroState(0.25);
        $this->assertInstanceOf(\App\DTO\MacroStateDTO::class, $dto2);

        $dto3 = $this->engine->updateMacroState(0.25);
        $this->assertInstanceOf(\App\DTO\MacroStateDTO::class, $dto3);

        $dto4 = $this->engine->updateMacroState(0.25);
        $this->assertInstanceOf(\App\DTO\MacroStateDTO::class, $dto4);
    }

    public function testPolicyRateCeilingAndOverhangBoundsConstants(): void
    {
        $this->assertEquals(0.20, MacroEngine::POLICY_RATE_CEILING);
        $this->assertEquals(0.0005, MacroEngine::BALANCE_SHEET_ACTIVE_THRESHOLD);
        $this->assertEquals(-0.15, MacroEngine::CAPITAL_OVERHANG_MIN);
        $this->assertEquals(0.15, MacroEngine::CAPITAL_OVERHANG_MAX);
        $this->assertEquals(10.0, MacroEngine::STRESS_MULTIPLIER_GAP_SENSITIVITY);
    }


}
