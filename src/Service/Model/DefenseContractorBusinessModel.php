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
 * Earnings strategy for Heavy Defense Contractors & Aerospace Weapons Manufacturers.
 * 
 * Financial Physics:
 * - Tri-Stream Defense Engine:
 *      1. Cost-Plus Sovereign Procurement: Protected by FAR 16.3 inflation escalators.
 *      2. Fixed-Price Development (EMD): ASC 606 reach-forward losses; vulnerable to general inflation.
 *      3. Foreign Military Sales (FMS): High-margin exports where Wright's Law drives margin expansion.
 * - Sovereign Fiscal Physics: Immune to consumer recessions; exposed to Continuing Resolution (CR) budget freezes.
 * - FAR Progress Payment Withholding: Program overruns/defects trigger working capital expansion (FAR 32.503-6).
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
    /** Maximum allowable revenue multiplier drag during a severe Continuing Resolution freeze. */
    public const MAX_CR_BUDGET_DRAG = 0.50;

    // --- Fixed-Price ASC 606 & Production Physics ---
    /** Negative Z-score threshold indicating development program overruns and reach-forward losses. */
    public const FORWARD_LOSS_Z_SCORE = -1.50;
    /** Variable margin penalty for ASC 606 reach-forward losses on fixed-price contracts. */
    public const FORWARD_LOSS_PENALTY = 0.08;
    /** Sensitivity scalar translating excess macroeconomic inflation into fixed-price engineering overruns. */
    public const FIXED_PRICE_INFLATION_DRAG_SCALAR = 0.50;
    /** Margin efficiency elasticity (Wright's Law) applied to mature FMS production volume scale. */
    public const PRODUCTION_LEARNING_CURVE_ELASTICITY = 0.020;

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

    public function getModelThresholds(): array
    {
        return [
            'min_icr'                  => 2.00,
            'bankrupt_equity'          => 0.0,
            'distress_equity'          => 0.0,
            'warning_equity'           => 0.0,
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

        // FAR 32.503-6 Suspension/Reduction of Progress Payments due to programmatic failures
        if ($fixedPriceZ < self::FORWARD_LOSS_Z_SCORE || $eventZ < self::FLAGSHIP_FAILURE_Z_SCORE) {
            return self::WITHHOLDING_NWC_INTENSITY;
        }

        return self::BASE_NWC_INTENSITY;
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        return [
            'macro_demand_shift'       => 0.0,
            'pricing_power_multiplier' => 1.0,
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
            ModelParam::CostPlusWeight->value             => self::COST_PLUS_WEIGHT,
            ModelParam::FixedPriceDevWeight->value        => self::FIXED_PRICE_DEV_WEIGHT,
            ModelParam::ForeignMilitarySalesWeight->value => self::FOREIGN_MILITARY_SALES_WEIGHT,
        ]);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new StreamContext($momentum, $mathUtility);

        // --- Dynamic Revenue Mix Drift ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'cost_plus_procurement'   => $params[ModelParam::CostPlusWeight->value],
            'fixed_price_development' => $params[ModelParam::FixedPriceDevWeight->value],
            'foreign_military_sales'  => $params[ModelParam::ForeignMilitarySalesWeight->value],
        ]);

        $costPlusWeight   = $activeWeights['cost_plus_procurement'];
        $fixedPriceWeight = $activeWeights['fixed_price_development'];
        $fmsWeight        = $activeWeights['foreign_military_sales'];

        $costPlusZ   = $streams->generateZ('cost_plus_procurement', 0.70); // High multi-year appropriation persistence
        $fixedPriceZ = $streams->generateZ('fixed_price_development', 0.30);
        $fmsZ        = $streams->generateZ('foreign_military_sales', 0.20);
        $eventZ      = $streams->generateZ('event', 0.10);

        // --- Sovereign Procurement & Cost-Plus Fiscal Physics ---
        $inflation = $macroState->inflationEma;

        // FAR 16.3 Cost-Plus contracts pass through excess inflation as nominal revenue growth
        $costPlusBonus = $inflation > MacroEngine::TARGET_INFLATION
            ? ($inflation - MacroEngine::TARGET_INFLATION) * self::COST_PLUS_BONUS_SCALAR
            : 0.0;

        // Sovereign credit spreads indicate debt ceiling crises triggering Continuing Resolutions (CRs)
        $creditSpread = $macroState->macroCreditSpreadEma;
        $crDrag = $creditSpread > self::SOVEREIGN_STRESS_THRESHOLD
            ? ($creditSpread - self::SOVEREIGN_STRESS_THRESHOLD) * self::CR_BUDGET_DRAG_SCALAR
            : 0.0;

        // Excess inflation aggressively squeezes margins on fixed-price EMD contracts
        $excessInflation = max(0.0, $inflation - MacroEngine::TARGET_INFLATION);
        $fixedPriceInflationDrag = $excessInflation > 0.0
            ? $excessInflation * self::FIXED_PRICE_INFLATION_DRAG_SCALAR
            : 0.0;

        // --- Program Execution, Forward Losses & Tail Shocks ---
        $costPlusMultiplier   = max(self::MAX_CR_BUDGET_DRAG, 1.0 - $crDrag);
        $fixedPriceMultiplier = 1.0;
        $fmsMultiplier        = 1.0;
        $eventType            = null;
        $forwardLossPenalty   = 0.0;
        $flagshipPenalty      = 0.0;
        $wartimeSupplyDrag    = 0.0;

        // Wright's Law: Learning curve applies to mature FMS export volume
        $learningCurveShift = -self::PRODUCTION_LEARNING_CURVE_ELASTICITY * $fmsZ * $fmsWeight;

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
            $wartimeSupplyDrag = self::WARTIME_SUPPLY_CHAIN_DRAG;
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
        $rawMargin = $realizedVariableMargin
            + $learningCurveShift
            + ($forwardLossPenalty * $fixedPriceWeight)
            + ($fixedPriceInflationDrag * $fixedPriceWeight)
            + ($flagshipPenalty * $costPlusWeight)
            + ($wartimeSupplyDrag * $fmsWeight);

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
