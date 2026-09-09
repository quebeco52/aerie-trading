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
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Inelastic pantry demand; brands substitute on the shelf. */
    public const OPERATING_CYCLICALITY = 0.50;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.30;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.60;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['agri' => 0.30, 'ppi' => 0.20, 'energy' => 0.06, 'freight' => 0.05, 'labor' => 0.20];
    /** Branded staples recover input moves on the shelf within a couple of quarters. */
    public const INPUT_PASS_THROUGH_LAG_YEARS = 0.50;
    /** Brand equity is shelf-price power: a branded FMCG list price rises with input costs and the volume loss is small. Bulk commodity producers are tuned down per ticker. */
    public const PRICING_POWER_INDEX = 0.75;

    // --- Inventory Cycle ---
    /** Order sensitivity to the economy-wide inventory-to-sales gap (Metzler cycle): overhangs trigger destocking, shortfalls restocking. Grocery and distributor stock levels only modestly gate replenishment volumes. */
    public const INVENTORY_CYCLE_SENSITIVITY = 0.30;

    // --- Balance Sheet Realism ---
    /** Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Store and distribution-centre leases behind grocery and discount formats. */
    public const LEASE_LIABILITY_INTENSITY = 0.30;

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
    /** Idiosyncratic agricultural harvest shock sensitivity scalar on variable costs. */
    public const AGRI_HARVEST_SHOCK_SCALAR = 0.010;

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

    public function getSeasonalityFactors(): array
    {
        return [0.95, 1.00, 1.00, 1.05]; // Slight Q4 holiday pantry load
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

    /** Shelf prices track expected inflation at 1 - effective price elasticity: brand equity lowers the elasticity. */
    protected function resolvePricingElasticity(Stock $stock): float
    {
        $effectivePed = self::BASELINE_PRICE_ELASTICITY_OF_DEMAND * (1.5 - $this->resolvePricingPower($stock));

        return max(0.0, 1.0 - $effectivePed);
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        // Modulate elasticity by pricing power: strong brand equity lowers PED further
        $effectivePed = self::BASELINE_PRICE_ELASTICITY_OF_DEMAND * (1.5 - $this->resolvePricingPower($stock));

        $beta = $this->getOperatingCyclicality($stock);
        $fxShift = ($macroState->exchangeRateIndexEma - 100.0) / 100.0;

        // Inelastic demand lets the shelf price track expected inflation at 1 - PED, reached over the repricing lag.
        return [
            'macro_demand_shift'       => ($macroState->outputGapEma * $beta * $effectivePed) - ($fxShift * 0.05),
            ...$this->resolvePricingMultipliers($stock, $macroState),
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
        $pricingPower = $this->resolvePricingPower($stock);

        $rawCommodityWeight = $params[ModelParam::CommodityTradingWeight];
        $rawLandWeight      = $params[ModelParam::LandSpeculationWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

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
        $eventZ   = $streams->generateExogenousZ('event', 0.05);

        $brandedRevenue = max(0.0, $expectedRevenue * $brandedWeight * (1.0 + ($brandedZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR))));
        // Metzler inventory cycle: distributor stock overhangs modestly delay replenishment of volume lines.
        $inventoryCycleShift = -$macroState->inventoryStockGapEma * self::INVENTORY_CYCLE_SENSITIVITY;
        $volumeRevenue  = max(0.0, $expectedRevenue * $volumeWeight * (1.0 + ($volumeZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $inventoryCycleShift));

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
        // Farm commodities, packaging and freight, plant energy and line payroll reach COGS at spot and are
        // recovered on the shelf with the repricing lag. A firm running its own commodity desk hedges part of it.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);
        $agriculturalCostSqueeze = -($volumeZ * self::AGRI_HARVEST_SHOCK_SCALAR * $volumeWeight);
        $logisticsPenalty = $inputCostDrag * (1.0 - min(self::MAX_COMMODITY_HEDGE_MITIGATION, $commodityWeight * self::COMMODITY_HEDGE_MULTIPLIER));

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

    /** Brand equity erosion toward private-label floor */
    public function getDepreciationDecayRate(): float
    {
        return self::BRAND_EQUITY_DECAY_RATE;
    }

    /** Brand marketing super-cycle expands pricing power */
    public function getModernizationGainRate(): float
    {
        return self::BRAND_MARKETING_GAIN_RATE;
    }

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'agricultural_commodity_index_ema',
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'freight_rate_index_ema',
            'inflation_ema',
            'inventory_stock_gap_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'tips_breakeven_ema',
            'wage_growth_ema',
        ];
    }
}
