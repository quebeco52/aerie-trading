<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use PHPUnit\Framework\TestCase;

class MacroStateDTOTest extends TestCase
{
    public function testFromArrayWithDefaults(): void
    {
        $dto = MacroStateDTO::fromArray([]);

        $this->assertEquals(MacroEngine::TARGET_INFLATION, $dto->inflation);
        $this->assertEquals(MacroEngine::TARGET_INFLATION, $dto->inflationEma);
        $this->assertEquals(0.02, $dto->outputGap);
        $this->assertEquals(0.15, $dto->marketVolatility);
        $this->assertEquals(100.0, $dto->exchangeRateIndex);
        $this->assertEquals(100.0, $dto->industrialMetalsIndex);
        $this->assertEquals(100.0, $dto->governmentSpendingIndex);
        $this->assertEquals(100.0, $dto->commercialPropertyIndex);
        $this->assertEquals(MacroEngine::INTERBANK_BASELINE_SPREAD, $dto->interbankLiquiditySpread);
        $this->assertEquals(MacroEngine::INTERBANK_BASELINE_SPREAD, $dto->interbankLiquiditySpreadEma);
        $this->assertEquals(MacroEngine::TFP_BASELINE, $dto->totalFactorProductivityIndex);
        $this->assertEquals(MacroEngine::TFP_BASELINE, $dto->totalFactorProductivityIndexEma);
        $this->assertEquals(MacroEngine::PMI_BASELINE, $dto->manufacturingPmi);
        $this->assertEquals(MacroEngine::PMI_BASELINE, $dto->manufacturingPmiEma);
        $this->assertEquals(MacroEngine::TARGET_INFLATION, $dto->producerPriceInflation);
        $this->assertEquals(MacroEngine::TARGET_INFLATION, $dto->producerPriceInflationEma);
        $this->assertEquals(MacroEngine::TRADE_BALANCE_BASELINE, $dto->tradeBalanceToGdp);
        $this->assertEquals(MacroEngine::TRADE_BALANCE_BASELINE, $dto->tradeBalanceToGdpEma);
        $this->assertEquals(MacroEngine::HOUSING_STARTS_BASELINE, $dto->housingStartsIndex);
        $this->assertEquals(MacroEngine::HOUSING_STARTS_BASELINE, $dto->housingStartsIndexEma);
        $this->assertEquals(MacroEngine::M2_BASE_GROWTH, $dto->moneySupplyGrowth);
        $this->assertEquals(MacroEngine::M2_BASE_GROWTH, $dto->moneySupplyGrowthEma);
        $this->assertFalse($dto->qeActive);
        $this->assertNull($dto->eventType);
    }

    public function testFromArrayAndToArrayRoundtrip(): void
    {
        $payload = [
            'output_gap' => 0.03,
            'output_gap_ema' => 0.025,
            'inflation' => 0.025,
            'inflation_ema' => 0.024,
            'policy_rate' => 0.045,
            'policy_rate_ema' => 0.044,
            'target_rate' => 0.045,
            'yield_2y' => 0.046,
            'yield_2y_ema' => 0.045,
            'yield_5y' => 0.048,
            'yield_5y_ema' => 0.047,
            'yield_10y' => 0.05,
            'yield_10y_ema' => 0.049,
            'yield_30y' => 0.052,
            'yield_30y_ema' => 0.051,
            'exchange_rate_index' => 105.5,
            'exchange_rate_index_ema' => 104.2,
            'industrial_metals_index' => 112.0,
            'industrial_metals_index_ema' => 110.0,
            'government_spending_index' => 95.0,
            'government_spending_index_ema' => 96.0,
            'commercial_property_index' => 88.5,
            'commercial_property_index_ema' => 90.0,
            'market_volatility' => 0.22,
            'market_volatility_ema' => 0.20,
            'market_z' => 1.2,
            'corporate_tax_rate' => 0.21,
            'equity_risk_premium' => 0.05,
            'macro_credit_spread' => 0.02,
            'macro_credit_spread_ema' => 0.019,
            'qe_active' => true,
            'ns_level' => 0.04,
            'ns_slope' => -0.01,
            'ns_slope_ema' => -0.01,
            'ns_curvature' => 0.005,
            'potential_gdp_index' => 1.05,
            'nominal_gdp_index' => 1.08,
            'interbank_liquidity_spread' => 0.0035,
            'interbank_liquidity_spread_ema' => 0.0030,
            'total_factor_productivity_index' => 105.0,
            'total_factor_productivity_index_ema' => 104.0,
            'manufacturing_pmi' => 54.2,
            'manufacturing_pmi_ema' => 53.8,
            'producer_price_inflation' => 0.028,
            'producer_price_inflation_ema' => 0.026,
            'trade_balance_to_gdp' => -0.030,
            'trade_balance_to_gdp_ema' => -0.028,
            'housing_starts_index' => 115.0,
            'housing_starts_index_ema' => 112.0,
            'money_supply_growth' => 0.065,
            'money_supply_growth_ema' => 0.062,
            'event_type' => 'TIGHTENING',
        ];

        $dto = MacroStateDTO::fromArray($payload);

        $this->assertEquals(0.03, $dto->outputGap);
        $this->assertEquals(105.5, $dto->exchangeRateIndex);
        $this->assertEquals(112.0, $dto->industrialMetalsIndex);
        $this->assertEquals(95.0, $dto->governmentSpendingIndex);
        $this->assertEquals(88.5, $dto->commercialPropertyIndex);
        $this->assertEquals(0.0035, $dto->interbankLiquiditySpread);
        $this->assertEquals(0.0030, $dto->interbankLiquiditySpreadEma);
        $this->assertEquals(105.0, $dto->totalFactorProductivityIndex);
        $this->assertEquals(104.0, $dto->totalFactorProductivityIndexEma);
        $this->assertEquals(54.2, $dto->manufacturingPmi);
        $this->assertEquals(53.8, $dto->manufacturingPmiEma);
        $this->assertEquals(0.028, $dto->producerPriceInflation);
        $this->assertEquals(0.026, $dto->producerPriceInflationEma);
        $this->assertEquals(-0.030, $dto->tradeBalanceToGdp);
        $this->assertEquals(-0.028, $dto->tradeBalanceToGdpEma);
        $this->assertEquals(115.0, $dto->housingStartsIndex);
        $this->assertEquals(112.0, $dto->housingStartsIndexEma);
        $this->assertEquals(0.065, $dto->moneySupplyGrowth);
        $this->assertEquals(0.062, $dto->moneySupplyGrowthEma);
        $this->assertTrue($dto->qeActive);
        $this->assertEquals('TIGHTENING', $dto->eventType);

        $exported = $dto->toArray();
        $this->assertEquals($payload['output_gap'], $exported['output_gap']);
        $this->assertEquals($payload['exchange_rate_index'], $exported['exchange_rate_index']);
        $this->assertEquals($payload['industrial_metals_index'], $exported['industrial_metals_index']);
        $this->assertEquals($payload['government_spending_index'], $exported['government_spending_index']);
        $this->assertEquals($payload['commercial_property_index'], $exported['commercial_property_index']);
        $this->assertEquals($payload['interbank_liquidity_spread'], $exported['interbank_liquidity_spread']);
        $this->assertEquals($payload['interbank_liquidity_spread_ema'], $exported['interbank_liquidity_spread_ema']);
        $this->assertEquals($payload['total_factor_productivity_index'], $exported['total_factor_productivity_index']);
        $this->assertEquals($payload['total_factor_productivity_index_ema'], $exported['total_factor_productivity_index_ema']);
        $this->assertEquals($payload['manufacturing_pmi'], $exported['manufacturing_pmi']);
        $this->assertEquals($payload['manufacturing_pmi_ema'], $exported['manufacturing_pmi_ema']);
        $this->assertEquals($payload['producer_price_inflation'], $exported['producer_price_inflation']);
        $this->assertEquals($payload['producer_price_inflation_ema'], $exported['producer_price_inflation_ema']);
        $this->assertEquals($payload['trade_balance_to_gdp'], $exported['trade_balance_to_gdp']);
        $this->assertEquals($payload['trade_balance_to_gdp_ema'], $exported['trade_balance_to_gdp_ema']);
        $this->assertEquals($payload['housing_starts_index'], $exported['housing_starts_index']);
        $this->assertEquals($payload['housing_starts_index_ema'], $exported['housing_starts_index_ema']);
        $this->assertEquals($payload['money_supply_growth'], $exported['money_supply_growth']);
        $this->assertEquals($payload['money_supply_growth_ema'], $exported['money_supply_growth_ema']);
        $this->assertEquals($payload['qe_active'], $exported['qe_active']);
        $this->assertEquals($payload['event_type'], $exported['event_type']);
    }

    public function testFromMacroState(): void
    {
        $state = new MacroState();
        $state->outputGap = 0.04;
        $state->inflation = 0.03;
        $state->exchangeRateIndex = 108.0;
        $state->industrialMetalsIndex = 115.0;
        $state->governmentSpendingIndex = 92.0;
        $state->commercialPropertyIndex = 85.0;
        $state->interbankLiquiditySpread = 0.0040;
        $state->interbankLiquiditySpreadEma = 0.0038;
        $state->totalFactorProductivityIndex = 110.0;
        $state->totalFactorProductivityIndexEma = 108.5;
        $state->manufacturingPmi = 58.0;
        $state->manufacturingPmiEma = 57.0;
        $state->producerPriceInflation = 0.035;
        $state->producerPriceInflationEma = 0.033;
        $state->tradeBalanceToGdp = -0.032;
        $state->tradeBalanceToGdpEma = -0.030;
        $state->housingStartsIndex = 130.0;
        $state->housingStartsIndexEma = 125.0;
        $state->moneySupplyGrowth = 0.080;
        $state->moneySupplyGrowthEma = 0.075;
        $state->qeActive = true;
        $state->eventType = 'STIMULUS';

        $dto = MacroStateDTO::fromMacroState($state);

        $this->assertEquals(0.04, $dto->outputGap);
        $this->assertEquals(0.03, $dto->inflation);
        $this->assertEquals(108.0, $dto->exchangeRateIndex);
        $this->assertEquals(115.0, $dto->industrialMetalsIndex);
        $this->assertEquals(92.0, $dto->governmentSpendingIndex);
        $this->assertEquals(85.0, $dto->commercialPropertyIndex);
        $this->assertEquals(0.0040, $dto->interbankLiquiditySpread);
        $this->assertEquals(0.0038, $dto->interbankLiquiditySpreadEma);
        $this->assertEquals(110.0, $dto->totalFactorProductivityIndex);
        $this->assertEquals(108.5, $dto->totalFactorProductivityIndexEma);
        $this->assertEquals(58.0, $dto->manufacturingPmi);
        $this->assertEquals(57.0, $dto->manufacturingPmiEma);
        $this->assertEquals(0.035, $dto->producerPriceInflation);
        $this->assertEquals(0.033, $dto->producerPriceInflationEma);
        $this->assertEquals(-0.032, $dto->tradeBalanceToGdp);
        $this->assertEquals(-0.030, $dto->tradeBalanceToGdpEma);
        $this->assertEquals(130.0, $dto->housingStartsIndex);
        $this->assertEquals(125.0, $dto->housingStartsIndexEma);
        $this->assertEquals(0.080, $dto->moneySupplyGrowth);
        $this->assertEquals(0.075, $dto->moneySupplyGrowthEma);
        $this->assertTrue($dto->qeActive);
        $this->assertEquals('STIMULUS', $dto->eventType);
    }
}
