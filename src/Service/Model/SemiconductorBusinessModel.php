<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Semiconductor Foundries and Photolithography Equipment Manufacturers.
 * 
 * Financial Physics:
 * - Extreme Capital Intensity ("Shovel Makers"): Requires astronomical CapEx to build and upgrade cleanrooms and lithography tools.
 * - Non-Linear Fab Utilization Leverage: With massive fixed overhead and depreciation, profitability scales non-linearly with capacity utilization.
 * - During technological super-cycles, running fabs at 100% capacity generates staggering ROIC (35%+).
 * - During industry inventory corrections, underutilized fabs create brutal earnings drag.
 * - Severe Technological Obsolescence: If R&D/CapEx drops below critical hurdle rates, fab yields collapse.
 */
class SemiconductorBusinessModel extends StandardCorporateBusinessModel
{
    // --- Dual-Stream Semiconductor Architecture ---
    /** Baseline fraction of revenue derived from physical cleanroom fab manufacturing and wafer sales. */
    public const FOUNDRY_REVENUE_WEIGHT = 0.85;
    /** Baseline fraction of revenue derived from fabless chip IP design and accelerator licensing. */
    public const DESIGN_REVENUE_WEIGHT  = 0.15;

    // --- Cyclical Demand & Macro Physics ---
    /** Macroeconomic demand shift sensitivity to global tech CapEx cycles. */
    public const MACRO_DEMAND_SCALAR       = 1.50;
    /** Minimum beta floor applied when calculating inflation pricing power. */
    public const MIN_PRICING_BETA_FLOOR    = 0.40;
    /** Multiplier scaling stock beta to determine pricing power responsiveness to inflation. */
    public const PRICING_BETA_SCALAR       = 0.70;

    // --- Fab Utilization Leverage & Cycle Thresholds ---
    /** Volatility multiplier for top-line revenue shocks reflecting chip inventory cycles. */
    public const REVENUE_VARIANCE_SCALAR   = 0.22;
    /** Positive output gap threshold triggering tech super-cycle fab utilization booms. */
    public const BOOM_GAP_THRESHOLD        = 0.01;
    /** Positive z-score threshold required to confirm hardware shortage lore. */
    public const BOOM_Z_SCORE_THRESHOLD    = 1.50;
    /** Output gap multiplier scaling top-line revenue during 100% fab capacity utilization booms. */
    public const BOOM_UTILIZATION_MULT     = 3.50;
    /** Negative output gap threshold triggering inventory correction fab underutilization. */
    public const GLUT_GAP_THRESHOLD        = -0.01;
    /** Negative z-score threshold required to confirm underutilized cleanroom lore. */
    public const GLUT_Z_SCORE_THRESHOLD    = -1.50;
    /** Output gap multiplier scaling revenue drag during industry inventory bullwhip corrections. */
    public const GLUT_UTILIZATION_MULT     = 2.50;

    // --- CapEx Hurdle & Wafer Yield Rails ---
    /** Minimum CapEx-to-depreciation ratio required to maintain cleanroom purity and lithography calibration. */
    public const CAPEX_HURDLE_RATIO        = 0.40;
    /** Variable margin penalty applied when underinvestment degrades silicon wafer yields. */
    public const WAFER_SCRAP_PENALTY       = 0.08;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Analyst Visibility & Error ---
    // Moved to getCoverageProfile() — see MarketConsensusEngine.

    // --- Capital Intensity Moat & Capital Structure ---
    /** Operating margin mean reversion speed: slower speed reflects massive capital barriers to entry. */
    public const FAB_REVERSION_SPEED       = 2.5;
    /** Minimum WACC arbitrage spread required before under-leveraged recapitalization is permitted. */
    public const WACC_ARBITRAGE_THRESHOLD  = 0.03;
    /** Minimum interest coverage ratio required to permit recapitalization for capital intensive foundries. */
    public const MIN_RECAP_ICR_FLOOR       = 15.0;
    /** Maximum debt tolerance threshold fraction triggering under-leveraged status. */
    public const UNDERLEVERAGED_DEBT_RATIO = 0.50;

    // --- Capital Reinvestment & Asset Depreciation Physics ---
    /** Quarterly efficiency decay rate per unit of underinvestment below replacement CapEx. */
    public const DEPRECIATION_DECAY_RATE      = 0.030;
    /** Quarterly efficiency gain scalar per unit of logarithmic overinvestment above replacement CapEx. */
    public const MODERNIZATION_GAIN_RATE      = 0.015;
    /** Structural minimum operating margin floor under extreme fab obsolescence. */
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.02;
    /** Structural maximum operating margin ceiling for state-of-the-art modernized fabs. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.38;

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $outputGap = $macroState->outputGapEma;
        $inflation = $macroState->inflationEma;
        $beta = (float) $stock->getBeta();

        // Semiconductors are highly cyclical and levered to global tech capital expenditure cycles
        return [
            'macro_demand_shift' => $outputGap * $beta * self::MACRO_DEMAND_SCALAR,
            'pricing_power_multiplier' => 1.0 + ($inflation * max(self::MIN_PRICING_BETA_FLOOR, $beta * self::PRICING_BETA_SCALAR)),
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            'foundry_revenue_weight' => self::FOUNDRY_REVENUE_WEIGHT,
            'design_revenue_weight'  => self::DESIGN_REVENUE_WEIGHT,
        ]);

        $foundryWeight = $params['foundry_revenue_weight'];
        $designWeight  = $params['design_revenue_weight'];

        // Independent stream Z-scores
        $foundryZ = $mathUtility->generateStandardNormal(); // Cleanroom wafer manufacturing volume
        $designZ  = $mathUtility->generateStandardNormal(); // IP architecture licensing & AI design mandates

        // Fab Utilization Leverage & Tech Super-Cycles
        // Crucially, capacity utilization leverage applies to physical fab manufacturing ($foundryWeight),
        // while fabless IP licensing scales independently with tech demand.
        $outputGap = $macroState->outputGapEma;
        $utilizationMultiplier = 0.0;
        $eventType = null;

        $cycleZ = $mathUtility->generateStandardNormal();
        if ($outputGap > self::BOOM_GAP_THRESHOLD && $cycleZ > self::BOOM_Z_SCORE_THRESHOLD) {
            $utilizationMultiplier = $outputGap * self::BOOM_UTILIZATION_MULT;
            $eventType = ShockEvent::SEMICONDUCTOR_FAB_SHORTAGE;
        } elseif ($outputGap < self::GLUT_GAP_THRESHOLD && $cycleZ < self::GLUT_Z_SCORE_THRESHOLD) {
            $utilizationMultiplier = $outputGap * self::GLUT_UTILIZATION_MULT;
            $eventType = ShockEvent::SEMICONDUCTOR_INVENTORY_CORRECTION;
        }

        $foundryRevenue = $expectedRevenue * $foundryWeight * (1.0 + ($foundryZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $utilizationMultiplier);
        $designRevenue  = $expectedRevenue * $designWeight * (1.0 + ($designZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));
        $actualRevenue  = max(0.0, $foundryRevenue + $designRevenue);

        // CapEx Hurdle Rate & Yield Penalties
        // In semiconductor manufacturing, massive ongoing CapEx is mandatory table stakes to maintain cleanroom purity.
        // Underinvestment scrap penalties compress margins proportionally on physical foundry operations.
        $capexRatio = (float) $stock->getCapexRatio();
        $yieldModifier = 0.0;
        if ($capexRatio < self::CAPEX_HURDLE_RATIO) {
            $yieldModifier = self::WAFER_SCRAP_PENALTY * $foundryWeight; // Scaled by foundry weight
        }

        $clampedMargin = min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + $yieldModifier));

        $primaryShockZ = abs($cycleZ) > abs($foundryZ) ? $cycleZ : $foundryZ;
        // observableShockZ: foundry demand visible via wafer shipment lead times and supply chain checks
        $observableShockZ = $foundryZ * $foundryWeight + $utilizationMultiplier * $foundryWeight;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
        );
    }

    public function getCoverageProfile(): \App\DTO\SectorCoverageProfile
    {
        // Wafer shipment lead times and supply chain checks give ~60% visibility (40% floor).
        return new \App\DTO\SectorCoverageProfile(baseVisibility: 0.60, errorStdDev: 0.05, minVisibility: 0.40);
    }

    public function getMarginReversionSpeed(): float
    {
        // Massive capital barriers to entry (ASML EUV machines cost $300M+ each) protect excess margins for years
        return self::FAB_REVERSION_SPEED;
    }

    public function isUnderLeveraged(bool $isFinancial, float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool
    {
        // Semiconductor foundries face severe capital cycles and high technological obsolescence risks.
        // Their optimal capital structure is lean on debt. They should only recapitalize under extreme
        // WACC arbitrage (Ke > Kd + 3.0%) and extraordinary cash flow safety (ICR > 15.0).
        if ($costOfEquity <= ($effectiveCostOfDebt + self::WACC_ARBITRAGE_THRESHOLD)) {
            return false;
        }
        if ($interestCoverage < self::MIN_RECAP_ICR_FLOOR) {
            return false;
        }
        return $currentDebtRatio < ($targetDebtTolerance * self::UNDERLEVERAGED_DEBT_RATIO);
    }

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        return 0.18; // High wafer fabrication lead time & finished goods inventory holding
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

