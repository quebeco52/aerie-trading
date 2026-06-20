<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Asset Managers.
 * 
 * Financial Physics:
 * - Asset light, high margin business model.
 * - Revenue scales off highly sticky, recurring Assets Under Management (AUM) fees.
 * - Evaluated on Return on Equity (ROE).
 */
class AssetManagementBusinessModel extends AbstractBusinessModel
{
    /**
     * Asset Managers scale EBIT to cover their target ROE and any operational wholesale debt.
     * They do not use fractional customer deposits or float to generate leverage.
     */
    public function getTargetMetrics(Stock $stock, array &$macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        $yield5y = $macroState['yield_5y_ema'] ?? ($macroState['yield_5y'] ?? $policyRate + 0.005);
        $structuralSpread = (float) $stock->getCreditSpread();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        
        $taxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;
        
        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $treasury = (float) $stock->getCorporateTreasury();
        
        $blendedWholesaleRate = ($floatingRatio * $policyRate) + ((1.0 - $floatingRatio) * $yield5y) + $structuralSpread;

        // --- THE CLEAR BALANCE SHEET MATH ---
        // Asset managers scale EBIT from their active operating equity (AUM/Platform capacity).
        // Excess cash beyond target operating cash is considered idle and stripped from the ROE target.
        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 1.0;
        
        $effectiveEquity = max(1.0, $equity);
        
        // We use ACTUAL deployed leverage (capped at limits) to prevent the "Phantom Debt" exploit, 
        // where low-leverage brokers pocket theoretical interest expense as massive ROE.
        $actualLeverage = $effectiveEquity > 0 ? ($wholesaleDebt / $effectiveEquity) : 0.0;
        $allowedLeverage = min($actualLeverage, max(0.0, $equityLimit));
        $optimalDebt = $effectiveEquity * $allowedLeverage;
        
        $optimalInterestExpense = $optimalDebt * $blendedWholesaleRate;
        
        $optimalOperatingNetIncome = $effectiveEquity * $baselineRoe;
        $optimalEbt = $optimalOperatingNetIncome / (1.0 - $taxRate);
        
        $operatingBase = max((float) $stock->getTotalRevenue(), $equity, 10000000.0);
        $optimalOperatingCash = $this->calculateTargetOperatingCash($operatingBase, 0.0, $optimalDebt);
        $minOperatingCash = $this->calculateMinOperatingCash($operatingBase, 0.0, $optimalDebt);
        $optimalYieldingCash = max(0.0, $optimalOperatingCash - $minOperatingCash);
        $optimalInterestIncome = $optimalYieldingCash * max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
        
        $optimalEbit = $optimalEbt + $optimalInterestExpense - $optimalInterestIncome;
        $structuralOperatingYield = $optimalEbit / max(1.0, $effectiveEquity);
        
        // Apply the pure structural yield to the ACTUAL active operating equity
        $targetOperatingCash = $this->calculateTargetOperatingCash($operatingBase, 0.0, $wholesaleDebt);
        $excessCash = max(0.0, $treasury - $targetOperatingCash);
        $operatingEquity = max(1.0, $equity - $excessCash);
        
        $targetEbit = $operatingEquity * $structuralOperatingYield;
        // ------------------------------------
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());
        
        // The Fee Revenue Floor:
        // Asset-light financials don't have massive balance sheets, but they must maintain 
        // structural fee revenue (AUM / Advisory) to survive.
        $minOperatingEbit = $operatingEquity * 0.05; 
        
        $targetEbit = max($minOperatingEbit, $targetEbit);
        
        // We derive revenue from target EBIT to hit ROE expectations, 
        // but we MUST cap the turnover. If margins compress due to market saturation, 
        // uncapped reverse-engineering will cause top-line revenue hyperinflation!
        $unboundedRevenue = max(0.0, $targetEbit) / $stableMargin;
        $targetRevenue = min($unboundedRevenue, $operatingEquity * 2.0); // Hard cap turnover at 2.0x annually

        $impliedTurnover = $targetRevenue / max(1.0, $operatingEquity);
        
        return [
            'invested_capital' => $operatingEquity,
            'baseline_roic' => ($impliedTurnover * $stableMargin) * (1.0 - $taxRate)
        ];
    }

    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $beta = (float) $stock->getBeta();
        
        return [
            'macro_demand_shift' => $outputGap * $beta * 0.25,
            'pricing_power_multiplier' => 1.0, 
            'operating_leverage_rate' => 0.05, 
        ];
    }

    /**
     * Idiosyncratic variance is relatively low compared to transactional brokerages.
     * AUM fees are highly recurring and sticky, providing a stable baseline of revenue.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // Sticky Assets Under Management (AUM):
        // AUM fees are highly recurring, making revenue variance significantly lower than trading-heavy brokerages.
        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.10)));
        
        $actualVariableCosts = $actualRevenue * min(0.99, max(0.01, $realizedVariableMargin));

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts, 
            'primary_shock_z' => $revenueZ
        ];
    }

    /**
     * Asset Managers earn standard money-market yields only on excess liquidity 
     * that isn't actively deployed or required for daily operations.
     */
    public function calculateInterestIncome(Stock $stock, array &$macroState, MathUtility $mathUtility): float
    {
        $equity = (float) $stock->getTotalEquity();
        $operatingBase = max((float) $stock->getTotalRevenue(), $equity, 10000000.0);
        $minCash = $this->calculateMinOperatingCash($operatingBase, 0.0, (float) $stock->getWholesaleDebt());
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - $minCash);
        
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        
        return $excessCash * $this->calculateCashYield($macroState, $policyRate);
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

    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return max($operatingBase * 0.10, $wholesaleDebt * 0.05);
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return $operatingBase * 0.05;
    }

    public function evaluateHoardingStatus(float $excessCash, float $operatingBase, float $totalDebt): array
    {
        return [
            'is_hoarder'      => $excessCash > ($operatingBase * 0.30),
            'is_mega_hoarder' => $excessCash > ($operatingBase * 0.50),
        ];
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array
    {
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $interestExpense = ($debt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($debt * $floatingRatio * $floatingInterestRate);
        $wholesaleRate = $debt > 0 ? ($interestExpense / $debt) : $currentMarketFixedRate;
        return ['interest_expense' => $interestExpense, 'wholesale_rate' => $wholesaleRate];
    }
    public function calculateCashYield(array &$macroState, float $policyRate): float 
    {
        $yield10y = $macroState['yield_10y_ema'] ?? ($macroState['policy_rate_ema'] ?? 0.02) + 0.01;
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        
        $bondReturn = $yield10y;
        $equityReturn = 0.07 + ($outputGap * 2.0);
        
        // Asset Managers invest heavily in their own funds ("eating their own cooking").
        // We use a classic 60/40 portfolio (60% Bonds / 40% Equities) which gives them higher market correlation.
        return max(0.0, (0.60 * $bondReturn) + (0.40 * $equityReturn)); 
    }
    public function getDebtExpansionAggressiveness(float $spreadMultiplier): array { return ['probability' => 0.50 + ($spreadMultiplier * 0.30), 'aggressiveness' => 0.05 + (0.10 * $spreadMultiplier)]; }
    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float 
    { 
        // Asset managers and brokerages use capital to seed new funds, acquire advisory firms, and build trading platforms.
        return max($organicSpend, $debtIssued * 0.90); 
    }

    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float
    {
        // Asset managers and brokerages can expand using existing cash hoards before taking on new debt
        return max(0.0, $baseCapacity - $excessCash);
    }
    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float { return max($revenueFloorValue, $peFairValue); }
}