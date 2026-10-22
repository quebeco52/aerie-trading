<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;

use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Math\ResponseCurves;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for Apparel Manufacturing, Garment Producers, and Industrial Textile Mills.
 *
 * Financial Physics:
 * - Tri-Stream Architecture:
 *      1. DTC Retail: High-margin branded garments sold direct-to-consumer (e-commerce and retail footprint). High
 *         consumer sentiment sensitivity, tempered by recessionary trade-down.
 *      2. Wholesale Channel: Branded garments distributed to department stores and multi-brand retailers. Moderate
 *         margin and channel inventory sensitivity.
 *      3. Contract Textile Supply: Low-margin B2B processed fiber, fabric, and private-label contract manufacturing.
 *         Low variance, counter-cyclical FX export benefits.
 * - Forrester Bullwhip Effect: Wholesale channel suffers violent, mathematically convex order freezes when retailers
 *   panic over inventory bloat during negative output gaps.
 * - Fast Fashion Markdown Squeeze: Seasonal/perishable inventory forces aggressive promotional markdowns during
 *   recessions, destroying DTC and Wholesale gross margins.
 * - COGS Forward Hedging: Raw material inventory lags (6-9 months) and cotton futures dampen immediate spot commodity
 *   and freight inflation passthrough.
 * - Consumer Trade-Down Effect: Recessions prompt consumers to trade down from high-end designer labels into durable
 *   value apparel and basics.
 * - Agricultural & Supply Chain Inflation: Variable margins squeezed by price spikes in agricultural commodities (raw
 *   cotton, wool, plant cellulose), freight rates, and energy.
 * - Manufacturing Asset & Plant Modernization Physics: High asset sensitivity where equipment maintenance and automated
 *   weaving/cutting dictate structural unit cost leadership.
 */
class ApparelManufacturingBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Discretionary
     * garments with fashion substitution.
     */
    public const OPERATING_CYCLICALITY = 1.20;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 1.00;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.60;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::APPAREL_MANUFACTURING;
    /** Six to nine months of raw inventory and cotton futures: spot fiber and freight moves reach COGS with a lag. */
    public const INPUT_COST_LAG_YEARS = 0.50;

    // --- Demand Transmission Lag ---
    /**
     * Years for a move in the output gap to reach the order book. Wholesale orders are placed two seasons ahead against
     * a buying calendar, not against current demand.
     */
    public const DEMAND_LAG_YEARS = 0.50;

    // --- FX Exposure ---
    /**
     * Share of revenue whose competitiveness moves with the trade-weighted exchange rate. Wholesale and DTC exports
     * translate home, and the domestic shelf meets importers who reprice when the currency does.
     */
    public const FX_REVENUE_EXPOSURE = 0.15;

    // --- Inventory Cycle ---
    /**
     * Order sensitivity to the economy-wide inventory-to-sales gap (Metzler cycle): overhangs trigger destocking,
     * shortfalls restocking. Retailer inventory-to-sales ratios gate wholesale reorders.
     */
    public const INVENTORY_CYCLE_SENSITIVITY = 0.50;

    // --- Balance Sheet Realism ---
    /**
     * The direct-to-consumer stream's capitalized leases over its revenue (IFRS 16 / ASC 842): store leases at an
     * apparel retailer's 0.30. Wholesale and the mills lease little, at the default.
     */
    public const DTC_LEASE_INTENSITY = 0.30;

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
    /**
     * Variable cost multiplier for volume-driven contract B2B textile supply (20% higher variable cost ratio / thinner
     * unit spread).
     */
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
    /**
     * Mass-market demand per unit of output gap against the cycle, both ways: consumers trade down from designer
     * labels into affordable basics in a recession and back up in a boom.
     */
    public const TRADE_DOWN_SCALAR = 0.60;
    /** Sensitivity of branded apparel demand to macroeconomic consumer sentiment swings. */
    public const CONSUMER_SENTIMENT_SCALAR = 0.40;
    /** Sensitivity of aggregate textile production demand to real GDP output gap. */
    public const OUTPUT_GAP_SCALAR = 0.70;
    /** Currency depreciation export competitiveness boost on contract textile supply. */
    public const CONTRACT_FX_EXPORT_SCALAR = 0.15;

    // --- Trade Physics ---
    /** Export volume per unit of the foreign bloc's output gap: the customers abroad. */
    public const FOREIGN_DEMAND_SENSITIVITY = 1.00;

    // --- Tail Risk & Shock Thresholds ---
    /** Negative z-score threshold triggering severe agricultural/raw fiber supply chain disruption. */
    public const SUPPLY_CHAIN_DISRUPTION_Z = -2.30;
    /** Revenue multiplier applied across all streams during an agricultural fiber supply chain collapse. */
    public const SUPPLY_CHAIN_DISRUPTION_MULT = 0.88;
    /** Positive z-score threshold triggering a viral consumer product super-cycle. */
    public const VIRAL_PRODUCT_Z = 2.40;
    /** Revenue multiplier applied to DTC and Wholesale streams during a viral product sensation. */
    public const VIRAL_PRODUCT_MULT = 1.12;

    // --- Secular Demand ---
    /**
     * Spending on clothing and footwear as a share of US nominal GDP in 1997 (BEA NIPA Table 2.4.5, personal
     * consumption expenditures).
     */
    public const SECULAR_SHARE_1997 = 0.0289;
    /** The same share in 2019. */
    public const SECULAR_SHARE_2019 = 0.0192;

    // --- Return Reversion ---
    /** Pull of the trailing return toward the required return plus the moat (0.15 a year). */
    public const ROIC_REVERSION_SPEED = 0.15;
    /** Excess return over the required return the moat sustains (0.8pp). */
    public const MOAT_SPREAD = 0.008;

    // --- Capital Cycle ---
    /** Elasticity of the capital budget to the cycle signal (1). */
    public const CAPEX_CYCLICALITY = 1.0;
    /** Share of construction in progress completed into plant each quarter (25%). */
    public const CAPEX_COMPLETION_RATE = 0.25;

    // --- Seasonality ---
    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0. Back-to-school Q3 and winter/holiday Q4
     * apparel cycles.
     */
    public const SEASONALITY_FACTORS = [0.85, 0.95, 1.05, 1.15];

    /** Trend real growth plus the sector's measured drift in its share of GDP. */
    public function getSecularGrowthRate(Stock $stock): float
    {
        return MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift(self::SECULAR_SHARE_1997, self::SECULAR_SHARE_2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);
    }

    // --- Analyst Coverage ---
    // Inventory write-downs and brand scandals are reported.
    /** Visibility of a quarter with an announced event (70%). */
    public const EVENT_BASE_VISIBILITY = 0.70;
    /** Floor on visibility in an announced-event quarter (40%). */
    public const EVENT_MIN_VISIBILITY = 0.40;

    // --- Working Capital Intensities ---
    /** Fast inventory turns and instant credit card payments yield low working capital needs for DTC. */
    public const DTC_NWC_INTENSITY = 0.12;
    /** Standard Net 30/60 day terms for department store wholesale distribution. */
    public const WHOLESALE_NWC_INTENSITY = 0.22;
    /** Raw material hoarding, slow B2B payments, and long factory lead times for contract textile supply. */
    public const CONTRACT_NWC_INTENSITY = 0.35;

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        return FirmEconomics::revenueWeightedRatio(
            $this->resolveTargetStreamWeights($stock),
            [self::DTC_NWC_INTENSITY, self::WHOLESALE_NWC_INTENSITY, self::CONTRACT_NWC_INTENSITY],
        );
    }

    /** Only the stores lease floor space; the wholesale book and the mills carry the default. */
    public function getLeaseIntensity(Stock $stock): float
    {
        return FirmEconomics::revenueWeightedRatio(
            $this->resolveTargetStreamWeights($stock),
            [self::DTC_LEASE_INTENSITY, FinancialConstants::DEFAULT_LEASE_LIABILITY_INTENSITY, FinancialConstants::DEFAULT_LEASE_LIABILITY_INTENSITY],
        );
    }

    /**
     * The firm's target mix, direct-to-consumer, wholesale and contract supply in that order.
     *
     * @return list<float>
     */
    private function resolveTargetStreamWeights(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::DtcRetailWeight->value             => self::DTC_RETAIL_WEIGHT,
            ModelParam::WholesaleChannelWeight->value      => self::WHOLESALE_CHANNEL_WEIGHT,
            ModelParam::ContractTextileSupplyWeight->value => self::CONTRACT_TEXTILE_SUPPLY_WEIGHT,
        ]);

        return [$params[ModelParam::DtcRetailWeight], $params[ModelParam::WholesaleChannelWeight], $params[ModelParam::ContractTextileSupplyWeight]];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $pricingPower = $this->resolvePricingPower($stock);

        $outputGap = $this->resolveLaggedOutputGap($macroState);
        // The gap is priced beside it, so confidence enters as its residual over the gap (Lemmon & Portniaguina 2006).
        $sentimentShift = $macroState->sentimentResidual();
        $beta = $this->getOperatingCyclicality($stock);

        // Hybrid demand physics: pro-cyclical consumer sentiment and output gap, tempered by trading down in a
        // downturn and back up in a boom. Companies with lower pricing power (budget/mass-market basics) gain more
        // of the trade-down volume and lose more of it when consumers trade back up.
        $tradeDownScalar = self::TRADE_DOWN_SCALAR * (1.5 - $pricingPower);
        $tradeDownBonus = -$outputGap * $tradeDownScalar;
        $proCyclicalDemand = ($outputGap * self::OUTPUT_GAP_SCALAR) + ($sentimentShift * self::CONSUMER_SENTIMENT_SCALAR);
        $blendedDemandShift = ($proCyclicalDemand * $beta) + $tradeDownBonus + $this->resolveFxDemandShift($macroState);

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
        ]);

        $pricingPower = $this->resolvePricingPower($stock);

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

        // Currency FX Export Competitiveness & Global Trade: a weaker currency and demand abroad boost textile exports.
        $contractFxBonus = $this->resolveFxDemandShift($macroState, self::CONTRACT_FX_EXPORT_SCALAR);
        $tradeShift = MacroTransmission::calculateForeignDemandShift($macroState->foreignOutputGapEma, sensitivity: self::FOREIGN_DEMAND_SENSITIVITY);

        // Output gap drives non-linear supply chain ordering contractions and inventory markdown pressures
        $outputGap = $macroState->outputGapEma;
        $outputGapContraction = $outputGap < 0.0 ? abs($outputGap) : 0.0;

        // 1. Forrester Bullwhip Effect: Wholesale retailers panic and freeze orders non-linearly under negative output
        //    gaps
        $bullwhipPenalty = ResponseCurves::calculateConvexPenalty($outputGapContraction, self::BULLWHIP_CONVEXITY, self::BULLWHIP_PENALTY_SCALAR);

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
        // 2. Fast Fashion Markdown Squeeze: During negative output gaps, unsold perishable inventory forces aggressive
        //    markdowns, raising variable cost ratios.
        $markdownPenalty = ResponseCurves::calculateConvexPenalty($outputGapContraction, self::MARKDOWN_CONVEXITY, self::MARKDOWN_SQUEEZE_SCALAR);

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

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
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
            'consumer_sentiment_index_ema',
            'exchange_rate_index_ema',
            'foreign_output_gap_ema',
            'import_price_level',
            'inventory_stock_gap_ema',
            'output_gap_ema',
            'output_gap_lag_6m',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
