<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
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
    /** Base analyst visibility into semiconductor lead times and wafer shipments. */
    public const ANALYST_BASE_VISIBILITY   = 0.60;
    /** Minimum allowable analyst visibility floor for global supply chain tracking. */
    public const MIN_ANALYST_VISIBILITY    = 0.40;
    /** Standard deviation of analyst estimation error for quarterly foundry sales. */
    public const ANALYST_ERROR_STD_DEV     = 0.05;

    // --- Capital Intensity Moat & Capital Structure ---
    /** Operating margin mean reversion speed: slower speed reflects massive capital barriers to entry. */
    public const FAB_REVERSION_SPEED       = 2.5;
    /** Minimum WACC arbitrage spread required before under-leveraged recapitalization is permitted. */
    public const WACC_ARBITRAGE_THRESHOLD  = 0.03;
    /** Minimum interest coverage ratio required to permit recapitalization for capital intensive foundries. */
    public const MIN_RECAP_ICR_FLOOR       = 15.0;
    /** Maximum debt tolerance threshold fraction triggering under-leveraged status. */
    public const UNDERLEVERAGED_DEBT_RATIO = 0.50;

    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        $beta = (float) $stock->getBeta();

        // Semiconductors are highly cyclical and levered to global tech capital expenditure cycles
        return [
            'macro_demand_shift' => $outputGap * $beta * self::MACRO_DEMAND_SCALAR,
            'pricing_power_multiplier' => 1.0 + ($inflation * max(self::MIN_PRICING_BETA_FLOOR, $beta * self::PRICING_BETA_SCALAR)),
        ];
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Chip cycles exhibit high volatility (shortages vs. inventory glut bullwhip effect)
        $revenueShock = $revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);
        
        // Fab Utilization Leverage & Tech Super-Cycles
        $outputGap = $macroState['output_gap_ema'] ?? ($macroState['output_gap'] ?? 0.0);
        $utilizationMultiplier = 0.0;
        $eventLore = null;

        $cycleZ = $mathUtility->generateStandardNormal();
        if ($outputGap > self::BOOM_GAP_THRESHOLD && $cycleZ > self::BOOM_Z_SCORE_THRESHOLD) {
            // AI / Tech hardware super-cycle causes severe chip shortages and 100% fab utilization
            $utilizationMultiplier = $outputGap * self::BOOM_UTILIZATION_MULT;
            $actualRevenue = $expectedRevenue * (1.0 + $revenueShock + $utilizationMultiplier);
            $eventLore = "Achieved 100% fab capacity utilization amid a global technological hardware shortage.";
        } elseif ($outputGap < self::GLUT_GAP_THRESHOLD && $cycleZ < self::GLUT_Z_SCORE_THRESHOLD) {
            // Inventory bullwhip correction causes fab underutilization
            $utilizationMultiplier = $outputGap * self::GLUT_UTILIZATION_MULT;
            $actualRevenue = $expectedRevenue * (1.0 + $revenueShock + $utilizationMultiplier);
            $eventLore = "Suffered severe margin drag from underutilized cleanrooms during an industry inventory correction.";
        } else {
            $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);
        }

        // CapEx Hurdle Rate & Yield Penalties
        // In semiconductor manufacturing, massive ongoing CapEx is mandatory table stakes to maintain cleanroom purity and lithography tool calibration.
        $capexRatio = (float) $stock->getCapexRatio();
        $yieldModifier = 0.0;
        if ($capexRatio < self::CAPEX_HURDLE_RATIO) {
            // Underinvesting in lithography and cleanroom upgrades degrades silicon wafer yields
            $yieldModifier = self::WAFER_SCRAP_PENALTY; // 8% penalty on variable costs due to defective wafer scrap
        }

        $actualVariableCosts = $actualRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + $yieldModifier));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        // Analyst Visibility
        // Semiconductor lead times and wafer shipments are closely monitored by supply chain analysts (~60% visibility).
        $analystError = $mathUtility->generateStandardNormal() * self::ANALYST_ERROR_STD_DEV;
        $dynamicVisibility = min(1.0, max(self::MIN_ANALYST_VISIBILITY, self::ANALYST_BASE_VISIBILITY + $analystError));
        $analystExpectedRevenue = $expectedRevenue * (1.0 + (($revenueShock + $utilizationMultiplier) * $dynamicVisibility));
        $analystExpectedVariableCosts = $analystExpectedRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + ($yieldModifier * $dynamicVisibility)));

        return [
            'actual_revenue' => $actualRevenue,
            'actual_variable_costs' => $actualVariableCosts,
            'analyst_expected_revenue' => $analystExpectedRevenue,
            'analyst_expected_variable_costs' => $analystExpectedVariableCosts,
            'ebit' => $ebit,
            'primary_shock_z' => abs($cycleZ) > abs($revenueZ) ? $cycleZ : $revenueZ,
            'event_lore' => $eventLore
        ];
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
}

