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
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Heavy Defense Contractors & Aerospace Weapons Manufacturers.
 * 
 * Financial Physics:
 * - Tri-Stream Defense Engine:
 *      1. Cost-Plus Sovereign Procurement & Sustainment: Multi-year appropriations, high persistence, dynamic FAR 16.3 inflation escalators.
 *      2. Fixed-Price Development & Classified R&D: Engineering & manufacturing development (EMD) subject to ASC 606 reach-forward losses.
 *      3. Foreign Military Sales (FMS): High-margin international exports driven by geopolitical threat levels and export licensing.
 * - Sovereign Fiscal Physics: Immune to consumer recessions, but exposed to Continuing Resolution (CR) budget freezes during debt ceiling stress.
 * - FAR Progress Payment Withholding: Program overruns/defects trigger working capital expansion (FAR 32.503-6) compressing FCF.
 * - Classified Tooling & Platform Modernization: Multi-decade tooling tech debt vs next-gen franchise margin expansion.
 */
class DefenseContractorBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Forecasting ---
    /** Base coverage visibility for defense contractors operating under transparent sovereign defense appropriations. */
    public const BASE_COVERAGE_VISIBILITY = 0.60;
    /** Base analyst forecasting error standard deviation for long-cycle defense programs. */
    public const BASE_COVERAGE_ERROR = 0.08;

    // --- Tri-Stream Architecture Weights ---
    /** Baseline fraction of revenue derived from long-term cost-plus sovereign defense procurement and sustainment lots. */
    public const COST_PLUS_WEIGHT = 0.60;
    /** Baseline fraction of revenue derived from fixed-price development, prototyping, and classified R&D. */
    public const FIXED_PRICE_DEV_WEIGHT = 0.20;
    /** Baseline fraction of revenue derived from foreign military sales (FMS) and direct commercial sales. */
    public const FOREIGN_MILITARY_SALES_WEIGHT = 0.20;

    // --- Stream Variance & Volatility Scalars ---
    /** Variance scalar for rock-solid, multi-year sovereign cost-plus defense procurement lots. */
    public const COST_PLUS_VARIANCE_SCALAR = 0.04;
    /** Variance scalar for engineering and manufacturing development contracts. */
    public const FIXED_PRICE_DEV_VARIANCE_SCALAR = 0.15;
    /** Variance scalar for volatile, geopolitically sensitive foreign military sales. */
    public const FMS_VARIANCE_SCALAR = 0.35;

    // --- Sovereign Procurement & Cost-Plus Physics ---
    /** Top-line multiplier converting inflation above target into cost-plus contractual price escalators (FAR 16.3). */
    public const COST_PLUS_BONUS_SCALAR = 1.50;
    /** Sovereign credit spread threshold above which budget ceiling standoffs and Continuing Resolutions freeze procurement. */
    public const SOVEREIGN_STRESS_THRESHOLD = 0.030;
    /** Top-line procurement drag scalar per unit of sovereign credit spread widen during Continuing Resolution standoffs. */
    public const CR_BUDGET_DRAG_SCALAR = 2.50;

    // --- Fixed-Price Development Forward-Loss Physics ---
    /** Negative Z-score threshold triggering ASC 606 reach-forward loss write-offs on development contracts. */
    public const FORWARD_LOSS_Z_SCORE = -1.50;
    /** Variable cost penalty applied to recognize forward losses and absorb engineering cost overruns. */
    public const FORWARD_LOSS_PENALTY = 0.08;
    /** Sensitivity of fixed-price development margins to unhedged energy and industrial commodity inflation shocks. */
    public const FIXED_PRICE_MATERIAL_DRAG_SCALAR = 0.40;

    // --- Geopolitical Sanctions & Conflict Tail Shocks ---
    /** Negative Z-score threshold triggering congressional arms export license revocations or regional embargoes. */
    public const CONGRESSIONAL_EXPORT_BAN_Z = -2.00;
    /** Top-line revenue haircut applied to foreign military sales following congressional export bans. */
    public const EXPORT_BAN_MULT = 0.50;
    /** Positive Z-score threshold indicating acute geopolitical conflict and wartime munitions demand. */
    public const GEOPOLITICAL_CONFLICT_Z = 2.00;
    /** Revenue multiplier boost on foreign military sales during active geopolitical conflicts. */
    public const FMS_CONFLICT_BOOST = 1.50;
    /** Variable margin drag from emergency supply chain expediting and critical titanium/alloy spot surcharges. */
    public const WARTIME_SUPPLY_CHAIN_DRAG = 0.035;

    // --- Major Programmatic Contract Shocks ---
    /** Negative Z-score threshold indicating catastrophic flagship platform structural flaw or fleet grounding. */
    public const FLAGSHIP_FAILURE_Z_SCORE = -2.50;
    /** Top-line revenue multiplier haircut applied to domestic procurement following flagship program defects. */
    public const FLAGSHIP_FAILURE_MULT = 0.80;
    /** Variable cost penalty required to remediate engineering defects during fleet groundings. */
    public const FLAGSHIP_FAILURE_PENALTY = 0.10;
    /** Positive Z-score threshold indicating award of a major multi-year next-generation platform franchise. */
    public const MEGA_CONTRACT_WIN_Z_SCORE = 2.50;
    /** Top-line revenue multiplier boost following a franchise mega-procurement contract award. */
    public const MEGA_CONTRACT_WIN_MULT = 1.18;

    // --- Program Execution & Classified Tooling Physics ---
    /** Operating margin elasticity per unit of programmatic execution and delivery performance. */
    public const PROGRAM_EXECUTION_ELASTICITY = 0.015;
    /** Quarterly margin decay rate per unit of underinvestment in classified tooling and secure fabrication facilities. */
    public const DEFENSE_TOOLING_DECAY_RATE = 0.018;
    /** Quarterly margin gain scalar per unit of logarithmic overinvestment in next-generation platform modernization. */
    public const CLASSIFIED_PLATFORM_GAIN_RATE = 0.009;
    /** Structural minimum operating margin floor under severe tooling tech debt. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.06;
    /** Structural maximum operating margin ceiling for modernized classified production platforms. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.22;

    // --- Working Capital & FAR Progress Payment Withholding ---
    /** Standard defense sector net working capital intensity under regular milestone billing. */
    public const BASE_NWC_INTENSITY = 0.10;
    /** Elevated net working capital intensity under FAR progress payment withholding and unbilled WIP accumulation. */
    public const WITHHOLDING_NWC_INTENSITY = 0.18;

    // --- ROIC Annualization & Multi-Year Smoothing ---
    /** Annualization multiplier converting quarterly NOPAT into annual returns. */
    public const ROIC_ANNUALIZATION_MULT = 4.00;
    /** Lower clamp for calculated ROIC. */
    public const MIN_ROIC_CLAMP = -0.50;
    /** Upper clamp for calculated ROIC. */
    public const MAX_ROIC_CLAMP = 1.00;
    /** Weight given to current quarter post-tax return when updating programmatic TTM ROIC EMA. */
    public const ROIC_TTM_EMA_WEIGHT = 0.20;
    /** Weight given to historical TTM ROIC when updating programmatic TTM ROIC EMA. */
    public const ROIC_TTM_HIST_WEIGHT = 0.80;

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
            'reversion_speed'          => 0.12,
            'moat_spread'              => 0.020,
            'nwc_intensity'            => self::BASE_NWC_INTENSITY,
            'capex_completion_rate'    => 0.20,
        ];
    }

    public function getCapexCyclicality(): float
    {
        return 0.30;
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.70, 'revenue_weight' => 0.30];
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.02;
    }

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $fixedPriceZ = (float) ($momentum['fixed_price_development'] ?? 0.0);
        $eventZ = (float) ($momentum['event'] ?? 0.0);

        // When a contractor faces development cost overruns or flagship platform groundings,
        // the sovereign customer invokes FAR 32.503-6 progress payment withholding, tying up capital in unbilled receivables.
        if ($fixedPriceZ < self::FORWARD_LOSS_Z_SCORE || $eventZ < self::FLAGSHIP_FAILURE_Z_SCORE) {
            return self::WITHHOLDING_NWC_INTENSITY;
        }

        return self::BASE_NWC_INTENSITY;
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Defense procurement is driven by sovereign security appropriations, immune to consumer output gap recessions.
        $physics['macro_demand_shift'] = 0.0;

        // Cost-plus escalators and fixed-price loss models handle inflation dynamics directly in calculateSectorPhysics.
        $physics['pricing_power_multiplier'] = 1.0;

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
            ModelParam::CostPlusWeight->value             => self::COST_PLUS_WEIGHT,
            ModelParam::FixedPriceDevWeight->value        => self::FIXED_PRICE_DEV_WEIGHT,
            ModelParam::ForeignMilitarySalesWeight->value => self::FOREIGN_MILITARY_SALES_WEIGHT,
        ]);

        $costPlusWeight   = $params[ModelParam::CostPlusWeight];
        $fixedPriceWeight = $params[ModelParam::FixedPriceDevWeight];
        $fmsWeight        = $params[ModelParam::ForeignMilitarySalesWeight];

        // Normalize weights if needed
        $totalWeight = $costPlusWeight + $fixedPriceWeight + $fmsWeight;
        if ($totalWeight > 0.0 && abs($totalWeight - 1.0) > 0.0001) {
            $costPlusWeight   /= $totalWeight;
            $fixedPriceWeight /= $totalWeight;
            $fmsWeight        /= $totalWeight;
        }

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new StreamContext($momentum, $mathUtility);

        // Independent stream Z-scores
        $costPlusZ   = $streams->generateZ('cost_plus_procurement', 0.70); // High persistence (multi-year appropriations)
        $fixedPriceZ = $streams->generateZ('fixed_price_development', 0.30); // Moderate persistence (EMD development)
        $fmsZ        = $streams->generateZ('foreign_military_sales', 0.20);  // Volatile geopolitical exports
        $eventZ      = $streams->generateZ('event', 0.10);

        // --- Sovereign Procurement & Cost-Plus Fiscal Physics ---
        $inflation = $macroState->inflationEma;
        $costPlusBonus = $inflation > MacroEngine::TARGET_INFLATION
            ? ($inflation - MacroEngine::TARGET_INFLATION) * self::COST_PLUS_BONUS_SCALAR
            : 0.0;

        // Sovereign Fiscal Stress / Continuing Resolution (CR) Drag
        $creditSpread = $macroState->macroCreditSpreadEma;
        $crDrag = $creditSpread > self::SOVEREIGN_STRESS_THRESHOLD
            ? ($creditSpread - self::SOVEREIGN_STRESS_THRESHOLD) * self::CR_BUDGET_DRAG_SCALAR
            : 0.0;

        // Fixed-Price Material / Energy Shock Squeeze
        $energyShock = $macroState->energyPriceShock;
        $materialDrag = $energyShock > 0.0
            ? $energyShock * self::FIXED_PRICE_MATERIAL_DRAG_SCALAR
            : 0.0;

        // --- Program Execution, Forward Losses & Tail Shocks ---
        $costPlusMultiplier   = max(0.50, 1.0 - $crDrag);
        $fixedPriceMultiplier = 1.0;
        $fmsMultiplier        = 1.0;
        $eventType            = null;
        $forwardLossPenalty   = 0.0;
        $flagshipPenalty      = 0.0;

        // Program Execution Efficiency (Better execution reduces cost of delivery on domestic lots)
        $executionEfficiencyShift = -self::PROGRAM_EXECUTION_ELASTICITY * $costPlusZ * $costPlusWeight;

        // Fixed-Price Development Reach-Forward Loss (ASC 606)
        if ($fixedPriceZ < self::FORWARD_LOSS_Z_SCORE) {
            $forwardLossPenalty = self::FORWARD_LOSS_PENALTY;
            $eventType = ShockEvent::PROJECT_DELAY;
        }

        // Flagship Platform Failure / Grounding vs Mega Contract Win vs Congressional Export Ban
        if ($eventZ < self::FLAGSHIP_FAILURE_Z_SCORE) {
            $costPlusMultiplier = self::FLAGSHIP_FAILURE_MULT;
            $flagshipPenalty = self::FLAGSHIP_FAILURE_PENALTY;
            $eventType = ShockEvent::DEFENSE_CONTRACT_LOSS;
        } elseif ($eventZ > self::MEGA_CONTRACT_WIN_Z_SCORE) {
            $costPlusMultiplier = self::MEGA_CONTRACT_WIN_MULT;
            $eventType = ShockEvent::DEFENSE_CONTRACT_WIN;
        } elseif ($eventZ < self::CONGRESSIONAL_EXPORT_BAN_Z) {
            $fmsMultiplier = self::EXPORT_BAN_MULT;
            $eventType = ShockEvent::GEOPOLITICAL_EXPORT_BAN;
        }

        // Active Geopolitical Conflict Surge (overrides standard event)
        if ($fmsZ > self::GEOPOLITICAL_CONFLICT_Z) {
            $fmsMultiplier = self::FMS_CONFLICT_BOOST;
            $executionEfficiencyShift += self::WARTIME_SUPPLY_CHAIN_DRAG;
            $eventType = ShockEvent::GEOPOLITICAL_CONFLICT;
        }

        // --- Clamped Revenue Streams ---
        $costPlusRevenue = max(
            0.0,
            $expectedRevenue * $costPlusWeight * (1.0 + ($costPlusZ * $baselineVol * self::COST_PLUS_VARIANCE_SCALAR) + $costPlusBonus) * $costPlusMultiplier
        );
        $fixedPriceRevenue = max(
            0.0,
            $expectedRevenue * $fixedPriceWeight * (1.0 + ($fixedPriceZ * $baselineVol * self::FIXED_PRICE_DEV_VARIANCE_SCALAR)) * $fixedPriceMultiplier
        );
        $fmsRevenue = max(
            0.0,
            $expectedRevenue * $fmsWeight * (1.0 + ($fmsZ * $baselineVol * self::FMS_VARIANCE_SCALAR)) * $fmsMultiplier
        );

        $actualRevenue = $costPlusRevenue + $fixedPriceRevenue + $fmsRevenue;

        // --- Realized Variable Cost Margin ---
        $rawMargin = $realizedVariableMargin + $executionEfficiencyShift + $forwardLossPenalty + $flagshipPenalty + $materialDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        // --- Shock Determination ---
        $primaryShockZ = $costPlusZ;
        if (abs($fixedPriceZ) > abs($primaryShockZ)) {
            $primaryShockZ = $fixedPriceZ;
        }
        if (abs($fmsZ) > abs($primaryShockZ)) {
            $primaryShockZ = $fmsZ;
        }
        if (abs($eventZ) > abs($primaryShockZ)) {
            $primaryShockZ = $eventZ;
        }

        $costPlusShock   = (($costPlusZ * $baselineVol * self::COST_PLUS_VARIANCE_SCALAR) + $costPlusBonus) * $costPlusMultiplier + ($costPlusMultiplier - 1.0);
        $fixedPriceShock = ($fixedPriceZ * $baselineVol * self::FIXED_PRICE_DEV_VARIANCE_SCALAR) * $fixedPriceMultiplier + ($fixedPriceMultiplier - 1.0);
        $fmsShock        = ($fmsZ * $baselineVol * self::FMS_VARIANCE_SCALAR) * $fmsMultiplier + ($fmsMultiplier - 1.0);

        $observableShockZ = ($costPlusShock * $costPlusWeight) + ($fixedPriceShock * $fixedPriceWeight) + ($fmsShock * $fmsWeight);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: [
                'cost_plus_procurement'   => $costPlusRevenue,
                'fixed_price_development' => $fixedPriceRevenue,
                'foreign_military_sales'  => $fmsRevenue,
            ],
        );
    }

    public function updateDynamicRoic(
        Stock $stock,
        float $actualTotalNetIncome,
        float $investedCapital,
        float $ebit,
        float $corporateTaxRate,
        float $wacc = 0.08,
        float $costOfEquity = 0.10,
        ?MacroStateDTO $macroState = null
    ): float {
        $thresholds = $this->getModelThresholds();
        $kappa = $thresholds['reversion_speed'] ?? 0.12;
        $moatSpread = $thresholds['moat_spread'] ?? 0.020;

        $nopatProxy = $ebit > 0 ? $ebit * (1.0 - $corporateTaxRate) : $ebit;
        $effectiveCapital = max(1.0, abs($investedCapital));
        $truePostTaxReturn = ($nopatProxy / $effectiveCapital) * self::ROIC_ANNUALIZATION_MULT;

        $stock->setCurrentRoic((string) max(self::MIN_ROIC_CLAMP, min(self::MAX_ROIC_CLAMP, $truePostTaxReturn)));

        // Multi-year contract smoothing prevents artificial P/E spikes from single-quarter milestone recognition.
        $oldTtm = (float) $stock->getRoicTtm();
        $newTtm = $oldTtm === 0.0
            ? $truePostTaxReturn
            : ($truePostTaxReturn * self::ROIC_TTM_EMA_WEIGHT) + ($oldTtm * self::ROIC_TTM_HIST_WEIGHT);

        $scaledKappa = $kappa / self::TTM_ROIC_WEIGHT;
        $math = new MathUtility();

        $saturationPenalty = 0.0;
        if ($macroState !== null) {
            $metrics = new CorporateMetrics();
            $saturationPenalty = $metrics->calculateMarketSaturationPenalty($stock, abs($investedCapital), $macroState);
        }

        $newTtm += $math->calculateReversionPull($newTtm, $wacc - $saturationPenalty, $scaledKappa, $moatSpread);
        $stock->setRoicTtm((string) max(self::MIN_ROIC_CLAMP, min(self::MAX_ROIC_CLAMP, $newTtm)));

        return $truePostTaxReturn;
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            // Classified tooling tech debt decay toward operating margin floor
            $decayRate = self::DEFENSE_TOOLING_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // Next-generation defense platform modernization expands margin ceiling
            $modGain = self::CLASSIFIED_PLATFORM_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}

