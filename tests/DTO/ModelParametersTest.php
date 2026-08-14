<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\Data\ModelParam;
use App\DTO\ModelParameters;
use PHPUnit\Framework\TestCase;

class ModelParametersTest extends TestCase
{
    public function testGetWithModelParamEnumAndStringKey(): void
    {
        $params = new ModelParameters([
            ModelParam::FoundryRevenueWeight->value => 0.85,
            ModelParam::DesignRevenueWeight->value  => 0.15,
        ]);

        $this->assertSame(0.85, $params->get(ModelParam::FoundryRevenueWeight));
        $this->assertSame(0.15, $params->get(ModelParam::DesignRevenueWeight));
        $this->assertSame(0.85, $params->get('foundry_revenue_weight'));
        $this->assertSame(0.15, $params->get('design_revenue_weight'));
    }

    public function testArrayAccessSupport(): void
    {
        $params = new ModelParameters([
            ModelParam::PricingPowerIndex->value => 0.75,
        ]);

        $this->assertTrue(isset($params[ModelParam::PricingPowerIndex]));
        $this->assertTrue(isset($params['pricing_power_index']));
        $this->assertFalse(isset($params[ModelParam::VixArbitrageScalar]));

        $this->assertSame(0.75, $params[ModelParam::PricingPowerIndex]);
        $this->assertSame(0.75, $params['pricing_power_index']);

        $params[ModelParam::VixArbitrageScalar] = 1.80;
        $this->assertSame(1.80, $params[ModelParam::VixArbitrageScalar]);
        $this->assertSame(1.80, $params['vix_arbitrage_scalar']);

        unset($params[ModelParam::VixArbitrageScalar]);
        $this->assertFalse(isset($params[ModelParam::VixArbitrageScalar]));
    }

    public function testDefaultFallback(): void
    {
        $params = new ModelParameters([]);

        $this->assertSame(0.50, $params->get(ModelParam::PricingPowerIndex, 0.50));
        $this->assertSame(0.00, $params->getFloat(ModelParam::PricingPowerIndex, 0.00));
    }

    public function testMissingKeyThrowsOutOfBoundsException(): void
    {
        $params = new ModelParameters([]);

        $this->expectException(\OutOfBoundsException::class);
        $params->get(ModelParam::PricingPowerIndex);
    }

    public function testFromFactoryWithMixedKeys(): void
    {
        $params = ModelParameters::from([
            ModelParam::FoundryRevenueWeight => 0.85,
            'design_revenue_weight'          => 0.15,
        ]);

        $this->assertSame(0.85, $params->get(ModelParam::FoundryRevenueWeight));
        $this->assertSame(0.15, $params->get(ModelParam::DesignRevenueWeight));
        $this->assertCount(2, $params);
    }
}
