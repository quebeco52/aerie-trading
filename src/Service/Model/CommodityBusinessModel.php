<?php

declare(strict_types=1);

namespace App\Service\Model;

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
 * - Inflation is a Blessing: While standard corporates suffer margin compression from raw material inflation,
 *   commodities *are* the supply chain—spot revenues explode during macro inflation and energy price spikes.
 * - Tri-Stream Commodity Architecture:
 *   1. Extraction Volume: Physical extraction, drilling, and mining throughput (subject to geological depletion,
 *      scale elasticity, and geopolitical sanction throttling).
 *   2. Spot Price Super-Cycle: Direct unhedged price-taker exposure to global spot commodity prices, inflation acceleration,
 *      and geopolitical export embargoes.
 *   3. Refining Crack Spread: Downstream refining margin (crude-to-distillate conversion arbitrage), expanding when
 *      industrial capacity utilization and macroeconomic output gaps are tight.
 * - Operating Leverage: Spot price shocks flow through at 100% operating margin (0% variable cost), creating massive
 *   earnings expansion during commodity super-cycles.
 */
class CommodityBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for publicly traded commodity and mining firms. */
    public const BASE_COVERAGE_VISIBILITY = 0.80;

    /** Base coverage forecasting error given wild swings in underlying commodity spot prices. */
    public const BASE_COVERAGE_ERROR = 0.10;

    // --- Tri-Stream Commodity Architecture ---
    /** Baseline fraction of revenue derived from physical extraction, drilling, and mining volume. */
    public const EXTRACTION_VOLUME_WEIGHT = 0.45;

    /** Baseline fraction of revenue derived from unhedged global commodity spot pricing exposure. */
    public const SPOT_PRICE_WEIGHT = 0.35;

    /** Baseline fraction of revenue derived from downstream crack spreads and merchant refining. */
    public const REFINING_SPREAD_WEIGHT = 0.20;

    /** Baseline multiplier scaling spot price sensitivity to macro inflation and energy spikes (0.50 = 50% hedged). */
    public const SPOT_PRICE_SENSITIVITY = 0.50;

    // --- Commodity Spot Price & Inflation Physics ---
    /** Volatility multiplier for top-line revenue shocks driven by global commodity spot prices. */
    public const REVENUE_VARIANCE_SCALAR = 0.25;

    /** Sensitivity scalar scaling excess inflation and stock beta to determine spot price revenue bonus. */
    public const INFLATION_BONUS_SCALAR = 1.00;

    /** Sensitivity of downstream crack spreads to macroeconomic output gap tightness. */
    public const CRACK_SPREAD_MACRO_SCALAR = 2.00;

    // --- Continuous Scale Elasticity ---
    /** Variable margin efficiency improvement per unit of positive physical extraction volume drift. */
    public const EXTRACTION_SCALE_ELASTICITY = 0.015;

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

    // --- Capital Reinvestment & Asset Depreciation Physics ---
    /** Quarterly efficiency decay rate per unit of underinvestment below replacement CapEx. */
    public const DEPRECIATION_DECAY_RATE = 0.025;

    /** Quarterly efficiency gain scalar per unit of logarithmic overinvestment above replacement CapEx. */
    public const MODERNIZATION_GAIN_RATE = 0.012;

    /** Structural minimum operating margin floor under extreme mining/drilling equipment aging. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.02;

    /** Structural maximum operating margin ceiling for state-of-the-art mining/drilling operations. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.32;

    public function getModelThresholds(): array
    {
        return [
            'min_icr'                  => 2.00,
            'bankrupt_equity'          => 0.0,
            'distress_equity'          => 0.0,
            'warning_equity'           => 0.0,
            'wholesale_leverage_limit' => 1.0,
            'dividend_crisis_icr'      => 1.50,
            'buyback_min_icr'          => 2.00,
            'reversion_speed'          => 0.30,
            'moat_spread'              => 0.000,
            'nwc_intensity'            => 0.15,
            'capex_completion_rate'    => 0.125,
        ];
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.01;
    }

    public function getCapexCyclicality(): float
    {
        return 3.0; // Extreme heavy industrial CapEx cycles (mine development, deepwater rigs, cat crackers)
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.30, 'revenue_weight' => 0.70];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Commodities are absolute price-takers with zero traditional corporate pricing power.
        // Top-line revenue is dynamically forced by spot market pricing and inflation bonuses.
        $physics['pricing_power_multiplier'] = 1.0;

        // Macro demand volume sensitivity (1.5x output gap)
        $physics['macro_demand_shift'] = $macroState->outputGapEma * 1.5 * abs((float) $stock->getBeta());

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

        $extractionWeight = $params[ModelParam::ExtractionRevenueWeight];
        $spotWeight       = $params[ModelParam::SpotPriceWeight];
        $refiningWeight   = $params[ModelParam::RefiningSpreadWeight];
        $spotSensitivity  = $params[ModelParam::SpotPriceSensitivity];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new StreamContext($momentum, $mathUtility);
        $beta = abs((float) $stock->getBeta());

        // Independent stream Z-scores with persistent AR(1) momentum
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
            $extractionMultiplier = 0.85;
        } elseif ($eventZ < self::GEOPOLITICAL_SANCTIONS_Z_SCORE) {
            $eventType = ShockEvent::GEOPOLITICAL_SANCTIONS;
            $extractionMultiplier = self::SANCTIONS_EXTRACTION_MULT;
        }

        // --- Macro Spot Price & Refining Crack Spread Drivers ---
        $inflation = $macroState->inflationEma;
        $energyShift = ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0;

        // Spot price unhedged inflation & energy bonuses
        $excessInflation = max(0.0, $inflation - MacroEngine::TARGET_INFLATION);
        $inflationBonus = $excessInflation * $beta * self::INFLATION_BONUS_SCALAR * $spotSensitivity;
        $energyBonus = max(0.0, $energyShift) * self::INFLATION_BONUS_SCALAR * $spotSensitivity;

        // Downstream refining crack spread surges during economic booms when refinery capacity is tight
        $crackSpreadBonus = max(0.0, $macroState->outputGapEma * self::CRACK_SPREAD_MACRO_SCALAR * $beta);

        // --- Tri-Stream Revenue Calculation ---
        $extractionShock = $extractionZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);
        $spotShock       = $spotZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);
        $refiningShock   = $refiningZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 1.5);

        $extractionRevenue = max(0.0, $expectedRevenue * $extractionWeight * (1.0 + $extractionShock) * $extractionMultiplier);
        $spotRevenue       = max(0.0, $expectedRevenue * $spotWeight * (1.0 + $spotShock + $inflationBonus + $energyBonus) * $spotMultiplier);
        $refiningRevenue   = max(0.0, $expectedRevenue * $refiningWeight * (1.0 + $refiningShock + $crackSpreadBonus));

        $actualRevenue = $extractionRevenue + $spotRevenue + $refiningRevenue;

        // --- Operating Margin & Scale Elasticity Physics ---
        // Realized variable margin is allocated across physical extraction and refining operations.
        // Pure spot price shocks carry 100% operating margin (0% variable cost), creating operating leverage.
        $physicalWeight = $extractionWeight + $refiningWeight;
        $physicalVariableMargin = $physicalWeight > 0 ? ($realizedVariableMargin / $physicalWeight) : $realizedVariableMargin;
        $actualVariableCosts = ($extractionRevenue + $refiningRevenue) * $physicalVariableMargin;

        $effectiveMargin = $actualRevenue > 0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;

        // Scale Elasticity: Higher extraction volume reduces unit mining/drilling cost
        $elasticityShift = -self::EXTRACTION_SCALE_ELASTICITY * $extractionZ * $extractionWeight;

        $clampedMargin = $this->clampMargin($effectiveMargin + $elasticityShift + $disasterPenalty);

        // Determine dominant primary shock driver
        $streamAbs = [
            'extraction_volume' => abs($extractionZ),
            'spot_price'        => abs($spotZ),
            'refining_spread'   => abs($refiningZ),
        ];
        arsort($streamAbs);
        $dominantKey = array_key_first($streamAbs);
        $primaryShockZ = match ($dominantKey) {
            'extraction_volume' => $extractionZ,
            'refining_spread'   => $refiningZ,
            default             => $spotZ,
        };

        if (abs($eventZ) > abs($primaryShockZ)) {
            $primaryShockZ = $eventZ;
        }

        // Analyst Observable Shock: Spot prices & macro bonuses are 100% public; extraction volume is partially observable
        $spotShockTotal = $spotShock + $inflationBonus + $energyBonus;
        $observableShockZ = ($extractionShock * $extractionWeight) +
            (($spotShockTotal * $spotWeight) / 0.20) +
            ($refiningShock * $refiningWeight * 0.50);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: [
                'extraction_volume' => $extractionZ,
                'spot_price'        => $spotZ,
                'refining_spread'   => $refiningZ,
                'event'             => $eventZ,
            ],
            streamRevenue: [
                'extraction_volume' => $extractionRevenue,
                'spot_price'        => $spotRevenue,
                'refining_spread'   => $refiningRevenue,
            ],
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
