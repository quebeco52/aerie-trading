<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Math\FinancialConstants;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorCoverageProfile;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Apparel Manufacturing, Garment Producers, and Industrial Textile Mills.
 *
 * Financial Physics:
 * - Tri-Stream Architecture:
 *      1. DTC Retail: High-margin branded garments sold direct-to-consumer (e-commerce and retail footprint). High consumer sentiment sensitivity, tempered by recessionary trade-down.
 *      2. Wholesale Channel: Branded garments distributed to department stores and multi-brand retailers. Moderate margin and channel inventory sensitivity.
 *      3. Contract Textile Supply: Low-margin B2B processed fiber, fabric, and private-label contract manufacturing. Low variance, counter-cyclical FX export benefits.
 * - Forrester Bullwhip Effect: Wholesale channel suffers violent, mathematically convex order freezes when retailers panic over inventory bloat during negative output gaps.
 * - Fast Fashion Markdown Squeeze: Seasonal/perishable inventory forces aggressive promotional markdowns during recessions, destroying DTC and Wholesale gross margins.
 * - COGS Forward Hedging: Raw material inventory lags (6-9 months) and cotton futures dampen immediate spot commodity and freight inflation passthrough.
 * - Consumer Trade-Down Effect: Recessions prompt consumers to trade down from high-end designer labels into durable value apparel and basics.
 * - Agricultural & Supply Chain Inflation: Variable margins squeezed by price spikes in agricultural commodities (raw cotton, wool, plant cellulose), freight rates, and energy.
 * - Manufacturing Asset & Plant Modernization Physics: High asset sensitivity where equipment maintenance and automated weaving/cutting dictate structural unit cost leadership.
 */
class ApparelManufacturingBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Discretionary garments with fashion substitution. */
    public const OPERATING_CYCLICALITY = 1.20;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 1.00;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.60;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['agri' => 0.15, 'freight' => 0.06, 'energy' => 0.04, 'ppi' => 0.20, 'labor' => 0.25];
    /** Six to nine months of raw inventory and cotton futures: spot fiber and freight moves reach COGS with a lag. */
    public const INPUT_COST_LAG_YEARS = 0.50;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Design studios, brand marketing and merchandising are the overhead; cutting and sewing sits in variable cost, largely under contract. */
    public const FIXED_COST_LABOR_SHARE = 0.60;

    // --- Inventory Cycle ---
    /** Order sensitivity to the economy-wide inventory-to-sales gap (Metzler cycle): overhangs trigger destocking, shortfalls restocking. Retailer inventory-to-sales ratios gate wholesale reorders. */
    public const INVENTORY_CYCLE_SENSITIVITY = 0.50;

    // --- Balance Sheet Realism ---
    /** Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Direct-to-consumer store leases. */
    public const LEASE_LIABILITY_INTENSITY = 0.30;

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for apparel manufacturing analysts. */
    public const BASE_COVERAGE_VISIBILITY = 0.35;
    /** Standard forecasting error on apparel sales volume and channel margins. */
    public const BASE_COVERAGE_ERROR = 0.07;
    /** Minimum visibility floor for analyst consensus models. */
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.20;

    // --- Tri-Stream Architecture Weights ---
    /** Baseline fraction of revenue derived from direct-to-consumer branded apparel sales. */
    public const DTC_RETAIL_WEIGHT = 0.30;
    /** Baseline fraction of revenue derived from wholesale apparel retail distribution. */
    public const WHOLESALE_CHANNEL_WEIGHT = 0.40;
    /** Baseline fraction of revenue derived from contract B2B technical fiber and fabric supply. */
    public const CONTRACT_TEXTILE_SUPPLY_WEIGHT = 0.30;

    // --- Stream Variance Scalars ---
    /** Volatility multiplier for direct-to-consumer branded sales shocks. */
    public const DTC_RETAIL_VARIANCE = 0.30;
    /** Volatility multiplier for wholesale channel orders and department store restocking. */
    public const WHOLESALE_CHANNEL_VARIANCE = 0.18;
    /** Volatility multiplier for defensive B2B contract textile and raw fiber orders. */
    public const CONTRACT_TEXTILE_VARIANCE = 0.08;

    // --- Structural Variable Cost Multipliers ---
    /** Variable cost multiplier for high-margin direct-to-consumer branded sales (20% lower variable cost ratio). */
    public const DTC_VARIABLE_COST_MULTIPLIER = 0.80;
    /** Variable cost multiplier for wholesale channel retail distribution (standard baseline). */
    public const WHOLESALE_VARIABLE_COST_MULTIPLIER = 1.00;
    /** Variable cost multiplier for volume-driven contract B2B textile supply (20% higher variable cost ratio / thinner unit spread). */
    public const CONTRACT_VARIABLE_COST_MULTIPLIER = 1.20;

    // --- Forrester Bullwhip Physics ---
    /** Convexity exponent for non-linear wholesale channel order cancellations during macro contractions. */
    public const BULLWHIP_CONVEXITY = 2.0;
    /** Scaling multiplier applied to the convex output gap penalty on the wholesale channel. */
    public const BULLWHIP_PENALTY_SCALAR = 2.0;

    // --- Fast Fashion Markdown Squeeze Physics ---
    /** Convexity exponent for perishable seasonal inventory markdown squeezes during recessions. */
    public const MARKDOWN_CONVEXITY = 1.5;
    /** Scaling multiplier for gross margin destruction from aggressive promotional markdowns. */
    public const MARKDOWN_SQUEEZE_SCALAR = 0.40;

    // --- Macro & Trade-Down Physics ---
    /** Baseline pricing power across apparel manufacturer brand portfolio. */
    public const PRICING_POWER_INDEX = 0.50;
    /** Demand boost scalar capturing consumer trade-down from premium/designer apparel into affordable mass-market basics during recessions. */
    public const TRADE_DOWN_SCALAR = 0.60;
    /** Sensitivity of branded apparel demand to macroeconomic consumer sentiment swings. */
    public const CONSUMER_SENTIMENT_SCALAR = 0.40;
    /** Sensitivity of aggregate textile production demand to real GDP output gap. */
    public const OUTPUT_GAP_SCALAR = 0.70;
    /** Currency depreciation export competitiveness boost on contract textile supply. */
    public const CONTRACT_FX_EXPORT_SCALAR = 0.15;

    // --- Trade Physics ---
    /** Sensitivity of contract textile export and domestic mill volumes to trade balance shifts. */
    public const TRADE_BALANCE_SENSITIVITY = 1.00;

    // --- Tail Risk & Shock Thresholds ---
    /** Negative z-score threshold triggering severe agricultural/raw fiber supply chain disruption. */
    public const SUPPLY_CHAIN_DISRUPTION_Z = -2.30;
    /** Revenue multiplier applied across all streams during an agricultural fiber supply chain collapse. */
    public const SUPPLY_CHAIN_DISRUPTION_MULT = 0.88;
    /** Positive z-score threshold triggering a viral consumer product super-cycle. */
    public const VIRAL_PRODUCT_Z = 2.40;
    /** Revenue multiplier applied to DTC and Wholesale streams during a viral product sensation. */
    public const VIRAL_PRODUCT_MULT = 1.12;

    // --- Plant Modernization & Asset Depreciation Physics ---
    /** Quarterly efficiency decay rate per unit of underinvestment below replacement CapEx for specialized manufacturing plant and equipment. */
    public const PLANT_DECAY_RATE = 0.025;
    /** Backward compatibility alias for plant decay rate. */
    public const LOOM_DECAY_RATE = self::PLANT_DECAY_RATE;
    /** Quarterly efficiency gain scalar per unit of logarithmic overinvestment into automated cutting, sewing, and weaving infrastructure. */
    public const PLANT_MODERNIZATION_GAIN = 0.012;
    /** Backward compatibility alias for plant modernization gain. */
    public const LOOM_MODERNIZATION_GAIN = self::PLANT_MODERNIZATION_GAIN;
    /** Structural minimum operating margin floor under severe industrial equipment mechanical degradation. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.08;
    /** Structural maximum operating margin ceiling for fully modernized, automated apparel manufacturing facilities. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.32;
    /** Operating margin mean reversion speed for apparel manufacturing economics. */
    public const APPAREL_REVERSION_SPEED = 0.15;

    public function getReversionSpeed(): float
    {
        return 0.15;
    }

    public function getMoatSpread(): float
    {
        return 0.008;
    }

    public function getCapExCompletionRate(Stock $stock): float
    {
        return 0.25;
    }

    public function getCapexCyclicality(): float
    {
        return 1.0;
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.025;
    }

    public function getCoverageProfile(Stock $stock): SectorCoverageProfile
    {
        return new SectorCoverageProfile(
            baseVisibility: self::BASE_COVERAGE_VISIBILITY,
            errorStdDev: self::BASE_COVERAGE_ERROR,
            minVisibility: self::BASE_COVERAGE_MIN_VISIBILITY,
            eventBaseVisibility: 0.70,
            eventMinVisibility: 0.40
        );
    }

    // --- Working Capital Intensities ---
    /** Fast inventory turns and instant credit card payments yield low working capital needs for DTC. */
    public const DTC_NWC_INTENSITY = 0.12;
    /** Standard Net 30/60 day terms for department store wholesale distribution. */
    public const WHOLESALE_NWC_INTENSITY = 0.22;
    /** Raw material hoarding, slow B2B payments, and long factory lead times for contract textile supply. */
    public const CONTRACT_NWC_INTENSITY = 0.35;

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::DtcRetailWeight->value             => self::DTC_RETAIL_WEIGHT,
            ModelParam::WholesaleChannelWeight->value      => self::WHOLESALE_CHANNEL_WEIGHT,
            ModelParam::ContractTextileSupplyWeight->value => self::CONTRACT_TEXTILE_SUPPLY_WEIGHT,
        ]);

        $dtcWeight       = $params[ModelParam::DtcRetailWeight];
        $wholesaleWeight = $params[ModelParam::WholesaleChannelWeight];
        $contractWeight  = $params[ModelParam::ContractTextileSupplyWeight];
        
        $totalWeight = max(0.01, $dtcWeight + $wholesaleWeight + $contractWeight);

        return (($dtcWeight * self::DTC_NWC_INTENSITY) + 
                ($wholesaleWeight * self::WHOLESALE_NWC_INTENSITY) + 
                ($contractWeight * self::CONTRACT_NWC_INTENSITY)) / $totalWeight;
    }

    public function getSeasonalityFactors(): array
    {
        return [0.85, 0.95, 1.05, 1.15]; // Back-to-school Q3 and winter/holiday Q4 apparel cycles
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.50, 'revenue_weight' => 0.50];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::PricingPowerIndex->value => self::PRICING_POWER_INDEX,
        ]);
        $pricingPower = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));

        $outputGap = $macroState->outputGapEma;
        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;
        $beta = $this->getOperatingCyclicality($stock);

        // Hybrid demand physics: Pro-cyclical consumer sentiment + output gap,
        // tempered by counter-cyclical trade-down effect during economic downturns (output gap < 0).
        // Companies with lower pricing power (budget/mass-market basics) capture higher trade-down volume.
        $tradeDownScalar = self::TRADE_DOWN_SCALAR * (1.5 - $pricingPower);
        $tradeDownBonus = $outputGap < 0.0 ? abs($outputGap) * $tradeDownScalar : 0.0;
        $proCyclicalDemand = ($outputGap * self::OUTPUT_GAP_SCALAR) + ($sentimentShift * self::CONSUMER_SENTIMENT_SCALAR);
        $blendedDemandShift = ($proCyclicalDemand * $beta) + $tradeDownBonus;

        return [
            'macro_demand_shift' => $blendedDemandShift,
            ...$this->resolvePricingMultipliers($stock, $macroState),
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::DtcRetailWeight->value              => self::DTC_RETAIL_WEIGHT,
            ModelParam::WholesaleChannelWeight->value       => self::WHOLESALE_CHANNEL_WEIGHT,
            ModelParam::ContractTextileSupplyWeight->value  => self::CONTRACT_TEXTILE_SUPPLY_WEIGHT,
            ModelParam::PricingPowerIndex->value            => self::PRICING_POWER_INDEX,
        ]);

        $pricingPower = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'dtc_retail'              => $params[ModelParam::DtcRetailWeight],
            'wholesale_channel'       => $params[ModelParam::WholesaleChannelWeight],
            'contract_textile_supply' => $params[ModelParam::ContractTextileSupplyWeight],
        ]);

        $dtcWeight       = $activeWeights['dtc_retail'];
        $wholesaleWeight = $activeWeights['wholesale_channel'];
        $contractWeight  = $activeWeights['contract_textile_supply'];

        // Independent stream Z-scores
        $dtcZ       = $streams->generateZ('dtc_retail', 0.25);
        $wholesaleZ = $streams->generateZ('wholesale_channel', 0.30);
        $contractZ  = $streams->generateZ('contract_textile_supply', 0.40);
        $eventZ     = $streams->generateExogenousZ('event', 0.10);

        // --- Tail Risk Events ---
        $revenueMultiplier = 1.0;
        $brandSurgeMultiplier = 1.0;
        $eventType = null;

        if ($eventZ < self::SUPPLY_CHAIN_DISRUPTION_Z) {
            $revenueMultiplier = self::SUPPLY_CHAIN_DISRUPTION_MULT;
            $eventType = ShockEvent::APPAREL_SUPPLY_CHAIN_DISRUPTION;
        } elseif ($eventZ > self::VIRAL_PRODUCT_Z) {
            $brandSurgeMultiplier = self::VIRAL_PRODUCT_MULT;
            $eventType = ShockEvent::APPAREL_VIRAL_PRODUCT;
        }

        // Currency FX Export Competitiveness & Global Trade: A weaker currency and positive trade balance boost textile exports.
        $fxShift = ($macroState->exchangeRateIndexEma - FinancialConstants::FX_INDEX_BASE) / FinancialConstants::FX_INDEX_BASE;
        $contractFxBonus = $fxShift * self::CONTRACT_FX_EXPORT_SCALAR;
        $tradeShift = MathUtility::calculateTradeBalanceShift($macroState->tradeBalanceToGdpEma, sensitivity: self::TRADE_BALANCE_SENSITIVITY);

        // Output gap drives non-linear supply chain ordering contractions and inventory markdown pressures
        $outputGap = $macroState->outputGapEma;
        $outputGapContraction = $outputGap < 0.0 ? abs($outputGap) : 0.0;

        // 1. Forrester Bullwhip Effect: Wholesale retailers panic and freeze orders non-linearly under negative output gaps
        $bullwhipPenalty = $mathUtility->calculateConvexPenalty($outputGapContraction, self::BULLWHIP_CONVEXITY, self::BULLWHIP_PENALTY_SCALAR);

        $dtcShock       = $dtcZ * ($baselineVol * self::DTC_RETAIL_VARIANCE);
        // Metzler inventory cycle: retailers with elevated inventory-to-sales ratios cut wholesale reorders.
        $inventoryCycleShift = -$macroState->inventoryStockGapEma * self::INVENTORY_CYCLE_SENSITIVITY;
        $wholesaleShock = ($wholesaleZ * ($baselineVol * self::WHOLESALE_CHANNEL_VARIANCE)) - $bullwhipPenalty + $inventoryCycleShift;
        $contractShock  = ($contractZ * ($baselineVol * self::CONTRACT_TEXTILE_VARIANCE)) + $contractFxBonus + $tradeShift;

        $dtcRevenue       = max(0.0, $expectedRevenue * $dtcWeight * (1.0 + $dtcShock) * $revenueMultiplier * $brandSurgeMultiplier);
        $wholesaleRevenue = max(0.0, $expectedRevenue * $wholesaleWeight * (1.0 + $wholesaleShock) * $revenueMultiplier * $brandSurgeMultiplier);
        $contractRevenue  = max(0.0, $expectedRevenue * $contractWeight * (1.0 + $contractShock) * $revenueMultiplier);

        $streamRevenues = [
            'dtc_retail'              => $dtcRevenue,
            'wholesale_channel'       => $wholesaleRevenue,
            'contract_textile_supply' => $contractRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // --- Structural Margin Blending & Fast Fashion Markdown Squeeze ---
        // DTC retail captures premium margins (lower variable costs), wholesale operates at baseline,
        // and contract textile supply operates at volume pricing (higher variable costs).
        // 2. Fast Fashion Markdown Squeeze: During negative output gaps, unsold perishable inventory forces aggressive markdowns, raising variable cost ratios.
        $markdownPenalty = $mathUtility->calculateConvexPenalty($outputGapContraction, self::MARKDOWN_CONVEXITY, self::MARKDOWN_SQUEEZE_SCALAR);

        $dtcCostRatio       = ($realizedVariableMargin * self::DTC_VARIABLE_COST_MULTIPLIER) + $markdownPenalty;
        $wholesaleCostRatio = ($realizedVariableMargin * self::WHOLESALE_VARIABLE_COST_MULTIPLIER) + $markdownPenalty;
        $contractCostRatio  = $realizedVariableMargin * self::CONTRACT_VARIABLE_COST_MULTIPLIER;

        $actualVariableCosts = ($dtcRevenue * $dtcCostRatio)
            + ($wholesaleRevenue * $wholesaleCostRatio)
            + ($contractRevenue * $contractCostRatio);

        // --- Commodity & Supply Chain Input Inflation (with Forward Hedging) ---
        // 3. Raw cotton and wool, container freight, mill energy, processed fiber and sewing payroll reach COGS
        // through the forward-hedged buying lag and are recovered at retail with the repricing lag. A fiber price
        // collapse is a gross margin dividend in the same way.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        $effectiveMargin = $actualRevenue > 0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;
        $clampedMargin = $this->clampMargin($effectiveMargin + $inputCostDrag);

        // Primary and observable shocks
        $primaryShockZ = $streams->resolveDominantShockZ([$dtcZ, $wholesaleZ, $contractZ], $eventZ);
        $observableShockZ = (($dtcZ * $dtcWeight * self::DTC_RETAIL_VARIANCE) +
            ($wholesaleZ * $wholesaleWeight * self::WHOLESALE_CHANNEL_VARIANCE) +
            ($contractZ * $contractWeight * self::CONTRACT_TEXTILE_VARIANCE)) * $baselineVol;
        $observableShockZ -= ($bullwhipPenalty * $wholesaleWeight);

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

    /** Rapid obsolescence of specialized cutting, sewing, and weaving equipment */
    public function getDepreciationDecayRate(): float
    {
        return self::PLANT_DECAY_RATE;
    }

    /** Modernization and automated weaving/cutting infrastructure expand unit cost advantage */
    public function getModernizationGainRate(): float
    {
        return self::PLANT_MODERNIZATION_GAIN;
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
            'consumer_sentiment_index_ema',
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'freight_rate_index_ema',
            'inventory_stock_gap_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'tips_breakeven_ema',
            'trade_balance_to_gdp_ema',
            'wage_growth_ema',
        ];
    }
}
