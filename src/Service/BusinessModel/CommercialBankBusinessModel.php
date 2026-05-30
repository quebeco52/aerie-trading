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
     * Target ROE is dynamically adjusted by the steepness of the yield curve (NS Slope).
     * Banks must generate enough EBIT to cover both wholesale interest and customer deposit APY
     * before hitting their target net income.
     */
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        
        $structuralSpread = (float) $stock->getCreditSpread();
        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $customerDeposits = (float) $stock->getCustomerDeposits();
        
        $wholesaleRate = $policyRate + $structuralSpread;
        $totalDebt = $wholesaleDebt + $customerDeposits;
        
        // Calculate the APY they must pay to retain customer deposits
        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 10.0;
        $depositBeta = $mathUtility->calculateDepositBeta($totalDebt, $equity, $equityLimit, $customerDeposits);
        $depositRate = max(0.001, $policyRate * $depositBeta);
        
        // Total structural cost of capital
        $structuralInterestExpense = ($wholesaleDebt * $wholesaleRate) + ($customerDeposits * $depositRate);
        
        // Steep yield curves (high slope) make banking wildly profitable (Borrow short, lend long)
        $yieldCurveSlope = $macroState['ns_slope_ema'] ?? ($macroState['ns_slope'] ?? 0.0);
        $nimModifier = max(0.1, 1.0 + ($yieldCurveSlope * 10.0));
        
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe()) * $nimModifier;
        $targetNetIncome = $equity * $baselineRoe;
        $targetEbt = $targetNetIncome / (1.0 - ($macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE));
        
        $treasury = (float) $stock->getCorporateTreasury();
        $expectedInterestIncome = $treasury * max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD); 
        
        // Back out the required operating profit
        $requiredEbit = max(0.01 * $equity, $targetEbt + $structuralInterestExpense - $expectedInterestIncome);

        return [
            'invested_capital' => $equity,
            'baseline_roic' => $equity > 0 ? ($requiredEbit / $equity) : 0.01
        ];
    }

    /**
     * Banks don't manufacture physical goods, so inflation doesn't crush their supply chain.
     * Their pricing power is mainly tied to capturing the output gap during booms.
     */
    public function calculatePricingPowerModifier(Stock $stock, array $macroState): float
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        return ($outputGap * (float) $stock->getBeta() * 0.25);
    }

    /**
     * Standard idiosyncratic shock applied directly to loan origination volume and fee revenue.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.15)));
        $actualVariableCosts = $actualRevenue * $realizedVariableMargin;

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts, 
            'primary_shock_z' => $revenueZ
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