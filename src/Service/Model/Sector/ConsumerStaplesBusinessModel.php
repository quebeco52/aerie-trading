<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Consumer Staples (Food, Beverage, Tobacco, Household Goods).
 *
 * Financial Physics:
 * - Price Elasticity of Demand (PED): Staples are highly inelastic. Recessions (output gap drops) barely hurt volume.
 * - Pass-Through Pricing Power: Inflation is passed to consumers, expanding nominal revenue.
 * - Cost-Push Inflation Squeeze (COGS): Variable margins are squeezed by agricultural input spikes and packaging/freight costs.
 * - Dynamic Working Capital Float: Branded goods generate float (-5% NWC) while bulk commodity storage ties up cash (+5% NWC).
 * - Brand Equity Decay: Underinvesting in marketing/CapEx causes permanent operating margin erosion to generic private labels.
 */
class ConsumerStaplesBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for consumer staples analysts. */
    public const BASE_COVERAGE_VISIBILITY = 0.30;
    /** Base coverage forecasting error given steady, predictable cash flows. */
    public const BASE_COVERAGE_ERROR = 0.05;

    // --- Core Sector Structural Constants ---
    /** Baseline secular growth rate tethered to steady population growth and nominal GDP. */
    public const STAPLES_SECULAR_GROWTH = 0.03;
    /** Mild CapEx cyclicality; staples upgrade facilities but avoid heavy industrial boom/bust cycles. */
    public const STAPLES_CAPEX_CYCLICALITY = 0.80;
    /** Weight assigned to EPS surprises (Staples trade on reliable bottom-line earnings). */
    public const SURPRISE_EPS_WEIGHT = 0.75;
    /** Weight assigned to Revenue surprises. */
    public const SURPRISE_REVENUE_WEIGHT = 0.25;
    /** Operating margin mean reversion speed: fast speed reflects intense retail shelf-space price competition. */
    public const STAPLES_REVERSION_SPEED = 5.0;

    // --- Working Capital Intensity Physics ---
    /** Working capital intensity for branded consumer staples with negative cash conversion cycle float. */
    public const BRANDED_NWC_INTENSITY = -0.05;
    /** Working capital intensity for bulk agricultural commodity storage and processing. */
    public const VOLUME_NWC_INTENSITY = 0.05;

    // --- Dual-Stream Consumer Staples Architecture ---
    /** Baseline fraction of revenue derived from premium packaged branded staples and inelastic consumer goods. */
    public const BRANDED_STAPLES_WEIGHT = 0.65;
    /** Baseline fraction of revenue derived from bulk commodity food processing and agricultural volume. */
    public const VOLUME_COMMODITY_WEIGHT = 0.35;

    // --- Inelastic Demand & Macro Physics ---
    /** Baseline Price Elasticity of Demand (PED). < 1.0 means highly inelastic. */
    public const BASELINE_PRICE_ELASTICITY_OF_DEMAND = 0.30;
    /** Volatility multiplier for top-line revenue shocks in stable consumer staples models. */
    public const REVENUE_VARIANCE_SCALAR = 0.05;
    /** Revenue volatility amplifier for commodity trading desks. */
    public const COMMODITY_TRADING_VOL_SCALAR = 2.50;
    /** Revenue volatility dampener for slow-moving land speculation streams. */
    public const LAND_SPECULATION_VOL_SCALAR = 0.50;

    // --- Cost-Push Inflation & COGS Squeeze ---
    /** Variable margin cost penalty scalar for agricultural inflation (Producer Price Index proxy). */
    public const AGRI_INFLATION_COST_SCALAR = 0.015;
    /** Idiosyncratic agricultural harvest shock sensitivity scalar on variable costs. */
    public const AGRI_HARVEST_SHOCK_SCALAR = 0.010;
    /** Variable margin cost penalty scalar for energy-driven logistics, freight, and packaging costs. */
    public const PACKAGING_ENERGY_COST_SCALAR = 0.12;

    // --- Weaponized Proof Desk & Commodity Arbitrage Physics ---
    /** Revenue expansion scalar on commodity trading desk when global inflation accelerates. */
    public const COMMODITY_INFLATION_ALPHA_SCALAR = 2.50;
    /** Revenue expansion scalar on commodity trading desk during energy & packaging price spikes. */
    public const COMMODITY_ENERGY_ALPHA_SCALAR = 0.40;
    /** Maximum fraction of logistics & packaging drag mitigated by physical inventory hoarding. */
    public const MAX_COMMODITY_HEDGE_MITIGATION = 0.80;
    /** Inventory hedging multiplier representing effectiveness of the commodity trading desk. */
    public const COMMODITY_HEDGE_MULTIPLIER = 2.50;

    // --- Product Recall & Regulatory Lore Thresholds ---
    /** Negative z-score threshold indicating severe supply chain contamination and massive product recall. */
    public const RECALL_SEVERE_Z_SCORE = -2.50;
    /** Variable cost penalty applied during massive product recalls and inventory write-offs. */
    public const RECALL_SEVERE_PENALTY = 0.08;
    /** Negative z-score threshold indicating sudden health regulatory scrutiny and fines. */
    public const RECALL_MODERATE_Z_SCORE = -2.00;
    /** Variable cost penalty applied during moderate regulatory fines and legal fees. */
    public const RECALL_MODERATE_PENALTY = 0.03;

    // --- Brand Equity Amortization & Marketing Reinvestment Physics ---
    /** Quarterly margin decay rate per unit of underinvestment below brand maintenance CapEx. */
    public const BRAND_EQUITY_DECAY_RATE = 0.020;
    /** Quarterly margin gain scalar per unit of logarithmic brand marketing super-cycle investment. */
    public const BRAND_MARKETING_GAIN_RATE = 0.010;
    /** Structural minimum operating margin floor under private-label generic retail competition. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.10;
    /** Structural maximum operating margin ceiling for dominant global consumer staple brands. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.35;

    public function getReversionSpeed(): float { return 0.15; }
    public function getMoatSpread(): float { return 0.015; }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return self::STAPLES_SECULAR_GROWTH;
    }

    public function getCapexCyclicality(): float
    {
        return self::STAPLES_CAPEX_CYCLICALITY;
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => self::SURPRISE_EPS_WEIGHT, 'revenue_weight' => self::SURPRISE_REVENUE_WEIGHT];
    }

    public function getMarginReversionSpeed(): float
    {
        return self::STAPLES_REVERSION_SPEED;
    }

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::BrandedStaplesWeight->value  => self::BRANDED_STAPLES_WEIGHT,
            ModelParam::VolumeCommodityWeight->value => self::VOLUME_COMMODITY_WEIGHT,
        ]);

        $brandedWeight = $params[ModelParam::BrandedStaplesWeight];
        $volumeWeight  = $params[ModelParam::VolumeCommodityWeight];
        $totalWeight   = max(0.01, $brandedWeight + $volumeWeight);

        // Branded FMCG generates float (-5%); bulk commodity storage ties up cash (+5%)
        return (($brandedWeight * self::BRANDED_NWC_INTENSITY) + ($volumeWeight * self::VOLUME_NWC_INTENSITY)) / $totalWeight;
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::PricingPowerIndex->value => 0.50,
        ]);
        $pricingPower = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));

        // Modulate elasticity by pricing power: strong brand equity lowers PED further
        $effectivePed = self::BASELINE_PRICE_ELASTICITY_OF_DEMAND * (1.5 - $pricingPower);

        $beta = abs((float) $stock->getBeta());
        $fxShift = ($macroState->exchangeRateIndexEma - 100.0) / 100.0;

        return [
            'macro_demand_shift'       => ($macroState->outputGapEma * $beta * $effectivePed) - ($fxShift * 0.05),
            'pricing_power_multiplier' => 1.0 + ($macroState->tipsBreakevenEma * (1.0 - $effectivePed)),
        ];
    }

    protected function calculateSectorPhysics(
        Stock $stock,
        float $expectedRevenue,
        float $realizedVariableMargin,
        float $fixedCosts,
        float $baselineVol,
        MacroStateDTO $macroState,
        MathUtility $mathUtility
    ): SectorPhysicsResult {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::BrandedStaplesWeight->value   => self::BRANDED_STAPLES_WEIGHT,
            ModelParam::VolumeCommodityWeight->value  => self::VOLUME_COMMODITY_WEIGHT,
            ModelParam::CommodityTradingWeight->value => 0.00,
            ModelParam::LandSpeculationWeight->value  => 0.00,
        ]);

        $rawCommodityWeight = $params[ModelParam::CommodityTradingWeight];
        $rawLandWeight      = $params[ModelParam::LandSpeculationWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new StreamContext($momentum, $mathUtility);

        $targetWeights = [
            'branded' => $params[ModelParam::BrandedStaplesWeight],
            'volume'  => $params[ModelParam::VolumeCommodityWeight],
        ];
        if ($rawCommodityWeight > 0.0) {
            $targetWeights['commodity_trading'] = $rawCommodityWeight;
        }
        if ($rawLandWeight > 0.0) {
            $targetWeights['land_speculation'] = $rawLandWeight;
        }

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights($targetWeights);

        $brandedWeight   = $activeWeights['branded'];
        $volumeWeight    = $activeWeights['volume'];
        $commodityWeight = $activeWeights['commodity_trading'] ?? 0.0;
        $landWeight      = $activeWeights['land_speculation'] ?? 0.0;

        // Independent stream Z-scores with AR(1) persistence
        $brandedZ = $streams->generateZ('branded', 0.15);
        $volumeZ  = $streams->generateZ('volume', 0.15);
        $eventZ   = $streams->generateZ('event', 0.05);

        $brandedRevenue = max(0.0, $expectedRevenue * $brandedWeight * (1.0 + ($brandedZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR))));
        $volumeRevenue  = max(0.0, $expectedRevenue * $volumeWeight * (1.0 + ($volumeZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR))));

        // Tail Risk: Product Recalls and Health Regulations
        $eventType = null;
        $recallCostPenalty = 0.0;

        if ($eventZ < self::RECALL_SEVERE_Z_SCORE) {
            $recallCostPenalty = self::RECALL_SEVERE_PENALTY * $brandedWeight;
            $eventType = ShockEvent::PRODUCT_RECALL;
        } elseif ($eventZ < self::RECALL_MODERATE_Z_SCORE) {
            $recallCostPenalty = self::RECALL_MODERATE_PENALTY * $brandedWeight;
            $eventType = ShockEvent::REGULATORY_FINE;
        }

        $streamRevenues = [
            'branded' => $brandedRevenue,
            'volume'  => $volumeRevenue,
        ];

        $commodityZ = 0.0;
        if ($commodityWeight > 0.0) {
            $commodityZ = $streams->generateZ('commodity_trading', 0.20);

            $inflationExcess = max(0.0, $macroState->inflationEma - MacroEngine::TARGET_INFLATION);
            $energyExcess = max(0.0, $macroState->energyCostPushLag / MacroEngine::ENERGY_COST_PUSH_TRANSMISSION);
            $agriExcess = max(0.0, ($macroState->agriculturalCommodityIndexEma - 100.0) / 100.0);
            $commoditySqueezeBonus = ($inflationExcess * self::COMMODITY_INFLATION_ALPHA_SCALAR) + ($energyExcess * self::COMMODITY_ENERGY_ALPHA_SCALAR) + ($agriExcess * 0.30);

            $commodityRevenue = max(0.0, $expectedRevenue * $commodityWeight * (1.0 + ($commodityZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::COMMODITY_TRADING_VOL_SCALAR) + $commoditySqueezeBonus));
            $streamRevenues['commodity_trading'] = $commodityRevenue;
        }

        $landZ = 0.0;
        if ($landWeight > 0.0) {
            $landZ = $streams->generateZ('land_speculation', 0.50);
            $landRevenue = max(0.0, $expectedRevenue * $landWeight * (1.0 + ($landZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::LAND_SPECULATION_VOL_SCALAR)));
            $streamRevenues['land_speculation'] = $landRevenue;
        }

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // --- Cost-Push Inflation & COGS Squeeze ---
        $inflationExcess = max(0.0, $macroState->inflationEma - MacroEngine::TARGET_INFLATION);
        $agriShift = max(0.0, ($macroState->agriculturalCommodityIndexEma - 100.0) / 100.0);
        $agriculturalCostSqueeze = ($inflationExcess * self::AGRI_INFLATION_COST_SCALAR * $volumeWeight)
            + ($agriShift * 0.15 * $volumeWeight)
            - ($volumeZ * self::AGRI_HARVEST_SHOCK_SCALAR * $volumeWeight);

        // Supply Chain, Freight & Packaging Penalty (Energy & Freight Price Indices)
        $energyShift = max(0.0, $macroState->energyCostPushLag / MacroEngine::ENERGY_COST_PUSH_TRANSMISSION);
        $freightShift = max(0.0, ($macroState->freightRateIndexEma - 100.0) / 100.0);
        $beta = abs((float) $stock->getBeta());
        $rawLogisticsPenalty = ($energyShift * $beta * self::PACKAGING_ENERGY_COST_SCALAR) + ($freightShift * $beta * 0.05);

        // Physical inventory hoarding buffers input costs and mitigates packaging bottlenecks
        $logisticsPenalty = $rawLogisticsPenalty * (1.0 - min(self::MAX_COMMODITY_HEDGE_MITIGATION, $commodityWeight * self::COMMODITY_HEDGE_MULTIPLIER));

        $rawMargin = $realizedVariableMargin + $recallCostPenalty + $agriculturalCostSqueeze + $logisticsPenalty;
        $clampedMargin = $this->clampMargin($rawMargin);

        $primaryShockZ = $streams->resolveDominantShockZ([$brandedZ], $eventZ);

        $observableShockZ = ($brandedZ * $brandedWeight * $baselineVol * self::REVENUE_VARIANCE_SCALAR)
            + ($volumeZ * $volumeWeight * $baselineVol * self::REVENUE_VARIANCE_SCALAR)
            + ($commodityZ * $commodityWeight * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::COMMODITY_TRADING_VOL_SCALAR)
            + ($landZ * $landWeight * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::LAND_SPECULATION_VOL_SCALAR);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            // Brand equity erosion toward private-label floor
            $decayRate = self::BRAND_EQUITY_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // Brand marketing super-cycle expands pricing power
            $modGain = self::BRAND_MARKETING_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}

