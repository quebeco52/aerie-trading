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
            'event_type' => 'TIGHTENING',
        ];

        $dto = MacroStateDTO::fromArray($payload);

        $this->assertEquals(0.03, $dto->outputGap);
        $this->assertEquals(105.5, $dto->exchangeRateIndex);
        $this->assertEquals(112.0, $dto->industrialMetalsIndex);
        $this->assertEquals(95.0, $dto->governmentSpendingIndex);
        $this->assertEquals(88.5, $dto->commercialPropertyIndex);
        $this->assertTrue($dto->qeActive);
        $this->assertEquals('TIGHTENING', $dto->eventType);

        $exported = $dto->toArray();
        $this->assertEquals($payload['output_gap'], $exported['output_gap']);
        $this->assertEquals($payload['exchange_rate_index'], $exported['exchange_rate_index']);
        $this->assertEquals($payload['industrial_metals_index'], $exported['industrial_metals_index']);
        $this->assertEquals($payload['government_spending_index'], $exported['government_spending_index']);
        $this->assertEquals($payload['commercial_property_index'], $exported['commercial_property_index']);
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
        $state->qeActive = true;
        $state->eventType = 'STIMULUS';

        $dto = MacroStateDTO::fromMacroState($state);

        $this->assertEquals(0.04, $dto->outputGap);
        $this->assertEquals(0.03, $dto->inflation);
        $this->assertEquals(108.0, $dto->exchangeRateIndex);
        $this->assertEquals(115.0, $dto->industrialMetalsIndex);
        $this->assertEquals(92.0, $dto->governmentSpendingIndex);
        $this->assertEquals(85.0, $dto->commercialPropertyIndex);
        $this->assertTrue($dto->qeActive);
        $this->assertEquals('STIMULUS', $dto->eventType);
    }
}
