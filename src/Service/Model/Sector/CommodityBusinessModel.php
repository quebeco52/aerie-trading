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
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Heavy Commodity Extractors, Miners, Energy Drillers, and Merchant Refiners.
 * 
 * Financial Physics:
 * - Ultimate Price Takers: Upstream extractors have zero ability to set their own prices; revenue is dictated
 *   by global commodity supply/demand super-cycles.
 * - Price x Volume: the spot price is a MARKET variable, the exposure-weighted commodity complex index (energy,
 *   industrial metals, agriculture), signed in both directions. The firm-specific residual is only the basis
 *   differential (grade, location, contract timing).
 * - Theory of Storage (Working 1949): tight physical inventories put the curve in backwardation and pay a
 *   convenience yield to holders of physical barrels; ample inventories (contango) pay nothing.
 * - 3-2-1 Crack Spread Physics: Downstream refining margins expand when output gap (demand) outpaces crude spot prices (input costs).
 * - Ricardian Marginal Cost: Diseconomies of scale apply to extraction. Pushing volume higher requires tapping 
 *   lower-grade, high-cost reserves, driving up the variable cost ratio quadratically.
 */
class CommodityBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Price-taker volumes sold into a global pool: peers barely notice a rival barrel. */
    public const OPERATING_CYCLICALITY = 1.30;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.10;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.15;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['energy' => 0.15, 'metals' => 0.05, 'ppi' => 0.10, 'labor' => 0.20];
    /** Price takers recover none of their own input inflation through pricing: the market sets the quote. */
    public const PRICING_POWER_INDEX = 0.00;

    /**
     * Calendar-quarter revenue seasonality [Q1, Q2, Q3, Q4] summing to 4.0: winter heating demand for energy volumes.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [1.03, 0.98, 0.97, 1.02];
    }

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Extraction overhead is dominated by rigs, mines and royalties, not payroll. */
    public const FIXED_COST_LABOR_SHARE = 0.30;

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for publicly traded commodity and mining firms. */
    public const BASE_COVERAGE_VISIBILITY = 0.80;
    /** Base coverage forecasting error given wild swings in underlying commodity spot prices. */
    public const BASE_COVERAGE_ERROR = 0.10;
    /** Divisor used to scale up the observable shock magnitude for spot price movements (which are 100% public). */
    public const OBSERVABLE_SPOT_WEIGHT_DIVISOR = 0.20;
    /** Multiplier used to scale down the observable shock magnitude for refining operations. */
    public const OBSERVABLE_REFINING_WEIGHT_SCALAR = 0.50;

    // --- Tri-Stream Commodity Architecture ---
    /** Baseline fraction of revenue derived from physical extraction, drilling, and mining volume. */
    public const EXTRACTION_VOLUME_WEIGHT = 0.45;
    /** Baseline fraction of revenue derived from unhedged global commodity spot pricing exposure. */
    public const SPOT_PRICE_WEIGHT = 0.35;
    /** Baseline fraction of revenue derived from downstream crack spreads and merchant refining. */
    public const REFINING_SPREAD_WEIGHT = 0.20;
    /** Baseline pass-through of commodity complex price moves to spot revenue (0.50 = 50% hedged). */
    public const SPOT_PRICE_SENSITIVITY = 0.50;

    // --- Commodity Complex Exposure (price-taker book) ---
    /** Default share of the spot book priced off the energy complex (crude, gas, refined products). */
    public const ENERGY_PRICE_EXPOSURE = 0.60;
    /** Default share of the spot book priced off industrial metals (copper, aluminium, iron ore). */
    public const INDUSTRIAL_METALS_EXPOSURE = 0.20;
    /** Default share of the spot book priced off agricultural commodities (grains, softs). */
    public const AGRICULTURAL_EXPOSURE = 0.20;
    /** Firm-specific basis differential noise as a fraction of the revenue variance scalar (price itself is a market variable). */
    public const BASIS_DIFFERENTIAL_VOL_SCALAR = 0.40;
    /** Revenue pass-through of the theory-of-storage convenience yield earned on physical energy inventory. */
    public const CONVENIENCE_YIELD_SCALAR = 1.00;

    // --- Spot Price & Schwartz Convenience Yield Physics ---
    /** Volatility multiplier for top-line revenue shocks driven by global commodity spot prices. */
    public const REVENUE_VARIANCE_SCALAR = 0.25;
    /** Volatility scalar applied to refining crack spread variance. */
    public const REFINING_SHOCK_VOLATILITY_SCALAR = 1.50;
    /** Signed pass-through of inflation above or below target into nominal spot revenue (commodities hedge inflation both ways). */
    public const INFLATION_BONUS_SCALAR = 1.00;
    /** Multiplier for macro output gap sensitivity on extraction volume. */
    public const MACRO_DEMAND_BETA_SCALAR = 1.50;

    // --- 3-2-1 Crack Spread Physics ---
    /** Demand elasticity: Sensitivity of output distillate/gasoline pricing to the macroeconomic output gap. */
    public const CRACK_SPREAD_DEMAND_ELASTICITY = 2.00;
    /** Input drag: Sensitivity of refining margins to the cost of raw crude/energy inputs squeezing the spread. */
    public const CRACK_SPREAD_INPUT_DRAG = 0.80;
    /** Sensitivity of merchant refining revenue to macro 3:2:1 refining crack spread index deviation from baseline. */
    public const CRACK_SPREAD_INDEX_SENSITIVITY = 0.35;

    // --- Ricardian Diminishing Returns (Marginal Cost) ---
    /** Quadratic variable cost penalty per unit of excess extraction volume (tapping lower grade reserves). */
    public const RICARDIAN_EXTRACTION_FRICTION = 0.025;

    // --- Tail Risk & Shock Events ---
    /** Negative Z-score threshold indicating severe geopolitical sanctions throttling physical exports. */
    public const GEOPOLITICAL_SANCTIONS_Z_SCORE = -2.20;
    /** Revenue throughput multiplier applied during geopolitical export sanctions and blockades. */
    public const SANCTIONS_EXTRACTION_MULT = 0.75;
    /** Positive Z-score threshold indicating a massive geopolitical export embargo or supply squeeze. */
    public const GEOPOLITICAL_EXPORT_BAN_Z_SCORE = 2.40;
    /** Multiplier applied to global spot prices during a geopolitical export ban or supply squeeze. */
    public const EXPORT_BAN_SPOT_MULT = 1.30;
    /** Negative Z-score threshold indicating a catastrophic mine collapse or deepwater environmental blowout. */
    public const ENVIRONMENTAL_DISASTER_Z_SCORE = -2.60;
    /** Variable cost penalty applied to fund environmental remediation, rig repairs, and containment liabilities. */
    public const ENVIRONMENTAL_DISASTER_PENALTY = 0.08;
    /** Output restriction multiplier applied to extraction volume during an environmental disaster. */
    public const DISASTER_EXTRACTION_MULT = 0.85;

    // --- Capital Reinvestment & Asset Depreciation Physics ---
    /** Quarterly efficiency decay rate per unit of underinvestment below replacement CapEx. */
    public const DEPRECIATION_DECAY_RATE = 0.025;
    /** Quarterly efficiency gain scalar per unit of logarithmic overinvestment above replacement CapEx. */
    public const MODERNIZATION_GAIN_RATE = 0.012;
    /** Structural minimum operating margin floor under extreme mining/drilling equipment aging. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.02;
    /** Structural maximum operating margin ceiling for state-of-the-art mining/drilling operations. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.32;
    /** Extreme heavy industrial CapEx cycles (mine development, deepwater rigs, cat crackers). */
    public const COMMODITY_CAPEX_CYCLICALITY = 3.0;
    /** Baseline secular growth rate for heavily mature commodity producers. */
    public const COMMODITY_SECULAR_GROWTH = 0.01;

        public function getReversionSpeed(): float { return 0.3; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.125; }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return self::COMMODITY_SECULAR_GROWTH;
    }

    public function getCapexCyclicality(): float
    {
        return self::COMMODITY_CAPEX_CYCLICALITY;
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.30, 'revenue_weight' => 0.70];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        $physics['pricing_power_multiplier'] = 1.0;
        // Inflation is carried inside this model's own stream physics: neither price nor cost base inflates at the engine level.
        $physics['input_cost_multiplier'] = 1.0;
        $physics['macro_demand_shift'] = $macroState->outputGapEma * self::MACRO_DEMAND_BETA_SCALAR * $this->getOperatingCyclicality($stock);

        return $physics;
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
            ModelParam::ExtractionRevenueWeight->value => self::EXTRACTION_VOLUME_WEIGHT,
            ModelParam::SpotPriceWeight->value         => self::SPOT_PRICE_WEIGHT,
            ModelParam::RefiningSpreadWeight->value    => self::REFINING_SPREAD_WEIGHT,
            ModelParam::SpotPriceSensitivity->value    => self::SPOT_PRICE_SENSITIVITY,
            ModelParam::EnergyPriceExposure->value     => self::ENERGY_PRICE_EXPOSURE,
            ModelParam::IndustrialMetalsExposure->value => self::INDUSTRIAL_METALS_EXPOSURE,
            ModelParam::AgriculturalExposure->value    => self::AGRICULTURAL_EXPOSURE,
        ]);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $beta = $this->getOperatingCyclicality($stock);

        // --- Dynamic Revenue Mix Drift ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'extraction_volume' => $params[ModelParam::ExtractionRevenueWeight],
            'spot_price'        => $params[ModelParam::SpotPriceWeight],
            'refining_spread'   => $params[ModelParam::RefiningSpreadWeight],
        ]);

        $extractionWeight = $activeWeights['extraction_volume'];
        $spotWeight       = $activeWeights['spot_price'];
        $refiningWeight   = $activeWeights['refining_spread'];
        $spotSensitivity  = $params[ModelParam::SpotPriceSensitivity];
        $energyExposure   = max(0.0, $params[ModelParam::EnergyPriceExposure]);
        $metalsExposure   = max(0.0, $params[ModelParam::IndustrialMetalsExposure]);
        $agriExposure     = max(0.0, $params[ModelParam::AgriculturalExposure]);

        $extractionZ = $streams->generateZ('extraction_volume', 0.35);
        $spotZ       = $streams->generateZ('spot_price', 0.15);
        $refiningZ   = $streams->generateZ('refining_spread', 0.40);
        $eventZ      = $streams->generateExogenousZ('event', 0.10);

        // --- Tail Risk & Geopolitical Events ---
        $extractionMultiplier = 1.0;
        $spotMultiplier = 1.0;
        $eventType = null;
        $disasterPenalty = 0.0;

        if ($eventZ > self::GEOPOLITICAL_EXPORT_BAN_Z_SCORE) {
            $eventType = ShockEvent::GEOPOLITICAL_EXPORT_BAN;
            $spotMultiplier = self::EXPORT_BAN_SPOT_MULT;
        } elseif ($eventZ < self::ENVIRONMENTAL_DISASTER_Z_SCORE) {
            $eventType = ShockEvent::ENVIRONMENTAL_DISASTER;
            $disasterPenalty = self::ENVIRONMENTAL_DISASTER_PENALTY;
            $extractionMultiplier = self::DISASTER_EXTRACTION_MULT;
        } elseif ($eventZ < self::GEOPOLITICAL_SANCTIONS_Z_SCORE) {
            $eventType = ShockEvent::GEOPOLITICAL_SANCTIONS;
            $extractionMultiplier = self::SANCTIONS_EXTRACTION_MULT;
        }

        // --- Spot Price: a market variable, not a firm draw ---
        // Price-takers book price x volume. The price is the exposure-weighted commodity complex index, signed:
        // a 20% crude slump cuts an E&P's spot revenue just as a spike lifts it.
        $inflation   = $macroState->inflationEma;
        $energyShift = ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / MacroEngine::ENERGY_BASELINE;
        $metalsShift = ($macroState->industrialMetalsIndexEma - 100.0) / 100.0;
        $agriShift   = ($macroState->agriculturalCommodityIndexEma - 100.0) / 100.0;
        $complexPriceShift = ($energyExposure * $energyShift) + ($metalsExposure * $metalsShift) + ($agriExposure * $agriShift);

        // Nominal pass-through: commodities hedge inflation in both directions.
        $inflationBonus = ($inflation - MacroEngine::TARGET_INFLATION) * $beta * self::INFLATION_BONUS_SCALAR * $spotSensitivity;

        // Theory of storage: the convenience yield on physical energy inventory is zero in contango (ample
        // stocks) and rises non-linearly as inventories deplete toward the buffer floor (backwardation).
        $convenienceYield = $mathUtility->calculateConvenienceYield($macroState->energyInventoryIndexEma);
        $convenienceYieldBonus = $convenienceYield * self::CONVENIENCE_YIELD_SCALAR * $energyExposure * $spotSensitivity;

        // --- 3-2-1 Crack Spread Physics ---
        // Refineries buy raw energy (energyShift) and sell end products governed by industrial demand (outputGapEma).
        $crackSpreadDemand = $macroState->outputGapEma * self::CRACK_SPREAD_DEMAND_ELASTICITY * $beta;
        $crackSpreadCostSqueeze = max(0.0, $energyShift) * self::CRACK_SPREAD_INPUT_DRAG;
        $crackSpreadIndexShift = ($macroState->refiningCrackSpreadEma - MacroEngine::CRACK_SPREAD_BASELINE) / MacroEngine::CRACK_SPREAD_BASELINE;
        $crackSpreadBonus = $crackSpreadDemand - $crackSpreadCostSqueeze + ($crackSpreadIndexShift * self::CRACK_SPREAD_INDEX_SENSITIVITY);

        // --- Tri-Stream Revenue Calculation ---
        $extractionShock = $extractionZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);
        $spotShock       = $spotZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::BASIS_DIFFERENTIAL_VOL_SCALAR);
        $refiningShock   = $refiningZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::REFINING_SHOCK_VOLATILITY_SCALAR);

        $extractionRevenue = max(0.0, $expectedRevenue * $extractionWeight * (1.0 + $extractionShock) * $extractionMultiplier);
        $spotRevenue       = max(0.0, $expectedRevenue * $spotWeight * (1.0 + ($complexPriceShift * $spotSensitivity) + $spotShock + $inflationBonus + $convenienceYieldBonus) * $spotMultiplier);
        $refiningRevenue   = max(0.0, $expectedRevenue * $refiningWeight * (1.0 + $refiningShock + $crackSpreadBonus));

        $streamRevenues = [
            'extraction_volume' => $extractionRevenue,
            'spot_price'        => $spotRevenue,
            'refining_spread'   => $refiningRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Operating Margin & Ricardian Friction Physics ---
        $physicalWeight = $extractionWeight + $refiningWeight;
        $physicalVariableMargin = $physicalWeight > 0 ? ($realizedVariableMargin / $physicalWeight) : $realizedVariableMargin;
        $actualVariableCosts = ($extractionRevenue + $refiningRevenue) * $physicalVariableMargin;

        $effectiveMargin = $actualRevenue > 0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;

        // Ricardian Marginal Cost: Pushing extraction volume requires tapping lower-grade, high-cost reserves.
        $ricardianFriction = 0.0;
        if ($extractionZ > 0.0) {
            $ricardianFriction = pow($extractionZ, 2) * self::RICARDIAN_EXTRACTION_FRICTION * $extractionWeight;
        }

        // Lifting and processing costs: diesel and power, consumables, field payroll, bought at spot with no
        // pricing power to recover them (the commodity itself is the price).
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);

        // Add frictions to the variable cost ratio (higher ratio = lower profits)
        $rawMargin = $effectiveMargin + $ricardianFriction + $disasterPenalty + $inputCostDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        $primaryShockZ = $streams->resolveDominantShockZ([$extractionZ, $spotZ, $refiningZ], $eventZ);

        $spotShockTotal = ($complexPriceShift * $spotSensitivity) + $spotShock + $inflationBonus + $convenienceYieldBonus;
        $observableShockZ = ($extractionShock * $extractionWeight) +
            (($spotShockTotal * $spotWeight) / self::OBSERVABLE_SPOT_WEIGHT_DIVISOR) +
            ($refiningShock * $refiningWeight * self::OBSERVABLE_REFINING_WEIGHT_SCALAR);

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

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        return 0.15; // Physical mining inventory holding & bulk refining working capital
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        // Cyclical commodity extractors anchor to Book Value (replacement cost) during trough earnings and mid-cycle earnings during expansions
        $bookWeight = $normalizedEps < 0 ? 0.70 : 0.40;
        $earningsWeight = 1.0 - $bookWeight;

        $baseConsensus = ($earningsValue * $earningsWeight) + ($pbFairValue * $bookWeight);
        return $dividendSupportValue > 0.0
            ? ($baseConsensus * (1.0 - FinancialConstants::FAIR_VALUE_DDM_WEIGHT)) + ($dividendSupportValue * FinancialConstants::FAIR_VALUE_DDM_WEIGHT)
            : $baseConsensus;
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
            'energy_inventory_index_ema',
            'energy_price_index_ema',
            'exchange_rate_index_ema',
            'industrial_metals_index_ema',
            'inflation_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'refining_crack_spread_ema',
            'tips_breakeven_ema',
            'wage_growth_ema',
        ];
    }
}
