<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Shadow Banks (Mortgage Finance, Non-bank lenders).
 * 
 * Financial Physics:
 * - Operates like a bank but without customer deposits.
 * - Funds its entire loan book via Wholesale Debt (Repo Markets, Commercial Paper).
 * - Highly vulnerable to credit market freezes and yield curve inversions.
 * - Evaluated on Return on Equity (ROE).
 */
class ShadowBankBusinessModel extends CommercialBankBusinessModel
{
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $treasury = (float) $stock->getCorporateTreasury();
        
        $earningAssets = max($equity, $equity + $wholesaleDebt - $treasury);
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        $structuralSpread = (float) $stock->getCreditSpread();
        
        $targetNetIncome = $equity * $baselineRoe;
        $taxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;
        $targetEbt = $targetNetIncome / (1.0 - $taxRate);
        
        $expectedInterestExpense = $wholesaleDebt * ($policyRate + $structuralSpread);
        $expectedInterestIncome = $treasury * max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
        
        $targetEbit = $targetEbt + $expectedInterestExpense - $expectedInterestIncome;
        
        // Shadow Banks rely on loan volume. We floor target EBIT to guarantee baseline lending operations.
        $coreLiabilities = $wholesaleDebt;
        $minLendingEbit = $coreLiabilities * 0.015;
        
        $targetEbit = max($minLendingEbit, $targetEbit);
        
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());
        
        $targetRevenue = max(0.0, $targetEbit) / $stableMargin;
        $grossYield = $targetRevenue / max(1.0, abs($earningAssets));
        
        return [
            'invested_capital' => $earningAssets,
            'baseline_roic' => $grossYield * $stableMargin
        ];
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.20))); // Slightly higher baseline vol than deposit-backed banks
        
        $creditZ = $mathUtility->generateStandardNormal();
        
        // Mortgage Default Shock:
        // Shadow Banks primarily hold highly leveraged mortgages and auto loans. Housing defaults spike during deep recessions.
        $outputGap = $macroState['output_gap_ema'] ?? ($macroState['output_gap'] ?? 0.0);
        $macroDefaultDrag = $outputGap < 0.0 ? abs($outputGap) * 1.2 : 0.0;
        
        $lossProvisionShock = ($creditZ < -1.5 ? abs($creditZ) * 0.12 : ($creditZ > 1.0 ? -0.02 : 0.0)) + $macroDefaultDrag;
        
        // Shadow Bank NIM Squeeze (high VULNERABILITY):
        // Shadow banks have ZERO cheap deposits. They fund their long-term loans entirely by borrowing 
        // short-term cash in the Repo Market. Yield curve inversions are real painful. (2.0x multiplier)
        $yieldCurveSlope = $macroState['ns_slope_ema'] ?? ($macroState['ns_slope'] ?? 0.015);
        $nimSqueeze = (0.010 - $yieldCurveSlope) * 2.0;
        
        $actualVariableCosts = $actualRevenue * min(0.99, max(0.01, $realizedVariableMargin + $lossProvisionShock + $nimSqueeze));
        
        $eventLore = null;
        if ($creditZ < -2.0) {
            $eventLore = "Took massive write-downs on toxic mortgage-backed securities and loan defaults.";
        } elseif ($creditZ < -1.5) {
            $eventLore = "Elevated mortgage defaults negatively impacted quarterly margins.";
        }

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts, 
            'primary_shock_z' => abs($creditZ) > abs($revenueZ) ? $creditZ : $revenueZ,
            'event_lore' => $eventLore
        ];
    }
}