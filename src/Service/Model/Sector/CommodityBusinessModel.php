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
 * Earnings strategy for Heavy Commodity Extractors, Miners, Energy Drillers, and Merchant Refiners.
 * 
 * Financial Physics:
 * - Ultimate Price Takers: Upstream extractors have zero ability to set their own prices; revenue is dictated
 *   by global commodity supply/demand super-cycles.
 * - Schwartz 1-Factor Convenience Yield: Spot revenues explode when physical inventories are tight (high convenience yield).
 * - 3-2-1 Crack Spread Physics: Downstream refining margins expand when output gap (demand) outpaces crude spot prices (input costs).
 * - Ricardian Marginal Cost: Diseconomies of scale apply to extraction. Pushing volume higher requires tapping 
 *   lower-grade, high-cost reserves, driving up the variable cost ratio quadratically.
 */
class CommodityBusinessModel extends StandardCorporateBusinessModel
{
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
    /** Baseline multiplier scaling spot price sensitivity to macro inflation and energy spikes (0.50 = 50% hedged). */
    public const SPOT_PRICE_SENSITIVITY = 0.50;

    // --- Spot Price & Schwartz Convenience Yield Physics ---
    /** Volatility multiplier for top-line revenue shocks driven by global commodity spot prices. */
    public const REVENUE_VARIANCE_SCALAR = 0.25;
    /** Volatility scalar applied to refining crack spread variance. */
    public const REFINING_SHOCK_VOLATILITY_SCALAR = 1.50;
    /** Sensitivity scalar translating excess macroeconomic inflation into spot price revenue. */
    public const INFLATION_BONUS_SCALAR = 1.00;
    /** Multiplier for macro output gap sensitivity on extraction volume. */
    public const MACRO_DEMAND_BETA_SCALAR = 1.50;

    // --- 3-2-1 Crack Spread Physics ---
    /** Demand elasticity: Sensitivity of output distillate/gasoline pricing to the macroeconomic output gap. */
    public const CRACK_SPREAD_DEMAND_ELASTICITY = 2.00;
    /** Input drag: Sensitivity of refining margins to the cost of raw crude/energy inputs squeezing the spread. */
    public const CRACK_SPREAD_INPUT_DRAG = 0.80;

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
        $physics['macro_demand_shift'] = $macroState->outputGapEma * self::MACRO_DEMAND_BETA_SCALAR * abs((float) $stock->getBeta());

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
        ]);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new StreamContext($momentum, $mathUtility);
        $beta = abs((float) $stock->getBeta());

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

        $extractionZ = $streams->generateZ('extraction_volume', 0.35);
        $spotZ       = $streams->generateZ('spot_price', 0.15);
        $refiningZ   = $streams->generateZ('refining_spread', 0.40);
        $eventZ      = $streams->generateZ('event', 0.10);

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

        // --- Spot Price & Schwartz Convenience Yield Dynamics ---
        $inflation = $macroState->inflationEma;
        $energyShift = ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0;

        $excessInflation = max(0.0, $inflation - MacroEngine::TARGET_INFLATION);
        $inflationBonus = $excessInflation * $beta * self::INFLATION_BONUS_SCALAR * $spotSensitivity;

        // High energy/metals/agri shifts imply high convenience yield (tight spot market inventory)
        $metalsShift = ($macroState->industrialMetalsIndexEma - 100.0) / 100.0;
        $agriShift = ($macroState->agriculturalCommodityIndexEma - 100.0) / 100.0;
        
        $commodityTightness = max(0.0, $energyShift) + max(0.0, $metalsShift * 0.50) + max(0.0, $agriShift * 0.50);
        $convenienceYieldBonus = $commodityTightness * self::INFLATION_BONUS_SCALAR * $spotSensitivity;

        // --- 3-2-1 Crack Spread Physics ---
        // Refineries buy raw energy (energyShift) and sell end products governed by industrial demand (outputGapEma).
        $crackSpreadDemand = $macroState->outputGapEma * self::CRACK_SPREAD_DEMAND_ELASTICITY * $beta;
        $crackSpreadCostSqueeze = max(0.0, $energyShift) * self::CRACK_SPREAD_INPUT_DRAG;
        $crackSpreadBonus = $crackSpreadDemand - $crackSpreadCostSqueeze;

        // --- Tri-Stream Revenue Calculation ---
        $extractionShock = $extractionZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);
        $spotShock       = $spotZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);
        $refiningShock   = $refiningZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::REFINING_SHOCK_VOLATILITY_SCALAR);

        $extractionRevenue = max(0.0, $expectedRevenue * $extractionWeight * (1.0 + $extractionShock) * $extractionMultiplier);
        $spotRevenue       = max(0.0, $expectedRevenue * $spotWeight * (1.0 + $spotShock + $inflationBonus + $convenienceYieldBonus) * $spotMultiplier);
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

        // Add frictions to the variable cost ratio (higher ratio = lower profits)
        $rawMargin = $effectiveMargin + $ricardianFriction + $disasterPenalty;
        $clampedMargin = $this->clampMargin($rawMargin);

        $primaryShockZ = $streams->resolveDominantShockZ([$extractionZ, $spotZ, $refiningZ], $eventZ);

        $spotShockTotal = $spotShock + $inflationBonus + $convenienceYieldBonus;
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

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            $decayRate = self::DEPRECIATION_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            $modGain = self::MODERNIZATION_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}
