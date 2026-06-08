<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for normal, non-financial companies.
 * 
 * Financial Physics:
 * - Evaluated on Return on Invested Capital (ROIC).
 * - Subject to supply chain inflation and physical depreciation.
 * - Operating scale is based on physical assets, not financial leverage.
 */
class StandardCorporateBusinessModel implements BusinessModelInterface
{
    /**
     * Physical businesses evaluate their true structural scale based on Invested Capital 
     * (Total Equity + Debt - Cash), requiring physical assets to turn a profit.
     */
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        return [
            'invested_capital' => $stock->getInvestedCapital(),
            'baseline_roic' => max(0.01, (float) $stock->getBaselineRoic())
        ];
    }

    /**
     * Idiosyncratic variance is applied directly to sales volume.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        $revenueShock = $revenueZ * ($baselineVol * 0.15);
        $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);
        
        // Supply Chain Inflation Penalty:
        // Physical companies get squeezed by inflation because raw material and labor costs rise 
        // faster than they can safely raise prices on consumers without destroying demand.
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $inflationPenalty = $inflation > 0.03 ? ($inflation - 0.03) * abs((float) $stock->getBeta()) * 1.5 : 0.0;
        
        $actualVariableCosts = $actualRevenue * min(0.99, max(0.01, $realizedVariableMargin + $inflationPenalty));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $ebit, 
            'primary_shock_z' => $revenueZ
        ];
    }

    /**
     * Normal companies earn standard money-market yields only on excess liquidity 
     * that isn't required to run the day-to-day business.
     */
    public function calculateInterestIncome(Stock $stock, array $macroState, MathUtility $mathUtility): float
    {
        $cash = (float) $stock->getCorporateTreasury();
        $equity = (float) $stock->getTotalEquity();
        $operatingBase = max((float) $stock->getTotalRevenue(), $equity, 10000000.0);
        
        $excessCash = max(0.0, $cash - ($operatingBase * 0.05));
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        
        return $excessCash * $this->calculateCashYield($macroState, $policyRate);
    }

    /**
     * Normal physical companies are evaluated on NOPAT / Invested Capital (ROIC).
     */
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate): float
    {
        // NOPAT (Net Operating Profit After Tax) strips out interest expense to measure 
        // the pure operating efficiency of the physical business assets.
        $nopatProxy = $ebit > 0 ? $ebit * (1.0 - $corporateTaxRate) : $ebit;
        
        $effectiveCapital = max(1.0, abs($investedCapital));
        $truePostTaxReturn = $nopatProxy / $effectiveCapital;
        
        $oldRoic = (float) $stock->getCurrentRoic();
        $smoothedRoic = $oldRoic === 0.0 ? $truePostTaxReturn : $oldRoic + (($truePostTaxReturn - $oldRoic) * 0.50);
        
        $stock->setCurrentRoic((string) max(-0.50, min(1.0, $smoothedRoic)));
        
        return $truePostTaxReturn;
    }

    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        return $macroTaxRate;
    }

    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return $operatingBase * 0.05;
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return $operatingBase * 0.03;
    }

    public function evaluateHoardingStatus(float $excessCash, float $operatingBase, float $totalDebt): array
    {
        return [
            'is_hoarder'      => $excessCash > ($operatingBase * 0.25),
            'is_mega_hoarder' => $excessCash > ($operatingBase * 0.40),
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
        
        // Standard corporates pay market interest rates on ALL of their debt. 
        // They do not get the benefit of cheap customer deposits like banks do.
        $interestExpense = ($debt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($debt * $floatingRatio * $floatingInterestRate);
        $wholesaleRate = $debt > 0 ? ($interestExpense / $debt) : $currentMarketFixedRate;

        return ['interest_expense' => $interestExpense, 'wholesale_rate' => $wholesaleRate];
    }

    public function getInterestCoverage(float $ebit, float $interestExpense): float
    {
        return $interestExpense > 0 ? ($ebit / $interestExpense) : ($ebit > 0 ? 999.0 : -999.0);
    }

    public function calculateCashYield(array $macroState, float $policyRate): float
    {
        return max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier): array
    {
        return ['probability' => 0.40 + ($spreadMultiplier * 0.50), 'aggressiveness' => 0.05 + (0.35 * $spreadMultiplier)];
    }

    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float
    {
        return max($organicSpend, $debtIssued * 0.75);
    }

    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float
    {
        if ($fcfPerShare !== null && $fcfPerShare > 0.0) {
            $multiplier = $mathUtility->calculateDcfMultiplier($liveWacc, 0.02);
            $dcfFairValue = max(0.01, $fcfPerShare * $multiplier);
            return ($peFairValue + $dcfFairValue) / 2.0;
        }
        return $fcfPerShare !== null ? max($revenueFloorValue, $peFairValue) * 0.75 : max($revenueFloorValue, $peFairValue);
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps): float { return ($earningsValue * 0.90) + ($pbFairValue * 0.10); }

    public function processPassiveLiabilityGrowth(Stock $stock, array $macroState, array &$state, MathUtility $mathUtility): void {}
}