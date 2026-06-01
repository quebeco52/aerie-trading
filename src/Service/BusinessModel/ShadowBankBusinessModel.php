<?php

namespace App\Service\BusinessModel;

use App\Entity\Stock;
use App\Service\MathUtility;
use App\Service\MacroEngine;

/**
 * Earnings strategy for Shadow Banks (Mortgage Finance, Non-bank lenders).
 * 
 * Financial Physics:
 * - Operates like a bank but without customer deposits.
 * - Funds its entire loan book via Wholesale Debt (Repo Markets, Commercial Paper).
 * - Highly vulnerable to credit market freezes and yield curve inversions.
 * - Evaluated on Return on Equity (ROE).
 */
class ShadowBankBusinessModel implements BusinessModelInterface
{
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $totalDebt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();
        
        $earningAssets = max($equity, $equity + $totalDebt - $treasury);
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        
        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 8.0;
        $targetRoa = $baselineRoe / max(1.0, $equityLimit);
        
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        $structuralSpread = (float) $stock->getCreditSpread();
        
        $grossYield = $policyRate + $structuralSpread + ($targetRoa * 3.0);
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());
        
        return [
            'invested_capital' => $earningAssets,
            'baseline_roic' => $grossYield * $stableMargin
        ];
    }

    public function calculatePricingPowerModifier(Stock $stock, array $macroState): float
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $macroDrag = ($outputGap * (float) $stock->getBeta() * 0.25);
        
        return $macroDrag - $this->getAnalystMarginShift($stock, $macroState);
    }

    public function getAnalystMarginShift(Stock $stock, array $macroState): float
    {
        $equity = (float) $stock->getTotalEquity();
        $totalDebt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();
        $earningAssets = max($equity, $equity + $totalDebt - $treasury);
        
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 8.0;
        $targetRoa = $baselineRoe / max(1.0, $equityLimit);
        
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        $structuralSpread = (float) $stock->getCreditSpread();
        
        $grossYield = $policyRate + $structuralSpread + ($targetRoa * 3.0);
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());
        
        $baselineRevenue = $earningAssets * $grossYield;
        $expectedRevenue = $baselineRevenue * (1.0 + (($macroState['output_gap_ema'] ?? 0.0) * (float)$stock->getBeta() * 0.25));
        $structuralEbit = $expectedRevenue * $stableMargin; 
        
        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $structuralInterestExpense = $wholesaleDebt * ($policyRate + $structuralSpread);
        
        $yieldCurveSlope = $macroState['ns_slope_ema'] ?? ($macroState['ns_slope'] ?? 0.0);
        $nimModifier = max(0.1, 1.0 + ($yieldCurveSlope * 12.0));
        
        $targetEbt = ($earningAssets * $targetRoa * $nimModifier) / (1.0 - ($macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE));
        $expectedInterestIncome = $treasury * max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
        
        $requiredEbit = max(0.01 * $equity, $targetEbt + $structuralInterestExpense - $expectedInterestIncome);
        
        if ($expectedRevenue > 0) {
            return ($structuralEbit / $expectedRevenue) - ($requiredEbit / $expectedRevenue);
        }
        return 0.0;
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.20))); // Slightly higher baseline vol than deposit-backed banks
        
        $equity = (float) $stock->getTotalEquity();
        $totalDebt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();
        $earningAssets = max($equity, $equity + $totalDebt - $treasury);
        
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 8.0;
        $targetRoa = $baselineRoe / max(1.0, $equityLimit);
        
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        
        $structuralSpread = (float) $stock->getCreditSpread();
        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        
        $structuralInterestExpense = $wholesaleDebt * ($policyRate + $structuralSpread);
        
        $yieldCurveSlope = $macroState['ns_slope_ema'] ?? ($macroState['ns_slope'] ?? 0.0);
        
        $nimModifier = max(0.1, 1.0 + ($yieldCurveSlope * 12.0)); 
        
        $targetEbt = ($earningAssets * $targetRoa * $nimModifier) / (1.0 - ($macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE));
        $expectedInterestIncome = $treasury * max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
        
        $requiredEbit = max(0.01 * $equity, $targetEbt + $structuralInterestExpense - $expectedInterestIncome);
        $requiredVariableCosts = $actualRevenue - $fixedCosts - $requiredEbit;
        $requiredVariableMargin = $requiredVariableCosts / max(1.0, $actualRevenue);
        
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $macroDrag = ($outputGap * (float) $stock->getBeta() * 0.25);
        $requiredVariableMargin -= $macroDrag;
        
        // Credit Default & Liquidity Freeze Shock
        $creditZ = $mathUtility->generateStandardNormal();
        $lossProvisionShock = $creditZ < -1.5 ? abs($creditZ) * 0.12 : ($creditZ > 1.0 ? -0.02 : 0.0);
        
        $actualVariableCosts = $actualRevenue * min(1.50, max(0.01, $requiredVariableMargin + $lossProvisionShock));
        
        $eventLore = null;
        if ($creditZ < -2.0) {
            $eventLore = "Suffered a major funding squeeze in wholesale lending markets.";
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

    public function calculateInterestIncome(Stock $stock, array $macroState, MathUtility $mathUtility): float
    {
        $equity = (float) $stock->getTotalEquity();
        $operatingBase = $mathUtility->calculateOperatingBase((float) $stock->getTotalRevenue(), $equity);
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - ($operatingBase * 0.05));
        
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        
        return $excessCash * max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
    }

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