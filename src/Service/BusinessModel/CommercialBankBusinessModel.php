<?php

namespace App\Service\BusinessModel;

use App\Entity\Stock;
use App\Service\MathUtility;
use App\Service\MacroEngine;

/**
 * Earnings strategy for Commercial Banks.
 * 
 * Financial Physics:
 * - Profits are driven by Net Interest Margin (NIM) and the spread between wholesale/deposit rates and lending rates.
 * - Evaluated strictly on Return on Equity (ROE) rather than ROIC.
 * - Customer deposits act as operating leverage (inventory), requiring an APY Beta to prevent capital flight.
 */
class CommercialBankBusinessModel implements BusinessModelInterface
{
    /**
     * Returns a stable structural ROIC proxy to keep top-line loan revenue rock solid.
     * Dynamic NIM (Net Interest Margin) expansion/compression is handled strictly in generateIdiosyncraticShock.
     *
     * @param Stock       $stock       The bank stock entity.
     * @param array       $macroState  The macroeconomic state.
     * @param MathUtility $mathUtility Mathematical utility.
     * @return array{invested_capital: float, baseline_roic: float}
     */
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        
        return [
            'invested_capital' => $equity,
            'baseline_roic' => $baselineRoe * 1.5 // Proxy multiplier for standard bank asset turnover
        ];
    }

    /**
     * Banks don't manufacture physical goods, so inflation doesn't crush their supply chain.
     * Their pricing power is mainly tied to capturing the output gap during booms.
     *
     * @param Stock $stock      The bank stock entity.
     * @param array $macroState The current macroeconomic state.
     * @return float The calculated pricing power modifier.
     */
    public function calculatePricingPowerModifier(Stock $stock, array $macroState): float
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $macroDrag = ($outputGap * (float) $stock->getBeta() * 0.25);
        
        return $macroDrag - $this->getAnalystMarginShift($stock, $macroState);
    }

    /**
     * Helper method to align Wall Street analysts with the CFO's dynamic Net Interest Margin (NIM).
     */
    public function getAnalystMarginShift(Stock $stock, array $macroState): float
    {
        $equity = (float) $stock->getTotalEquity();
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        $structuralSpread = (float) $stock->getCreditSpread();
        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $customerDeposits = (float) $stock->getCustomerDeposits();
        $totalDebt = $wholesaleDebt + $customerDeposits;
        
        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 10.0;
        
        $mathUtility = new MathUtility();
        $depositBeta = $mathUtility->calculateDepositBeta($totalDebt, $equity, $equityLimit, $customerDeposits);
        $depositRate = max(0.001, $policyRate * $depositBeta);
        
        $structuralInterestExpense = ($wholesaleDebt * ($policyRate + $structuralSpread)) + ($customerDeposits * $depositRate);
        
        $yieldCurveSlope = $macroState['ns_slope_ema'] ?? ($macroState['ns_slope'] ?? 0.0);
        $nimModifier = max(0.1, 1.0 + ($yieldCurveSlope * 10.0));
        
        $targetEbt = ($equity * $baselineRoe * $nimModifier) / (1.0 - ($macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE));
        $expectedInterestIncome = (float) $stock->getCorporateTreasury() * max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
        
        $requiredEbit = max(0.01 * $equity, $targetEbt + $structuralInterestExpense - $expectedInterestIncome);
        $structuralEbit = ($baselineRoe * 1.5) * $equity; 
        
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());
        $expectedRevenue = $equity * (($baselineRoe * 1.5) / $stableMargin) * (1.0 + (($macroState['output_gap_ema'] ?? 0.0) * (float)$stock->getBeta() * 0.25));
        
        if ($expectedRevenue > 0) {
            return ($structuralEbit / $expectedRevenue) - ($requiredEbit / $expectedRevenue);
        }
        return 0.0;
    }

    /**
     * Idiosyncratic shock applied directly to loan origination volume and fee revenue.
     * Introduces massive Loss Provision write-offs during economic downturns.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.15)));
        
        // 1. Dynamic NIM Alignment
        $equity = (float) $stock->getTotalEquity();
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        
        $structuralSpread = (float) $stock->getCreditSpread();
        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $customerDeposits = (float) $stock->getCustomerDeposits();
        $totalDebt = $wholesaleDebt + $customerDeposits;
        
        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 10.0;
        $depositBeta = $mathUtility->calculateDepositBeta($totalDebt, $equity, $equityLimit, $customerDeposits);
        $depositRate = max(0.001, $policyRate * $depositBeta);
        
        $structuralInterestExpense = ($wholesaleDebt * ($policyRate + $structuralSpread)) + ($customerDeposits * $depositRate);
        $yieldCurveSlope = $macroState['ns_slope_ema'] ?? ($macroState['ns_slope'] ?? 0.0);
        $nimModifier = max(0.1, 1.0 + ($yieldCurveSlope * 10.0));
        
        $targetEbt = ($equity * $baselineRoe * $nimModifier) / (1.0 - ($macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE));
        $expectedInterestIncome = (float) $stock->getCorporateTreasury() * max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
        
        $requiredEbit = max(0.01 * $equity, $targetEbt + $structuralInterestExpense - $expectedInterestIncome);
        $requiredVariableCosts = $actualRevenue - $fixedCosts - $requiredEbit;
        $requiredVariableMargin = $requiredVariableCosts / max(1.0, $actualRevenue);
        
        $pricingPower = $this->calculatePricingPowerModifier($stock, $macroState);
        $requiredVariableMargin -= $pricingPower;
        
        // 2. The Credit Default Shock (Loss Provisions)
        $defaultZ = $mathUtility->generateStandardNormal();
        $lossProvisionShock = $defaultZ < -1.5 ? abs($defaultZ) * 0.10 : ($defaultZ > 1.0 ? -0.02 : 0.0);
        
        $actualVariableCosts = $actualRevenue * min(1.50, max(0.01, $requiredVariableMargin + $lossProvisionShock));
        
        $eventLore = null;
        if ($defaultZ < -2.0) {
            $eventLore = "Took a massive provision for credit losses due to rising loan defaults.";
        } elseif ($defaultZ < -1.5) {
            $eventLore = "Elevated loan defaults negatively impacted quarterly margins.";
        }

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts, 
            'primary_shock_z' => abs($defaultZ) > abs($revenueZ) ? $defaultZ : $revenueZ,
            'event_lore' => $eventLore
        ];
    }

    /**
     * Banks earn standard money-market yields only on excess liquidity that isn't actively deployed.
     */
    public function calculateInterestIncome(Stock $stock, array $macroState, MathUtility $mathUtility): float
    {
        $equity = (float) $stock->getTotalEquity();
        $operatingBase = $mathUtility->calculateOperatingBase((float) $stock->getTotalRevenue(), $equity);
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - ($operatingBase * 0.05));
        
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        
        return $excessCash * max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
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