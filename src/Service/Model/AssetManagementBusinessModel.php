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
class AssetManagementBusinessModel implements BusinessModelInterface
{
    /**
     * Asset Managers scale EBIT to cover their target ROE and any operational wholesale debt.
     * They do not use fractional customer deposits or float to generate leverage.
     */
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
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
        $expectedInterestExpense = $wholesaleDebt * $blendedWholesaleRate;

        // --- THE CLEAR BALANCE SHEET MATH ---
        // Asset managers scale EBIT from their active operating equity (AUM/Platform capacity).
        // Excess cash beyond target operating cash is considered idle and stripped from the ROE target.
        $operatingBase = max((float) $stock->getTotalRevenue(), $equity, 10000000.0);
        $targetOperatingCash = max($operatingBase * 0.10, $wholesaleDebt * 0.05);
        
        $excessCash = max(0.0, $treasury - $targetOperatingCash);
        $operatingEquity = max(1.0, $equity - $excessCash);
        
        $targetOperatingNetIncome = $operatingEquity * $baselineRoe;
        $targetEbt = $targetOperatingNetIncome / (1.0 - $taxRate);
        
        $operatingInterestIncome = min($treasury, $targetOperatingCash) * max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
        
        $targetEbit = $targetEbt + $expectedInterestExpense - $operatingInterestIncome;
        // ------------------------------------
        
        // The Fee Revenue Floor:
        // Asset-light financials don't have massive balance sheets, but they must maintain 
        // structural fee revenue (AUM / Advisory) to survive.
        $minOperatingEbit = $operatingEquity * 0.05; 
        
        $targetEbit = max($minOperatingEbit, $targetEbit);
        
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());
        
        $targetRevenue = max(0.0, $targetEbit) / $stableMargin;
        $impliedTurnover = $targetRevenue / max(1.0, $operatingEquity);
        
        return [
            'invested_capital' => $operatingEquity,
            'baseline_roic' => $impliedTurnover * $stableMargin
        ];
    }

    /**
     * Idiosyncratic variance is relatively low compared to transactional brokerages.
     * AUM fees are highly recurring and sticky, providing a stable baseline of revenue.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
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
    public function calculateInterestIncome(Stock $stock, array $macroState, MathUtility $mathUtility): float
    {
        $equity = (float) $stock->getTotalEquity();
        $operatingBase = max((float) $stock->getTotalRevenue(), $equity, 10000000.0);
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - ($operatingBase * 0.05));
        
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

    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        return $macroTaxRate;
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

    public function calculateDepositBeta(float $totalDebt, float $equity, float $equityLimit, float $customerDeposits): float { return 0.0; }

    public function calculateCapacityModifier(float $totalDebt, float $equity, float $equityLimit, ?float $coreLiabilities = null): float { return 1.0; }

    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float
    {
        return $isMegaHoarder ? $excessCash * 0.30 : $excessCash * 0.10;
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array
    {
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $interestExpense = ($debt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($debt * $floatingRatio * $floatingInterestRate);
        $wholesaleRate = $debt > 0 ? ($interestExpense / $debt) : $currentMarketFixedRate;
        return ['interest_expense' => $interestExpense, 'wholesale_rate' => $wholesaleRate];
    }

    public function getInterestCoverage(float $ebit, float $interestExpense): float { return $interestExpense > 0 ? ($ebit / $interestExpense) : ($ebit > 0 ? 999.0 : -999.0); }
    public function calculateCashYield(array $macroState, float $policyRate): float 
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
    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float { return max($revenueFloorValue, $peFairValue); }
    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps): float { return ($earningsValue * 0.90) + ($pbFairValue * 0.10); }
    public function processPassiveLiabilityGrowth(Stock $stock, array $macroState, array &$state, MathUtility $mathUtility): void {}
}