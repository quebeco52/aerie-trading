<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;

/**
 * Fluent builder for creating isolated, deterministic MacroState and MacroStateDTO instances in tests.
 */
class MacroStateBuilder
{
    private MacroState $state;

    public function __construct()
    {
        $this->state = new MacroState();
        $this->state->inflation = MacroEngine::TARGET_INFLATION;
        $this->state->inflationEma = MacroEngine::TARGET_INFLATION;
        $this->state->outputGap = 0.0;
        $this->state->outputGapEma = 0.0;
        $this->state->policyRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;
        $this->state->policyRateEma = $this->state->policyRate;
        $this->state->naturalRate = MacroEngine::BASE_NATURAL_RATE;
        $this->state->marketVolatility = 0.15;
        $this->state->marketVolatilityEma = 0.15;
        $this->state->unemploymentRate = MacroEngine::NATURAL_UNEMPLOYMENT;
        $this->state->macroCreditSpread = MacroEngine::BASE_CREDIT_SPREAD;
        $this->state->macroCreditSpreadEma = MacroEngine::BASE_CREDIT_SPREAD;
        $this->state->highYieldCreditSpread = MacroEngine::BASE_CREDIT_SPREAD * 2.5;
        $this->state->highYieldCreditSpreadEma = $this->state->highYieldCreditSpread;
        $this->state->yield10y = 0.040;
        $this->state->yield2y = 0.038;
        $this->state->supercoreInflation = MacroEngine::TARGET_INFLATION;
        $this->state->supercoreInflationEma = MacroEngine::TARGET_INFLATION;
        $this->state->coreGoodsInflation = MacroEngine::TARGET_INFLATION;
        $this->state->coreGoodsInflationEma = MacroEngine::TARGET_INFLATION;
        $this->state->inventoryStockGap = 0.0;
        $this->state->inventoryStockGapEma = 0.0;
        $this->state->energyInventoryIndex = MacroEngine::COMMODITY_INVENTORY_BASELINE;
        $this->state->energyInventoryIndexEma = MacroEngine::COMMODITY_INVENTORY_BASELINE;
        $this->state->cumulativeInflationGap = 0.0;
        $this->state->cumulativeInflationGapEma = 0.0;
    }

    public static function create(): self
    {
        return new self();
    }

    public function asExpansion(): self
    {
        $this->state->outputGap = 0.03;
        $this->state->outputGapEma = 0.03;
        $this->state->unemploymentRate = 0.038;
        $this->state->inflation = 0.028;
        $this->state->inflationEma = 0.026;
        $this->state->policyRate = 0.045;
        $this->state->policyRateEma = 0.042;
        $this->state->macroCreditSpread = 0.012;
        $this->state->macroCreditSpreadEma = 0.012;
        $this->state->highYieldCreditSpread = 0.030;
        $this->state->highYieldCreditSpreadEma = 0.030;
        $this->state->marketVolatility = 0.12;
        $this->state->marketVolatilityEma = 0.13;
        return $this;
    }

    public function asRecession(): self
    {
        $this->state->outputGap = -0.045;
        $this->state->outputGapEma = -0.040;
        $this->state->unemploymentRate = 0.075;
        $this->state->inflation = 0.012;
        $this->state->inflationEma = 0.015;
        $this->state->policyRate = 0.010;
        $this->state->policyRateEma = 0.015;
        $this->state->macroCreditSpread = 0.035;
        $this->state->macroCreditSpreadEma = 0.035;
        $this->state->highYieldCreditSpread = 0.095;
        $this->state->highYieldCreditSpreadEma = 0.095;
        $this->state->marketVolatility = 0.32;
        $this->state->marketVolatilityEma = 0.30;
        return $this;
    }

    public function asStagflation(): self
    {
        $this->state->outputGap = -0.035;
        $this->state->outputGapEma = -0.030;
        $this->state->unemploymentRate = 0.070;
        $this->state->inflation = 0.075;
        $this->state->inflationEma = 0.070;
        $this->state->policyRate = 0.065;
        $this->state->policyRateEma = 0.060;
        $this->state->macroCreditSpread = 0.040;
        $this->state->macroCreditSpreadEma = 0.040;
        $this->state->highYieldCreditSpread = 0.110;
        $this->state->highYieldCreditSpreadEma = 0.110;
        $this->state->marketVolatility = 0.35;
        $this->state->marketVolatilityEma = 0.33;
        $this->state->energyPriceIndex = 140.0;
        $this->state->energyPriceIndexEma = 135.0;
        return $this;
    }

    public function asCreditCrisis(): self
    {
        $this->state->macroCreditSpread = 0.055;
        $this->state->macroCreditSpreadEma = 0.055;
        $this->state->highYieldCreditSpread = 0.145;
        $this->state->highYieldCreditSpreadEma = 0.145;
        $this->state->interbankLiquiditySpreadEma = 0.0120;
        $this->state->marketVolatility = 0.45;
        $this->state->marketVolatilityEma = 0.40;
        return $this;
    }

    public function withOutputGap(float $gap): self
    {
        $this->state->outputGap = $gap;
        $this->state->outputGapEma = $gap;
        return $this;
    }

    public function withInflation(float $inflation): self
    {
        $this->state->inflation = $inflation;
        $this->state->inflationEma = $inflation;
        return $this;
    }

    public function withPolicyRate(float $rate): self
    {
        $this->state->policyRate = $rate;
        $this->state->policyRateEma = $rate;
        return $this;
    }

    public function withCreditSpread(float $igSpread, float $hySpread): self
    {
        $this->state->macroCreditSpread = $igSpread;
        $this->state->macroCreditSpreadEma = $igSpread;
        $this->state->highYieldCreditSpread = $hySpread;
        $this->state->highYieldCreditSpreadEma = $hySpread;
        return $this;
    }

    public function withEnergyInventory(float $inventory): self
    {
        $this->state->energyInventoryIndex = $inventory;
        $this->state->energyInventoryIndexEma = $inventory;
        return $this;
    }

    public function withInventoryGap(float $inventoryGap): self
    {
        $this->state->inventoryStockGap = $inventoryGap;
        $this->state->inventoryStockGapEma = $inventoryGap;
        return $this;
    }

    public function withCumulativeInflationGap(float $gap): self
    {
        $this->state->cumulativeInflationGap = $gap;
        $this->state->cumulativeInflationGapEma = $gap;
        return $this;
    }

    public function build(): MacroState
    {
        return clone $this->state;
    }

    public function buildDTO(): MacroStateDTO
    {
        return MacroStateDTO::fromMacroState($this->state);
    }
}
