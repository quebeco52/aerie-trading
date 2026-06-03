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
        $structuralSpread = (float) $stock->getCreditSpread();
        
        $targetNetIncome = $equity * $baselineRoe;
        $taxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;
        $targetEbt = $targetNetIncome / (1.0 - $taxRate);
        
        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $treasury = (float) $stock->getCorporateTreasury();
        
        $expectedInterestExpense = $wholesaleDebt * ($policyRate + $structuralSpread);
        $expectedInterestIncome = $treasury * max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
        
        $targetEbit = $targetEbt + $expectedInterestExpense - $expectedInterestIncome;
        
        // The Fee Revenue Floor:
        // Asset-light financials don't have massive balance sheets, but they must maintain 
        // structural fee revenue (AUM / Advisory) to survive.
        $minOperatingEbit = $equity * 0.05; // 5% minimum operating EBIT on equity
        
        $targetEbit = max($minOperatingEbit, $targetEbit);
        
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());
        
        $targetRevenue = max(0.0, $targetEbit) / $stableMargin;
        $impliedTurnover = $targetRevenue / max(1.0, abs($equity));
        
        return [
            'invested_capital' => $equity,
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
    public function calculateCashYield(array $macroState, float $policyRate): float { return max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD); }
    public function getDebtExpansionAggressiveness(float $spreadMultiplier): array { return ['probability' => 0.50 + ($spreadMultiplier * 0.30), 'aggressiveness' => 0.05 + (0.10 * $spreadMultiplier)]; }
    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float { return 0.0; }
    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float { return max($revenueFloorValue, $peFairValue); }
    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps): float { return ($earningsValue * 0.90) + ($pbFairValue * 0.10); }
    public function processPassiveLiabilityGrowth(Stock $stock, array $macroState, array &$state, MathUtility $mathUtility): void {}
}