<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for the Chemical Industry (Petrochemicals, Specialty Chemicals, Agrochemicals).
 *
 * Financial Physics:
 * - Petrochemical Crack Spread: Variable costs explode when energy feedstock prices spike.
 * - Tri-Stream Demand Architecture:
 *      1. Base Petrochemicals: Highly cyclical, volume price takers driven geometrically by output gap and industrial metals.
 *      2. Specialty Chemicals: Defensive, high-margin, patent-protected electronic materials and catalysts with asymmetric cost pass-through.
 *      3. Agrochemicals: Uncorrelated to standard macro cycles; driven by agricultural commodity indices and weather jump diffusion.
 * - Asymmetric Feedstock Pass-Through: Specialty chemicals pass energy inflation through; base chemicals only pass through in positive output gap regimes.
 * - Capital Intensity & Plant Turnarounds: Continuous chemical corrosion requires strict maintenance CapEx; underinvestment causes compounding margin decay and turnaround downtime.
 */
class ChemicalBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for chemical sector analysts tracking feedstock crack spreads. */
    public const BASE_COVERAGE_VISIBILITY = 0.40;
    /** Standard forecasting error on chemical margins and commodity feedstock volatility. */
    public const BASE_COVERAGE_ERROR = 0.08;
    /** Minimum visibility floor for analyst consensus models. */
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.20;

    // --- Tri-Stream Architecture Baseline Weights ---
    /** Baseline fraction of revenue from cyclical base olefins, aromatics, and bulk petrochemicals. */
    public const BASE_PETROCHEMICALS_WEIGHT = 0.50;
    /** Baseline fraction of revenue from defensive, high-margin specialty chemicals and catalysts. */
    public const SPECIALTY_CHEMICALS_WEIGHT = 0.30;
    /** Baseline fraction of revenue from non-cyclical fertilizers and crop protection agrochemicals. */
    public const AGROCHEMICALS_WEIGHT = 0.20;

    // --- Stream Variance & Persistence Scalars ---
    /** Volatility multiplier for base petrochemical market price swings. */
    public const BASE_PETRO_VARIANCE = 0.25;
    /** Volatility multiplier for specialty chemical demand shocks. */
    public const SPECIALTY_CHEM_VARIANCE = 0.08;
    /** Volatility multiplier for agrochemical demand shocks. */
    public const AGROCHEM_VARIANCE = 0.15;
    /** AR(1) persistence coefficient for base petrochemical revenue shocks. */
    public const BASE_PETRO_PERSISTENCE = 0.30;
    /** AR(1) persistence coefficient for specialty chemical revenue shocks. */
    public const SPECIALTY_CHEM_PERSISTENCE = 0.15;
    /** AR(1) persistence coefficient for agrochemical revenue shocks. */
    public const AGROCHEM_PERSISTENCE = 0.25;
    /** AR(1) persistence coefficient for feedstock crack spread margin shocks. */
    public const FEEDSTOCK_CRACK_PERSISTENCE = 0.20;

    // --- Structural Variable Cost Multipliers ---
    /** Variable cost multiplier for bulk base petrochemicals with high energy intensity. */
    public const BASE_PETRO_VARIABLE_COST_MULTIPLIER = 1.15;
    /** Variable cost multiplier for high-margin specialty chemicals with patent moats. */
    public const SPECIALTY_CHEM_VARIABLE_COST_MULTIPLIER = 0.80;
    /** Variable cost multiplier for agrochemicals and fertilizer manufacturing. */
    public const AGROCHEM_VARIABLE_COST_MULTIPLIER = 1.00;

    // --- Macro Demand & Industrial Sensitivity ---
    /** Sensitivity of base petrochemical revenue to the macroeconomic output gap. */
    public const BASE_PETRO_OUTPUT_GAP_SCALAR = 1.60;
    /** Sensitivity of base petrochemical revenue to industrial metals demand index. */
    public const BASE_PETRO_METALS_SCALAR = 0.60;
    /** Sensitivity of agrochemical revenue to the agricultural commodity price index. */
    public const AGRI_COMMODITY_SCALAR = 0.70;
    /** Weight of cyclical industrial demand in aggregate macroeconomic demand shift. */
    public const INDUSTRIAL_DEMAND_WEIGHT = 0.70;
    /** Weight of agricultural commodity shift in aggregate macroeconomic demand shift. */
    public const AGRI_DEMAND_WEIGHT = 0.30;

    // --- Feedstock Crack Spread & Asymmetric Pass-Through Physics ---
    /** Energy feedstock cost intensity multiplier on variable operating margins. */
    public const ENERGY_FEEDSTOCK_INTENSITY = 0.35;
    /** Proportion of energy inflation specialty chemicals can pass through via pricing power. */
    public const SPECIALTY_PASS_THROUGH_RATIO = 0.95;
    /** Proportion of energy inflation base petrochemicals can pass through during positive output gap expansions. */
    public const BASE_PETRO_EXPANSION_PASS_THROUGH_RATIO = 0.75;
    /** Pass-through multiplier for agrochemical feedstock costs during agricultural commodity bull markets. */
    public const AGRI_PASS_THROUGH_SCALAR = 0.60;
    /** Volatility scalar for unhedged spot feedstock crack spread shocks on variable margins. */
    public const FEEDSTOCK_DRAG_SCALAR = 0.30;

    // --- Weather Jump Diffusion ---
    /** Annualized Poisson jump intensity for extreme weather shocks impacting agrochemicals. */
    public const WEATHER_JUMP_LAMBDA = 0.25;
    /** Mean log-return impact of extreme weather events on agrochemical demand. */
    public const WEATHER_JUMP_MEAN = 0.00;
    /** Volatility of weather shock jumps. */
    public const WEATHER_JUMP_VOL = 0.12;

    // --- Tail Risk & Shock Event Thresholds ---
    /** Z-score threshold for severe crack spread squeeze triggering event lore. */
    public const CRACK_SPREAD_SQUEEZE_Z = -2.20;
    /** Z-score threshold for positive agrochemical demand boom. */
    public const AGRI_BOOM_Z = 2.20;
    /** Severe energy inflation threshold above baseline triggering crack spread crisis lore during recessions. */
    public const SEVERE_ENERGY_INFLATION_THRESHOLD = 0.30;
    /** Output gap contraction threshold defining recessionary conditions for event triggers. */
    public const RECESSION_OUTPUT_GAP_THRESHOLD = -0.02;
    /** Minimum weather jump multiplier threshold required to trigger agricultural boom event. */
    public const WEATHER_BOOM_MULTIPLIER_THRESHOLD = 1.15;

    // --- Plant Corrosion, Turnaround & Asset Depreciation Physics ---
    /** Compounding quarterly margin decay rate under deferred maintenance and corrosion. */
    public const PLANT_DECAY_RATE = 0.020;
    /** Quarterly efficiency gain scalar per unit of logarithmic overinvestment into advanced chemical synthesis. */
    public const PLANT_MODERNIZATION_GAIN = 0.010;
    /** Structural minimum operating margin floor under severe plant corrosion and downtime. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.05;
    /** Structural maximum operating margin ceiling for optimized chemical manufacturing plants. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.30;
    /** Operating margin mean reversion speed for chemical manufacturing economics. */
    public const CHEMICAL_REVERSION_SPEED = 0.12;

    // --- Valuation, Growth & CapEx Rails ---
    /** Multiplier scaling heavy continuous chemical synthesis and cracking plant capital expenditure cycles. */
    public const CHEMICAL_CAPEX_CYCLICALITY = 3.50;
    /** Baseline secular growth rate for the diversified chemical sector. */
    public const CHEMICAL_SECULAR_GROWTH = 0.02;
    /** Weight given to EPS surprise when calculating aggregate earnings surprise. */
    public const SURPRISE_EPS_WEIGHT = 0.45;
    /** Weight given to revenue surprise when calculating aggregate earnings surprise. */
    public const SURPRISE_REVENUE_WEIGHT = 0.55;

    // --- Working Capital Intensities ---
    /** High inventory and hydrocarbon feedstock storage working capital intensity for base petrochemicals. */
    public const BASE_PETRO_NWC_INTENSITY = 0.24;
    /** Moderate working capital intensity for specialty chemicals. */
    public const SPECIALTY_CHEM_NWC_INTENSITY = 0.18;
    /** Seasonal fertilizer inventory stockpiling working capital intensity for agrochemicals. */
    public const AGROCHEM_NWC_INTENSITY = 0.28;

    public function getModelThresholds(): array
    {
        return [
            'min_icr' => 2.00,
            'bankrupt_equity' => 0.0,
            'distress_equity' => 0.0,
            'warning_equity' => 0.0,
            'wholesale_leverage_limit' => 1.5,
            'dividend_crisis_icr' => 1.50,
            'buyback_min_icr' => 2.00,
            'reversion_speed' => self::CHEMICAL_REVERSION_SPEED,
            'moat_spread' => 0.010,
            'nwc_intensity' => 0.23,
            'capex_completion_rate' => 0.30,
        ];
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return self::CHEMICAL_SECULAR_GROWTH;
    }

    public function getCapexCyclicality(): float
    {
        return self::CHEMICAL_CAPEX_CYCLICALITY;
    }

    public function getSurpriseBlendWeights(): array
    {
        return [
            'eps_weight' => self::SURPRISE_EPS_WEIGHT,
            'revenue_weight' => self::SURPRISE_REVENUE_WEIGHT,
        ];
    }

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::BasePetrochemicalsWeight->value => self::BASE_PETROCHEMICALS_WEIGHT,
            ModelParam::SpecialtyChemicalsWeight->value => self::SPECIALTY_CHEMICALS_WEIGHT,
            ModelParam::AgrochemicalsWeight->value      => self::AGROCHEMICALS_WEIGHT,
        ]);

        $basePetroWeight  = $params[ModelParam::BasePetrochemicalsWeight];
        $specialtyWeight  = $params[ModelParam::SpecialtyChemicalsWeight];
        $agriWeight       = $params[ModelParam::AgrochemicalsWeight];

        $totalWeight = max(0.01, $basePetroWeight + $specialtyWeight + $agriWeight);

        return (($basePetroWeight * self::BASE_PETRO_NWC_INTENSITY) +
                ($specialtyWeight * self::SPECIALTY_CHEM_NWC_INTENSITY) +
                ($agriWeight * self::AGROCHEM_NWC_INTENSITY)) / $totalWeight;
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::PricingPowerIndex->value => 0.50,
        ]);
        $pricingPower = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));

        $outputGap = $macroState->outputGapEma;
        $metalsShift = ($macroState->industrialMetalsIndexEma - 100.0) / 100.0;
        $agriShift = ($macroState->agriculturalCommodityIndexEma - 100.0) / 100.0;
        $inflation = $macroState->inflationEma;
        $beta = (float) $stock->getBeta();

        // Macro demand shift: Driven by industrial demand (output gap + metals) for base chemicals,
        // and agricultural commodities for agrochemicals.
        $industrialDemand = ($outputGap * self::BASE_PETRO_OUTPUT_GAP_SCALAR) + ($metalsShift * self::BASE_PETRO_METALS_SCALAR);
        $blendedDemandShift = ($industrialDemand * $beta * self::INDUSTRIAL_DEMAND_WEIGHT) + ($agriShift * self::AGRI_DEMAND_WEIGHT);

        return [
            'macro_demand_shift' => $blendedDemandShift,
            'pricing_power_multiplier' => 1.0 + ($inflation * max(self::MIN_BETA_PRICING_POWER_FLOOR, $beta) * $pricingPower),
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::BasePetrochemicalsWeight->value => self::BASE_PETROCHEMICALS_WEIGHT,
            ModelParam::SpecialtyChemicalsWeight->value => self::SPECIALTY_CHEMICALS_WEIGHT,
            ModelParam::AgrochemicalsWeight->value      => self::AGROCHEMICALS_WEIGHT,
            ModelParam::PricingPowerIndex->value        => 0.50,
        ]);

        $pricingPower = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new StreamContext($momentum, $mathUtility);

        // Active stream weights with dynamic drift
        $activeWeights = $streams->resolveActiveStreamWeights([
            'base_petrochemicals' => $params[ModelParam::BasePetrochemicalsWeight],
            'specialty_chemicals' => $params[ModelParam::SpecialtyChemicalsWeight],
            'agrochemicals'       => $params[ModelParam::AgrochemicalsWeight],
        ]);

        $basePetroWeight  = $activeWeights['base_petrochemicals'];
        $specialtyWeight  = $activeWeights['specialty_chemicals'];
        $agriWeight       = $activeWeights['agrochemicals'];

        // Z-scores
        $basePetroZ  = $streams->generateZ('base_petrochemicals', self::BASE_PETRO_PERSISTENCE);
        $specialtyZ  = $streams->generateZ('specialty_chemicals', self::SPECIALTY_CHEM_PERSISTENCE);
        $agriZ       = $streams->generateZ('agrochemicals', self::AGROCHEM_PERSISTENCE);
        $feedstockZ  = $streams->generateZ('feedstock_crack', self::FEEDSTOCK_CRACK_PERSISTENCE);

        // 1. Base Petrochemicals: Cyclical stream idiosyncratic volume variance
        $basePetroShock = $basePetroZ * ($baselineVol * self::BASE_PETRO_VARIANCE);
        $basePetroRevenue = max(0.0, $expectedRevenue * $basePetroWeight * (1.0 + $basePetroShock));

        // 2. Specialty Chemicals: Defensive, sticky margins, patent moats, ignores short-term cyclicality
        $specialtyShock = $specialtyZ * ($baselineVol * self::SPECIALTY_CHEM_VARIANCE);
        $specialtyRevenue = max(0.0, $expectedRevenue * $specialtyWeight * (1.0 + $specialtyShock));

        // 3. Agrochemicals: Disconnected from business cycle, driven by agricultural weather jump diffusion
        $dt = 0.25; // Quarterly time step
        $weatherJump = $mathUtility->calculateJumpDiffusion(self::WEATHER_JUMP_LAMBDA, self::WEATHER_JUMP_MEAN, self::WEATHER_JUMP_VOL, $dt);
        $weatherMultiplier = $weatherJump['multiplier'];

        $agriShock = $agriZ * ($baselineVol * self::AGROCHEM_VARIANCE);
        $agriRevenue = max(0.0, $expectedRevenue * $agriWeight * (1.0 + $agriShock) * $weatherMultiplier);

        $streamRevenues = [
            'base_petrochemicals' => $basePetroRevenue,
            'specialty_chemicals' => $specialtyRevenue,
            'agrochemicals'       => $agriRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // --- Feedstock Margin Squeeze (Crack Spreads) ---
        // Hydrocarbon cracking (ethane, naphtha, natural gas).
        // When energyPriceIndexEma spikes, variable costs explode.
        $outputGap = $macroState->outputGapEma;
        $agriShift = ($macroState->agriculturalCommodityIndexEma - 100.0) / 100.0;
        $energyInflation = max(0.0, ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0);

        // Asymmetric Pass-Through:
        // Specialty chemicals pass through ~95% (scaled by pricing power)
        $specialtyPassThrough = $energyInflation * min(1.0, self::SPECIALTY_PASS_THROUGH_RATIO * (0.50 + (0.50 * $pricingPower)));
        $specialtyUnpassedEnergy = max(0.0, $energyInflation - $specialtyPassThrough);

        // Base chemicals can only pass costs through if outputGapEma > 0; in a recession, forced to eat the cost
        $basePetroPassThrough = $outputGap > 0.0
            ? $energyInflation * self::BASE_PETRO_EXPANSION_PASS_THROUGH_RATIO * min(1.0, 0.50 + (0.50 * $pricingPower))
            : 0.0;
        $basePetroUnpassedEnergy = max(0.0, $energyInflation - $basePetroPassThrough);

        // Agrochemicals have moderate pass-through based on agricultural price strength
        $agriPassThrough = $agriShift > 0.0 ? min($energyInflation, $agriShift * self::AGRI_PASS_THROUGH_SCALAR) : 0.0;
        $agriUnpassedEnergy = max(0.0, $energyInflation - $agriPassThrough);

        // Blended unpassed feedstock energy drag on variable costs
        $basePetroCostRatio  = ($realizedVariableMargin * self::BASE_PETRO_VARIABLE_COST_MULTIPLIER) + ($basePetroUnpassedEnergy * self::ENERGY_FEEDSTOCK_INTENSITY);
        $specialtyCostRatio  = ($realizedVariableMargin * self::SPECIALTY_CHEM_VARIABLE_COST_MULTIPLIER) + ($specialtyUnpassedEnergy * self::ENERGY_FEEDSTOCK_INTENSITY);
        $agriCostRatio       = ($realizedVariableMargin * self::AGROCHEM_VARIABLE_COST_MULTIPLIER) + ($agriUnpassedEnergy * self::ENERGY_FEEDSTOCK_INTENSITY);

        $actualVariableCosts = ($basePetroRevenue * $basePetroCostRatio)
            + ($specialtyRevenue * $specialtyCostRatio)
            + ($agriRevenue * $agriCostRatio);

        $effectiveMargin = $actualRevenue > 0.0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;

        // Feedstock crack volatility shock
        $feedstockDrag = max(0.0, -$feedstockZ * (self::FEEDSTOCK_DRAG_SCALAR / 10.0) * $baselineVol);
        $clampedMargin = $this->clampMargin($effectiveMargin + $feedstockDrag);

        // Shock events
        $eventType = null;
        if ($feedstockZ < self::CRACK_SPREAD_SQUEEZE_Z || ($energyInflation > self::SEVERE_ENERGY_INFLATION_THRESHOLD && $outputGap < self::RECESSION_OUTPUT_GAP_THRESHOLD)) {
            $eventType = ShockEvent::CHEMICAL_CRACK_SPREAD_SQUEEZE;
        } elseif ($agriZ > self::AGRI_BOOM_Z && $weatherMultiplier > self::WEATHER_BOOM_MULTIPLIER_THRESHOLD) {
            $eventType = ShockEvent::CHEMICAL_AGRI_BOOM;
        }

        $primaryShockZ = $streams->resolveDominantShockZ([$basePetroZ, $specialtyZ, $agriZ, $feedstockZ]);
        $observableShockZ = ($basePetroZ * $basePetroWeight * self::BASE_PETRO_VARIANCE) +
            ($specialtyZ * $specialtyWeight * self::SPECIALTY_CHEM_VARIANCE) +
            ($agriZ * $agriWeight * self::AGROCHEM_VARIANCE);
        $observableShockZ *= $baselineVol;

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
            // Physical corrosion and deferred maintenance downtime create margin decay
            $underinvestment = 1.0 - $reinvestmentRatio;
            $decay = self::PLANT_DECAY_RATE * $underinvestment * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decay));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // Modernization and continuous flow chemical synthesis expansion
            $modGain = self::PLANT_MODERNIZATION_GAIN * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}
