<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroState;
use App\Service\Math\MacroConstants;

/**
 * Fluent builder for creating isolated, deterministic MacroState and MacroStateDTO instances in tests.
 */
class MacroStateBuilder
{
    private MacroState $state;

    public function __construct()
    {
        $this->state = new MacroState();
        $this->state->inflation = MacroConstants::TARGET_INFLATION;
        $this->state->inflationEma = MacroConstants::TARGET_INFLATION;
        $this->state->outputGap = 0.0;
        $this->state->outputGapEma = 0.0;
        $this->state->policyRate = MacroConstants::BASE_NATURAL_RATE + MacroConstants::TARGET_INFLATION;
        $this->state->policyRateEma = $this->state->policyRate;
        $this->state->naturalRate = MacroConstants::BASE_NATURAL_RATE;
        $this->state->marketVolatility = 0.15;
        $this->state->marketVolatilityEma = 0.15;
        $this->state->unemploymentRate = MacroConstants::NATURAL_UNEMPLOYMENT;
        $this->state->macroCreditSpread = MacroConstants::BASE_CREDIT_SPREAD;
        $this->state->macroCreditSpreadEma = MacroConstants::BASE_CREDIT_SPREAD;
        $this->state->highYieldCreditSpread = MacroConstants::BASE_CREDIT_SPREAD * MacroConstants::HY_BASE_SPREAD_MULTIPLIER;
        $this->state->highYieldCreditSpreadEma = $this->state->highYieldCreditSpread;
        $this->state->yield10y = 0.040;
        $this->state->yield2y = 0.038;
        $this->state->supercoreInflation = MacroConstants::TARGET_INFLATION;
        $this->state->supercoreInflationEma = MacroConstants::TARGET_INFLATION;
        $this->state->coreGoodsInflation = MacroConstants::TARGET_INFLATION;
        $this->state->coreGoodsInflationEma = MacroConstants::TARGET_INFLATION;
        $this->state->inventoryStockGap = 0.0;
        $this->state->inventoryStockGapEma = 0.0;
        $this->state->energyInventoryIndex = MacroConstants::COMMODITY_INVENTORY_BASELINE;
        $this->state->energyInventoryIndexEma = MacroConstants::COMMODITY_INVENTORY_BASELINE;
        $this->state->manufacturingPmi = MacroConstants::PMI_BASELINE;
        $this->state->manufacturingPmiEma = MacroConstants::PMI_BASELINE;
        $this->state->producerPriceInflation = MacroConstants::TARGET_INFLATION;
        $this->state->producerPriceInflationEma = MacroConstants::TARGET_INFLATION;
        $this->state->tradeBalanceToGdp = MacroConstants::TRADE_BALANCE_BASELINE;
        $this->state->tradeBalanceToGdpEma = MacroConstants::TRADE_BALANCE_BASELINE;
        $this->state->housingStartsIndex = MacroConstants::HOUSING_STARTS_BASELINE;
        $this->state->housingStartsIndexEma = MacroConstants::HOUSING_STARTS_BASELINE;
        $this->state->moneySupplyGrowth = MacroConstants::M2_BASE_GROWTH;
        $this->state->moneySupplyGrowthEma = MacroConstants::M2_BASE_GROWTH;
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
        $this->state->manufacturingPmi = 56.5;
        $this->state->manufacturingPmiEma = 55.0;
        $this->state->producerPriceInflation = 0.035;
        $this->state->producerPriceInflationEma = 0.032;
        $this->state->tradeBalanceToGdp = -0.035;
        $this->state->tradeBalanceToGdpEma = -0.032;
        $this->state->housingStartsIndex = 125.0;
        $this->state->housingStartsIndexEma = 120.0;
        $this->state->moneySupplyGrowth = 0.075;
        $this->state->moneySupplyGrowthEma = 0.070;
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
        $this->state->manufacturingPmi = 42.0;
        $this->state->manufacturingPmiEma = 44.0;
        $this->state->producerPriceInflation = -0.010;
        $this->state->producerPriceInflationEma = 0.005;
        $this->state->tradeBalanceToGdp = -0.015;
        $this->state->tradeBalanceToGdpEma = -0.018;
        $this->state->housingStartsIndex = 65.0;
        $this->state->housingStartsIndexEma = 70.0;
        $this->state->moneySupplyGrowth = 0.015;
        $this->state->moneySupplyGrowthEma = 0.020;
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
        $this->state->manufacturingPmi = 45.0;
        $this->state->manufacturingPmiEma = 46.0;
        $this->state->producerPriceInflation = 0.095;
        $this->state->producerPriceInflationEma = 0.090;
        $this->state->tradeBalanceToGdp = -0.040;
        $this->state->tradeBalanceToGdpEma = -0.038;
        $this->state->housingStartsIndex = 75.0;
        $this->state->housingStartsIndexEma = 80.0;
        $this->state->moneySupplyGrowth = 0.030;
        $this->state->moneySupplyGrowthEma = 0.035;
        return $this;
    }

    public function asCreditCrisis(): self
    {
        $this->state->macroCreditSpread = 0.055;
        $this->state->macroCreditSpreadEma = 0.055;
        $this->state->highYieldCreditSpread = 0.145;
        $this->state->highYieldCreditSpreadEma = 0.145;
        $this->state->interbankLiquiditySpreadEma = 0.0120;
        $this->state->excessBondPremium = 0.025;
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
        // A rate at rest: the Authority's own view sits on its decision.
        $this->state->policyRateShadow = $rate;
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

    public function build(): MacroState
    {
        return clone $this->state;
    }

    public function buildDTO(): MacroStateDTO
    {
        return MacroStateDTO::fromMacroState($this->state);
    }
}
