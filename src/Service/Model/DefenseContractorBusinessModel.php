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
    /** Base coverage visibility for defense contractors from public appropriations. */
    public const BASE_COVERAGE_VISIBILITY = 0.60;
    /** Base analyst forecasting error scalar for defense procurement schedules. */
    public const BASE_COVERAGE_ERROR = 0.08;

    // --- Tri-Stream Architecture Weights ---
    /** Baseline fraction of revenue from sovereign cost-plus procurement and sustainment contracts. */
    public const COST_PLUS_WEIGHT = 0.60;
    /** Baseline fraction of revenue from fixed-price engineering and classified development programs. */
    public const FIXED_PRICE_DEV_WEIGHT = 0.20;
    /** Baseline fraction of revenue from high-margin foreign military sales (FMS) weapon exports. */
    public const FOREIGN_MILITARY_SALES_WEIGHT = 0.20;

    // --- Stream Variance & Volatility Scalars ---
    /** Volatility multiplier for highly stable sovereign cost-plus appropriations. */
    public const COST_PLUS_VARIANCE_SCALAR = 0.04;
    /** Volatility multiplier for developmental milestones and prototype testing. */
    public const FIXED_PRICE_DEV_VARIANCE_SCALAR = 0.15;
    /** Volatility multiplier for international defense sales and export authorizations. */
    public const FMS_VARIANCE_SCALAR = 0.35;

    // --- Sovereign Procurement & Cost-Plus Physics ---
    /** Sensitivity multiplier for FAR 16.3 inflation indexation escalation clauses. */
    public const COST_PLUS_BONUS_SCALAR = 1.50;
    /** Sovereign credit spread threshold triggering Continuing Resolution (CR) budget freeze drag. */
    public const SOVEREIGN_STRESS_THRESHOLD = 0.030;
    /** Revenue drag scalar applied to cost-plus lot allocations during debt ceiling freezes. */
    public const CR_BUDGET_DRAG_SCALAR = 2.50;

    // --- Fixed-Price Development Forward-Loss Physics ---
    /** Negative Z-score threshold indicating development program overruns and reach-forward losses. */
    public const FORWARD_LOSS_Z_SCORE = -1.50;
    /** Variable margin penalty for ASC 606 reach-forward losses on fixed-price contracts. */
    public const FORWARD_LOSS_PENALTY = 0.08;
    /** Sensitivity scalar for energy and raw material cost inflation on fixed-price programs. */
    public const FIXED_PRICE_MATERIAL_DRAG_SCALAR = 0.30;

    // --- Geopolitical Sanctions & Conflict Tail Shocks ---
    /** Negative Z-score threshold indicating congressional foreign military export sanctions or bans. */
    public const CONGRESSIONAL_EXPORT_BAN_Z = -2.00;
    /** Revenue multiplier applied to international sales during arms export embargoes. */
    public const EXPORT_BAN_MULT = 0.50;
    /** Positive Z-score threshold indicating active regional conflict and munition restock surges. */
    public const GEOPOLITICAL_CONFLICT_Z = 2.00;
    /** Export revenue multiplier during active geopolitical conflict surges. */
    public const FMS_CONFLICT_BOOST = 1.50;
    /** Margin drag from emergency wartime supply chain expediting and component premiums. */
    public const WARTIME_SUPPLY_CHAIN_DRAG = 0.035;

    // --- Major Programmatic Contract Shocks ---
    /** Negative Z-score threshold indicating cancellation or failure of a flagship weapon program. */
    public const FLAGSHIP_FAILURE_Z_SCORE = -2.50;
    /** Cost-plus revenue multiplier applied during major contract cancellations. */
    public const FLAGSHIP_FAILURE_MULT = 0.80;
    /** Variable margin penalty from fleet groundings, redesign liabilities, and cancellation fees. */
    public const FLAGSHIP_FAILURE_PENALTY = 0.10;
    /** Positive Z-score threshold indicating a multi-decade prime platform franchise win. */
    public const MEGA_CONTRACT_WIN_Z_SCORE = 2.50;
    /** Revenue multiplier applied to cost-plus stream upon securing prime contractor status. */
    public const MEGA_CONTRACT_WIN_MULT = 1.18;

    // --- Program Execution & Classified Tooling Physics ---
    /** Margin efficiency elasticity per unit of contract execution momentum. */
    public const PROGRAM_EXECUTION_ELASTICITY = 0.015;
    /** Margin decay rate per unit of underinvestment in classified tooling and secure facilities. */
    public const DEFENSE_TOOLING_DECAY_RATE = 0.018;
    /** Margin gain rate per unit of logarithmic overinvestment in next-gen platforms. */
    public const CLASSIFIED_PLATFORM_GAIN_RATE = 0.009;
    /** Structural minimum operating margin floor under severe tooling tech debt. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.06;
    /** Structural maximum operating margin ceiling for next-gen platform franchises. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.22;

    // --- Working Capital & FAR Progress Payment Withholding ---
    /** Baseline net working capital intensity under standard FAR progress payment schedules. */
    public const BASE_NWC_INTENSITY = 0.10;
    /** Elevated net working capital intensity during FAR 32.503-6 progress payment withholding. */
    public const WITHHOLDING_NWC_INTENSITY = 0.18;

    // --- ROIC Annualization & Multi-Year Smoothing ---
    /** Multiplier to annualize quarterly NOPAT into annual economic return. */
    public const ROIC_ANNUALIZATION_MULT = 4.00;
    /** Lower bound clamp for dynamic ROIC to prevent numerical divergence. */
    public const MIN_ROIC_CLAMP = -0.50;
    /** Upper bound clamp for dynamic ROIC to prevent perpetual explosion. */
    public const MAX_ROIC_CLAMP = 1.00;
    /** Weight assigned to the current quarter's annualized return in multi-year smoothing. */
    public const ROIC_TTM_EMA_WEIGHT = 0.20;
    /** Weight assigned to historical TTM ROIC in multi-year defense procurement smoothing. */
    public const ROIC_TTM_HIST_WEIGHT = 0.80;

    public function getModelThresholds(): array
    {
        return [
            'min_icr'                  => 2.00,
            'bankrupt_equity'          => 0.0,
            'distress_equity'          => 0.0,
            'warning_equity'           => 0.0,
            // FIX 1: Raised to 2.0. Sovereign contractors can safely carry high debt without entering death spirals.
            'wholesale_leverage_limit' => 2.0,
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

        if ($fixedPriceZ < self::FORWARD_LOSS_Z_SCORE || $eventZ < self::FLAGSHIP_FAILURE_Z_SCORE) {
            return self::WITHHOLDING_NWC_INTENSITY;
        }

        return self::BASE_NWC_INTENSITY;
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        $physics['macro_demand_shift'] = 0.0;
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

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new StreamContext($momentum, $mathUtility);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'cost_plus_procurement'   => $params[ModelParam::CostPlusWeight->value],
            'fixed_price_development' => $params[ModelParam::FixedPriceDevWeight->value],
            'foreign_military_sales'  => $params[ModelParam::ForeignMilitarySalesWeight->value],
        ]);

        $costPlusWeight   = $activeWeights['cost_plus_procurement'];
        $fixedPriceWeight = $activeWeights['fixed_price_development'];
        $fmsWeight        = $activeWeights['foreign_military_sales'];

        // Independent stream Z-scores
        $costPlusZ   = $streams->generateZ('cost_plus_procurement', 0.70);
        $fixedPriceZ = $streams->generateZ('fixed_price_development', 0.30);
        $fmsZ        = $streams->generateZ('foreign_military_sales', 0.20);
        $eventZ      = $streams->generateZ('event', 0.10);

        // --- Sovereign Procurement & Cost-Plus Fiscal Physics ---
        $inflation = $macroState->inflationEma;
        $costPlusBonus = $inflation > MacroEngine::TARGET_INFLATION
            ? ($inflation - MacroEngine::TARGET_INFLATION) * self::COST_PLUS_BONUS_SCALAR
            : 0.0;

        $creditSpread = $macroState->macroCreditSpreadEma;
        $crDrag = $creditSpread > self::SOVEREIGN_STRESS_THRESHOLD
            ? ($creditSpread - self::SOVEREIGN_STRESS_THRESHOLD) * self::CR_BUDGET_DRAG_SCALAR
            : 0.0;

        $energyShift = max(0.0, ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0);
        $materialDrag = $energyShift > 0.0
            ? $energyShift * self::FIXED_PRICE_MATERIAL_DRAG_SCALAR
            : 0.0;

        // --- Program Execution, Forward Losses & Tail Shocks ---
        $costPlusMultiplier   = max(0.50, 1.0 - $crDrag);
        $fixedPriceMultiplier = 1.0;
        $fmsMultiplier        = 1.0;
        $eventType            = null;
        $forwardLossPenalty   = 0.0;
        $flagshipPenalty      = 0.0;

        $executionEfficiencyShift = -self::PROGRAM_EXECUTION_ELASTICITY * $costPlusZ * $costPlusWeight;

        if ($fixedPriceZ < self::FORWARD_LOSS_Z_SCORE) {
            $forwardLossPenalty = self::FORWARD_LOSS_PENALTY;
            $eventType = ShockEvent::PROJECT_DELAY;
        }

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

        if ($fmsZ > self::GEOPOLITICAL_CONFLICT_Z) {
            $fmsMultiplier = self::FMS_CONFLICT_BOOST;
            $executionEfficiencyShift += self::WARTIME_SUPPLY_CHAIN_DRAG;
            $eventType = ShockEvent::GEOPOLITICAL_CONFLICT;
        }

        // --- Clamped Revenue Streams ---
        $costPlusRevenue = max(0.0, $expectedRevenue * $costPlusWeight * (1.0 + ($costPlusZ * $baselineVol * self::COST_PLUS_VARIANCE_SCALAR) + $costPlusBonus) * $costPlusMultiplier);
        $fixedPriceRevenue = max(0.0, $expectedRevenue * $fixedPriceWeight * (1.0 + ($fixedPriceZ * $baselineVol * self::FIXED_PRICE_DEV_VARIANCE_SCALAR)) * $fixedPriceMultiplier);
        $fmsRevenue = max(0.0, $expectedRevenue * $fmsWeight * (1.0 + ($fmsZ * $baselineVol * self::FMS_VARIANCE_SCALAR)) * $fmsMultiplier);

        $streamRevenues = [
            'cost_plus_procurement'   => $costPlusRevenue,
            'fixed_price_development' => $fixedPriceRevenue,
            'foreign_military_sales'  => $fmsRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Realized Variable Cost Margin ---
        // FIX 2: Correctly scale stream-specific margin penalties by their revenue weights!
        // A cost overrun in a 20% division should not destroy 100% of the company's margins.
        $rawMargin = $realizedVariableMargin
            + $executionEfficiencyShift // (Already natively scaled by $costPlusWeight)
            + ($forwardLossPenalty * $fixedPriceWeight)
            + ($flagshipPenalty * $costPlusWeight)
            + ($materialDrag * $fixedPriceWeight);

        $clampedMargin = $this->clampMargin($rawMargin);

        // --- Shock Determination ---
        $primaryShockZ = $costPlusZ;
        if (abs($fixedPriceZ) > abs($primaryShockZ)) $primaryShockZ = $fixedPriceZ;
        if (abs($fmsZ) > abs($primaryShockZ)) $primaryShockZ = $fmsZ;
        if (abs($eventZ) > abs($primaryShockZ)) $primaryShockZ = $eventZ;

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
            streamRevenue: $streamRevenues,
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
            $decayRate = self::DEFENSE_TOOLING_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            $modGain = self::CLASSIFIED_PLATFORM_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}
