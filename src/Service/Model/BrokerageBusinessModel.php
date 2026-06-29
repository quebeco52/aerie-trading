<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;

/**
 * Earnings strategy for Brokerages & Capital Markets.
 * 
 * Financial Physics:
 * - Highly leveraged, transaction-driven business model.
 * - Revenue scales off trading volume, investment banking advisory, and margin loans.
 * - Evaluated on Return on Equity (ROE).
 */
class BrokerageBusinessModel extends AssetManagementBusinessModel
{
    /**
     * Idiosyncratic shock applied to retail trading volume and institutional deal flow.
     * Capital Markets have higher top-line variance compared to sticky Asset Managers.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();

        // The Volatility Bonus (Trading Volume):
        // Brokerage revenues are hyper-sensitive to the VIX (Systemic Market Volatility). 
        // High Volatility = Massive trading volume (panic selling or euphoria buying) which generates massive fees.
        $vixEma = $macroState['market_volatility_ema'] ?? ($macroState['market_volatility'] ?? 0.20);
        $volatilityBonus = max(0.0, ($vixEma - 0.20) * 0.5); // Direct revenue boost from average quarterly trading volume

        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.20)) + $volatilityBonus);

        $actualVariableCosts = $actualRevenue * min(1.50, max(0.01, $realizedVariableMargin));

        $eventLore = null;
        if ($vixEma > 0.30) {
            $eventLore = "Record trading volumes driven by extreme market volatility resulted in massive fee generation.";
        } elseif ($revenueZ < -2.0) {
            $eventLore = "Suffered a steep decline in investment banking deal flow and advisory fees.";
        }

        return [
            'actual_revenue' => $actualRevenue,
            'actual_variable_costs' => $actualVariableCosts,
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts,
            'primary_shock_z' => $revenueZ,
            'event_lore' => $eventLore
        ];
    }

    public function getTargetMetrics(Stock $stock, array &$macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        
        $ttmRoe = (float) $stock->getRoeTtm();
        if ($ttmRoe !== 0.0) {
            $baselineRoe = ($baselineRoe * 0.70) + ($ttmRoe * 0.30);
        }

        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        $yield5y = $macroState['yield_5y_ema'] ?? ($macroState['yield_5y'] ?? $policyRate + 0.005);
        $structuralSpread = (float) $stock->getCreditSpread();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        $taxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;

        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $treasury = (float) $stock->getCorporateTreasury();

        $blendedWholesaleRate = ($floatingRatio * $policyRate) + ((1.0 - $floatingRatio) * $yield5y) + $structuralSpread;

        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 8.0;

        $effectiveEquity = max(1.0, $equity);

        // Brokerages rely heavily on wholesale debt to fund high-yielding margin loans for their clients.
        $actualLeverage = $effectiveEquity > 0 ? ($wholesaleDebt / $effectiveEquity) : 0.0;
        $allowedLeverage = min($actualLeverage, max(0.0, $equityLimit));
        $optimalDebt = $effectiveEquity * $allowedLeverage;

        $optimalInterestExpense = $optimalDebt * $blendedWholesaleRate;

        // Margin loans yield a spread over the policy rate.
        $marginLoanYield = $policyRate + FinancialConstants::MARGIN_LOAN_SPREAD;
        $optimalInterestIncome = $optimalDebt * $marginLoanYield;

        $optimalOperatingNetIncome = $effectiveEquity * $baselineRoe;
        $optimalEbt = $optimalOperatingNetIncome / (1.0 - $taxRate);

        $optimalEbit = $optimalEbt + $optimalInterestExpense - $optimalInterestIncome;
        $optimalEarningAssets = $effectiveEquity + $optimalDebt;
        $structuralAssetYield = $optimalEbit / max(1.0, $optimalEarningAssets);

        $operatingBase = $this->getOperatingBase($stock);
        $targetOperatingCash = $this->calculateTargetOperatingCash($operatingBase, 0.0, $wholesaleDebt);
        $excessCash = max(0.0, $treasury - $targetOperatingCash);
        $earningAssets = max(1.0, ($equity + $wholesaleDebt) - $excessCash);

        $targetEbit = $earningAssets * $structuralAssetYield;
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        $minOperatingEbit = $earningAssets * 0.015;
        $targetEbit = max($minOperatingEbit, $targetEbit);

        $unboundedRevenue = max(0.0, $targetEbit) / $stableMargin;
        $targetRevenue = min($unboundedRevenue, $earningAssets * 1.50); // Hard cap gross yield on total assets

        $grossYield = $targetRevenue / max(1.0, $earningAssets);

        return [
            'invested_capital' => $earningAssets,
            'baseline_roic' => ($grossYield * $stableMargin) * (1.0 - $taxRate)
        ];
    }

    public function calculateInterestIncome(Stock $stock, array &$macroState, MathUtility $mathUtility): float
    {
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;

        // 1. Margin Loan Yield
        // Brokerages lend their wholesale debt to clients as margin loans.
        $marginLoanYield = $policyRate + FinancialConstants::MARGIN_LOAN_SPREAD;
        $marginLoans = (float) $stock->getWholesaleDebt(); // Proxy: Wholesale debt is deployed into margin loans
        $marginInterest = $marginLoans * $marginLoanYield;

        // 2. Excess Cash Yield
        $operatingBase = $this->getOperatingBase($stock);
        $minCash = $this->calculateMinOperatingCash($operatingBase, 0.0, (float) $stock->getWholesaleDebt());
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - $minCash);
        $cashYield = $this->calculateCashYield($macroState, $policyRate);
        $cashInterest = $excessCash * $cashYield;

        return $marginInterest + $cashInterest;
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array
    {
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        // Brokerages fund operations and margin lending purely via wholesale debt markets.
        $interestExpense = ($debt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($debt * $floatingRatio * $floatingInterestRate);
        $wholesaleRate = $debt > 0 ? ($interestExpense / $debt) : $currentMarketFixedRate;

        return [
            'interest_expense' => $interestExpense,
            'wholesale_rate' => $wholesaleRate
        ];
    }

    /**
     * Brokerages and Investment Banks do not dump their treasury into 60/40 mutual funds.
     * Their excess cash must remain highly liquid to satisfy clearinghouse margin requirements 
     * and strict regulatory capital constraints. They earn standard risk-free money market yields.
     */
    public function calculateCashYield(array &$macroState, float $policyRate): float
    {
        return max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
    }

    /**
     * Brokerages require significantly higher liquidity than standard asset managers.
     * They must hold massive cash reserves against their wholesale debt to satisfy 
     * clearinghouse margin requirements and facilitate high-frequency trade settlements.
     */
    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        // Requires 15% cash backing on all outstanding wholesale debt
        return max($operatingBase * 0.15, $wholesaleDebt * 0.15);
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        // Hard 10% liquidity floor to prevent catastrophic margin calls
        return max($operatingBase * 0.10, $wholesaleDebt * 0.10);
    }
}
