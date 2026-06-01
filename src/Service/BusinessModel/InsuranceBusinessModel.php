<?php

namespace App\Service\BusinessModel;

use App\Entity\Stock;
use App\Service\MathUtility;
use App\Service\MacroEngine;

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
     * Reverse engineers the required underwriting profit (EBIT) needed to hit the target ROE.
     * Insurance companies heavily subsidize their underwriting using massive interest income from "The Float".
     *
     * @param Stock       $stock       The insurance stock entity being evaluated.
     * @param array       $macroState  The current macroeconomic state.
     * @param MathUtility $mathUtility Mathematical utility for engine operations.
     * @return array{invested_capital: float, baseline_roic: float} Target operating metrics.
     */
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        
        // Insurance revenue (Premium Volume) is highly stable and does not wildly fluctuate with interest rates.
        // We return a fixed structural proxy (30% of ROE) to keep top-line Premium Revenue rock solid.
        // The dynamic pricing (margin expansion) is handled strictly in generateIdiosyncraticShock.
        
        return [
            'invested_capital' => $equity,
            'baseline_roic' => $baselineRoe * 0.30
        ];
    }

    /**
     * Insurance pricing is highly regulated but sticky. 
     * Inflation hurts them slightly (cost of repairs/claims goes up), but they raise premiums to match over time.
     *
     * @param Stock $stock      The insurance stock entity.
     * @param array $macroState The current macroeconomic state.
     * @return float The calculated pricing power modifier (applied against variable margins).
     */
    public function calculatePricingPowerModifier(Stock $stock, array $macroState): float
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $beta = (float) $stock->getBeta();
        
        $inflationPenalty = $inflation > 0.04 ? ($inflation - 0.04) * 0.5 : 0.0;
        $macroDrag = ($outputGap * $beta * 0.20) - $inflationPenalty;
        
        // --- Analyst Consensus Alignment (Hard/Soft Market Pricing) ---
        // Analysts know insurers slash premiums when investment yields are high.
        $equity = (float) $stock->getTotalEquity();
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        
        $targetEbt = ($equity * $baselineRoe) / (1.0 - ($macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE));
        
        $expectedInterestIncome = $this->calculateInterestIncome($stock, $macroState, new MathUtility());
        $expectedInterestExpense = (float) $stock->getWholesaleDebt() * (($macroState['policy_rate_ema'] ?? 0.04) + (float) $stock->getCreditSpread());
        
        $requiredEbit = max(0.01 * $equity, $targetEbt + $expectedInterestExpense - $expectedInterestIncome);
        $structuralEbit = ($baselineRoe * 0.30) * $equity;
        
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());
        $expectedRevenue = $equity * (($baselineRoe * 0.30) / $stableMargin) * (1.0 + ($outputGap * $beta));
        
        if ($expectedRevenue > 0) {
            $marginShift = ($structuralEbit / $expectedRevenue) - ($requiredEbit / $expectedRevenue);
            return $macroDrag - $marginShift;
        }
        
        return $macroDrag;
    }

    /**
     * Models the "Catastrophe Physics". Premium top-line revenue barely moves,
     * but cost margins can explode due to unpredictable massive claim payouts (Hurricanes, Mass Torts).
     *
     * @param Stock       $stock                  The insurance stock entity.
     * @param float       $expectedRevenue        The baseline expected revenue.
     * @param float       $realizedVariableMargin The expected variable cost margin.
     * @param float       $fixedCosts             The absolute fixed costs of operations.
     * @param float       $baselineVol            The stock's historical volatility.
     * @param array       $macroState             The current macroeconomic state.
     * @param MathUtility $mathUtility            Mathematical utility for Z-score generation.
     * @return array{actual_revenue: float, actual_variable_costs: float, ebit: float, primary_shock_z: float, event_lore: string|null}
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        // 1. Premium Revenue Shock (Very low top-line variance)
        $revenueZ = $mathUtility->generateStandardNormal();
        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.05)));
        
        // 2. The Combined Ratio Shock (Catastrophes)
        // Note: The Hard/Soft Market cycle is already perfectly baked into $realizedVariableMargin by the pricing power modifier!
        $claimZ = $mathUtility->generateStandardNormal();
        
        $underwritingShock = $claimZ < -1.5 ? abs($claimZ) * 0.15 : ($claimZ > 1.0 ? -0.05 : 0.0);
        
        $actualVariableCosts = $actualRevenue * min(1.50, max(0.01, $realizedVariableMargin + $underwritingShock));
        
        $eventLore = null;
        if ($claimZ < -2.0) {
            $eventLore = "Suffered catastrophic claim losses from a major systemic disaster.";
        } elseif ($claimZ < -1.5) {
            $eventLore = "Elevated claim payouts negatively impacted quarterly underwriting margins.";
        }

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts, 
            'primary_shock_z' => abs($claimZ) > abs($revenueZ) ? $claimZ : $revenueZ,
            'event_lore' => $eventLore
        ];
    }

    /**
     * Insurance companies invest their massive Float in long-duration bonds.
     * They earn a premium yield on virtually ALL their cash, not just the "excess" working capital.
     *
     * @param Stock       $stock       The insurance stock entity.
     * @param array       $macroState  The current macroeconomic state (provides bond yields and ERP).
     * @param MathUtility $mathUtility Mathematical utility.
     * @return float The total absolute interest income generated by the portfolio.
     */
    public function calculateInterestIncome(Stock $stock, array $macroState, MathUtility $mathUtility): float
    {
        $cash = (float) $stock->getCorporateTreasury();
        
        // Insurance companies do not just hold overnight cash; they invest their massive Float 
        // into a diversified portfolio, typically heavily weighted towards long-duration bonds 
        // with a smaller allocation to equities for growth.
        $yield10y = $macroState['yield_10y_ema'] ?? ($macroState['policy_rate_ema'] ?? 0.04) + 0.01;
        $erp = $macroState['equity_risk_premium'] ?? MacroEngine::BASE_EQUITY_RISK_PREMIUM;
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        
        // 80% Fixed Income (Anchored to the 10-Year Treasury Yield)
        $bondReturn = $yield10y;
        
        // 20% Equities (Captures the Equity Risk Premium and fluctuates with the economic cycle)
        $equityReturn = $yield10y + $erp + ($outputGap * 0.5);
        
        // Blended portfolio yield, floored at 0% so they don't mathematically lose the raw principal
        $floatYield = max(0.0, (0.80 * $bondReturn) + (0.20 * $equityReturn));
        
        return $cash * $floatYield;
    }

    /**
     * Financial companies are evaluated strictly on Return on Equity (ROE), not ROIC.
     *
     * @param Stock $stock                The insurance stock entity.
     * @param float $actualTotalNetIncome The total physical net income generated this quarter.
     * @param float $investedCapital      The invested capital (Total Equity for financials).
     * @param float $ebit                 Earnings before interest and taxes.
     * @param float $corporateTaxRate     The effective corporate tax rate.
     * @return float The true post-tax return on equity.
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

    /**
     * Retrieves the effective corporate tax rate for the insurance business.
     *
     * @param float $macroTaxRate The baseline macroeconomic corporate tax rate.
     * @return float The effective tax rate applied to earnings.
     */
    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        return $macroTaxRate;
    }
}