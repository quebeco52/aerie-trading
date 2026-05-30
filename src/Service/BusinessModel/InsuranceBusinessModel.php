<?php

namespace App\Service\BusinessModel;

use App\Entity\Stock;
use App\Service\MathUtility;

/**
 * Earnings strategy for Insurance companies.
 * 
 * Financial Physics:
 * - Revenue (Premiums) is highly sticky and predictable.
 * - Variance comes from Catastrophes (Claims/Underwriting losses).
 * - Structural profits come from "The Float" (investing premium cash before it's paid out).
 * - Evaluated on Return on Equity (ROE) rather than ROIC.
 */
class InsuranceBusinessModel implements BusinessModelInterface
{
    /**
     * Insurance companies target a Combined Ratio around 95% to 100% (breakeven underwriting).
     * We set the Target Underwriting Profit (EBIT) to a stable fraction of their Baseline ROE,
     * completely independent of the current interest rate environment.
     */
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        
        // Underwriting provides ~30% of their baseline structural return, the rest is investment yield
        $requiredEbit = $equity * ($baselineRoe * 0.30);
        
        return [
            'invested_capital' => $equity,
            'baseline_roic' => $equity > 0 ? ($requiredEbit / $equity) : 0.01
        ];
    }

    /**
     * Insurance pricing is highly regulated but sticky. 
     * Inflation hurts them slightly (cost of repairs/claims goes up), but they raise premiums to match over time.
     */
    public function calculatePricingPowerModifier(Stock $stock, array $macroState): float
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $beta = (float) $stock->getBeta();
        
        $inflationPenalty = $inflation > 0.04 ? ($inflation - 0.04) * 0.5 : 0.0;
        
        return ($outputGap * $beta * 0.20) - $inflationPenalty;
    }

    /**
     * Models the "Catastrophe Physics". Premium top-line revenue barely moves,
     * but cost margins can explode due to unpredictable massive claim payouts (Hurricanes, Mass Torts).
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        // 1. Premium Revenue Shock (Very low top-line variance)
        $revenueZ = $mathUtility->generateStandardNormal();
        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.05)));
        
        // 2. The Combined Ratio Shock (Catastrophes)
        $claimZ = $mathUtility->generateStandardNormal();
        
        // A -1.5 sigma event spikes claim costs heavily. A +1.0 sigma event represents a quiet, profitable quarter.
        $underwritingShock = $claimZ < -1.5 ? abs($claimZ) * 0.15 : ($claimZ > 1.0 ? -0.05 : 0.0);
        
        $actualVariableCosts = $actualRevenue * min(1.50, ($realizedVariableMargin + $underwritingShock));

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts, 
            'primary_shock_z' => abs($claimZ) > abs($revenueZ) ? $claimZ : $revenueZ
        ];
    }

    /**
     * Insurance companies invest their massive Float in long-duration bonds.
     * They earn a premium yield on virtually ALL their cash, not just the "excess" working capital.
     */
    public function calculateInterestIncome(Stock $stock, array $macroState, MathUtility $mathUtility): float
    {
        $cash = (float) $stock->getCorporateTreasury();
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        $floatYield = max(0.01, $policyRate + 0.01); // Policy rate + 100bps
        
        return $cash * $floatYield;
    }

    /**
     * Financial companies are evaluated strictly on Return on Equity (ROE), not ROIC.
     */
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate): float
    {
        $equity = (float) $stock->getTotalEquity();
        $truePostTaxReturn = $equity > 0 ? ($actualTotalNetIncome / $equity) : 0.0;
        
        $oldRoe = (float) $stock->getCurrentRoe();
        $smoothedRoe = $oldRoe === 0.0 ? $truePostTaxReturn : $oldRoe + (($truePostTaxReturn - $oldRoe) * 0.50);
        
        $stock->setCurrentRoe((string) max(-0.50, min(1.0, $smoothedRoe)));
        
        return $truePostTaxReturn;
    }

    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        return $macroTaxRate;
    }
}