<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\ModelParam;
use App\Data\StockModelTuning;
use App\DTO\ModelParameters;
use PHPUnit\Framework\TestCase;

class StockModelTuningTest extends TestCase
{
    public function testSilcTickerOverridesAreCorrectlyResolved(): void
    {
        $defaults = [
            ModelParam::FoundryRevenueWeight->value => 0.50,
            ModelParam::DesignRevenueWeight->value  => 0.50,
        ];

        $resolved = StockModelTuning::resolve('SILC', $defaults);

        $this->assertInstanceOf(ModelParameters::class, $resolved);
        $this->assertSame(0.85, $resolved->get(ModelParam::FoundryRevenueWeight));
        $this->assertSame(0.15, $resolved->get(ModelParam::DesignRevenueWeight));
    }

    public function testUnknownTickerFallsBackToDefaults(): void
    {
        $defaults = [
            ModelParam::FoundryRevenueWeight->value => 0.50,
            ModelParam::DesignRevenueWeight->value  => 0.50,
        ];

        $resolved = StockModelTuning::resolve('UNKNOWN_TICKER', $defaults);

        $this->assertSame(0.50, $resolved->get(ModelParam::FoundryRevenueWeight));
        $this->assertSame(0.50, $resolved->get(ModelParam::DesignRevenueWeight));
    }

    public function testGetWithModelParamAndDefault(): void
    {
        $this->assertSame(1.80, StockModelTuning::get('PERE', ModelParam::VixArbitrageScalar, 1.0));
        $this->assertSame(1.00, StockModelTuning::get('UNKNOWN', ModelParam::VixArbitrageScalar, 1.0));
    }

    public function testAllOverridesUseValidModelParamKeys(): void
    {
        foreach (StockModelTuning::OVERRIDES as $ticker => $overrides) {
            foreach (array_keys($overrides) as $key) {
                $enumCase = ModelParam::tryFrom((string) $key);
                $this->assertNotNull(
                    $enumCase,
                    sprintf("Ticker '%s' has an unknown parameter key: '%s'", $ticker, $key)
                );
            }
        }
    }
}
