<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Credit Services (Credit Cards, Consumer Finance).
 * 
 * Financial Physics:
 * - Hybrid model relying on interest spreads (like a bank) and transaction volume (like a brokerage).
 * - Revenue directly benefits from inflation because interchange/swipe fees are a percentage of total price.
 * - Highly vulnerable to economic downturns (negative output gap) due to a spike in unsecured loan defaults.
 * - Evaluated on Return on Equity (ROE).
 */
class CreditServicesBusinessModel extends CommercialBankBusinessModel
{
    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        // Swipe fees perfectly capture nominal inflation dynamically.
        // We strip generic pricing power to prevent double-dipping.
        $physics['pricing_power_multiplier'] = 1.0;
        return $physics;
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();

        // The Inflation Bonus (Interchange Fees):
        // Credit Services capture inflation directly into their REVENUE. 
        // Because swipe fees (Visa/Mastercard) are a percentage of the total transaction size, higher prices = higher revenue.
        $inflation = $macroState['inflation_ema'] ?? ($macroState['inflation'] ?? MacroEngine::TARGET_INFLATION);
        $inflationBonus = ($inflation - MacroEngine::TARGET_INFLATION) * abs((float) $stock->getBeta());

        // Moderate the random revenue fluctuations (higher than banks, but not pure chaos)
        $actualRevenue = max(0.0, $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.20)) + $inflationBonus));

        $defaultZ = $mathUtility->generateStandardNormal();

        // Unsecured Default Shock:
        // Credit card debt is unsecured. During recessions, consumers default on cards long before they default on mortgages.
        $outputGap = $macroState['output_gap_ema'] ?? ($macroState['output_gap'] ?? 0.0);
        $macroDefaultDrag = $outputGap < 0.0 ? abs($outputGap) * 0.8 : 0.0;

        $lossProvisionShock = ($defaultZ < -1.5 ? abs($defaultZ) * 0.08 : ($defaultZ > 1.0 ? -0.02 : 0.0)) + $macroDefaultDrag;

        // Net Interest Margin (NIM) Squeeze.
        // 0.5x higher then banks
        $yield10y = $macroState['yield_10y_ema'] ?? ($macroState['yield_10y'] ?? 0.04);
        $yield2y = $macroState['yield_2y_ema'] ?? ($macroState['yield_2y'] ?? 0.03);

        $bankSpread = $yield10y - $yield2y;
        if ($bankSpread < 0) {
            $nimSqueeze = (0.005 - $bankSpread) + pow(abs($bankSpread) * 15, 2) * 0.15;
        } else {
            $nimSqueeze = (0.005 - $bankSpread) * 1.5;
        }

        $actualVariableCosts = $actualRevenue * min(1.50, max(0.01, $realizedVariableMargin + $lossProvisionShock + $nimSqueeze));

        $eventLore = null;
        if ($defaultZ < -2.0) {
            $eventLore = "Took massive provisions for unsecured credit defaults as consumer health deteriorated.";
        } elseif ($defaultZ < -1.5) {
            $eventLore = "Elevated credit card defaults compressed quarterly margins.";
        }

        return [
            'actual_revenue' => $actualRevenue,
            'actual_variable_costs' => $actualVariableCosts,
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts,
            'primary_shock_z' => abs($defaultZ) > abs($revenueZ) ? $defaultZ : $revenueZ,
            'event_lore' => $eventLore
        ];
    }

    public function getTargetMetrics(Stock $stock, array &$macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $totalDebt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();

        $effectiveEquity = max(1.0, $equity);
        $earningAssets = max($effectiveEquity, $effectiveEquity + $totalDebt - $treasury);
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        
        $ttmRoe = (float) $stock->getRoeTtm();
        if ($ttmRoe !== 0.0) {
            $baselineRoe = ($baselineRoe * 0.70) + ($ttmRoe * 0.30);
        }

        $taxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        $yield5y = $macroState['yield_5y_ema'] ?? ($macroState['yield_5y'] ?? $policyRate + 0.005);
        $structuralSpread = (float) $stock->getCreditSpread();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 10.0;

        $customerDeposits = (float) $stock->getCustomerDeposits();
        $depositRatio = $totalDebt > 0 ? ($customerDeposits / $totalDebt) : 0.0;

        // Credit services require highly competitive APYs on their high-yield savings accounts
        $depositBeta = min(0.90, max(0.30, $this->calculateDepositBeta($totalDebt, $equity, $equityLimit, $customerDeposits) + 0.20));
        $depositRate = max(0.001, $policyRate * $depositBeta);
        $blendedWholesaleRate = ($floatingRatio * $policyRate) + ((1.0 - $floatingRatio) * $yield5y) + $structuralSpread;

        $actualLeverage = $effectiveEquity > 0 ? ($totalDebt / $effectiveEquity) : 0.0;
        $allowedLeverage = min($actualLeverage, max(1.0, $equityLimit));
        $optimalDebt = $effectiveEquity * $allowedLeverage;

        $optimalWholesaleDebt = $optimalDebt * (1.0 - $depositRatio);
        $optimalDeposits = $optimalDebt * $depositRatio;
        $optimalInterestExpense = ($optimalWholesaleDebt * $blendedWholesaleRate) + ($optimalDeposits * $depositRate);

        $optimalNetIncome = $effectiveEquity * $baselineRoe;
        $optimalEbt = $optimalNetIncome / (1.0 - $taxRate);

        $optimalEbit = $optimalEbt + $optimalInterestExpense;
        $structuralAssetYield = $optimalEbit / max(1.0, $effectiveEquity + $optimalDebt);

        $targetEbit = $earningAssets * $structuralAssetYield;

        $coreLiabilities = $totalDebt;
        $minLendingEbit = $coreLiabilities * 0.05; // Floor is higher than banks due to high-yield credit card loans

        $targetEbit = max($minLendingEbit, $targetEbit);

        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        $unboundedRevenue = max(0.0, $targetEbit) / $stableMargin;
        $targetRevenue = min($unboundedRevenue, $earningAssets * 0.80); // Cap gross yield at 80% (vs bank's 40%)

        $grossYield = $targetRevenue / max(1.0, abs($earningAssets));

        return [
            'invested_capital' => $earningAssets,
            'baseline_roic' => ($grossYield * $stableMargin) * (1.0 - $taxRate)
        ];
    }

    public function calculateInterestIncome(Stock $stock, array &$macroState, MathUtility $mathUtility): float
    {
        // Credit services generate their interest income from their unsecured loan book.
        $earningAssets = max(1.0, (float) $stock->getTotalEquity() + (float) $stock->getTotalDebt() - (float) $stock->getCorporateTreasury());

        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        // However, this is largely captured in Revenue (Gross Yield). 
        // We only return the supplemental interest from excess treasury cash to avoid double-counting.
        $operatingBase = $this->getOperatingBase($stock);
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - ($operatingBase * 0.05));

        return $excessCash * $this->calculateCashYield($macroState, $policyRate);
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array
    {
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $customerDeposits = (float) $stock->getCustomerDeposits(); // High yield savings sweeps
        $wholesaleDebt = (float) $stock->getWholesaleDebt();

        $wholesaleInterest = ($wholesaleDebt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($wholesaleDebt * $floatingRatio * $floatingInterestRate);
        $wholesaleRate = $wholesaleDebt > 0 ? ($wholesaleInterest / $wholesaleDebt) : $currentMarketFixedRate;

        // Credit services must offer highly competitive APYs on their high-yield savings accounts to attract funding
        $depositBeta = min(0.90, max(0.30, $this->calculateDepositBeta($debt, $totalEquity, $equityLimit, $customerDeposits) + 0.20));
        $depositRate = max(0.001, $policyRate * $depositBeta);
        $depositInterest = $customerDeposits * $depositRate;

        return [
            'interest_expense' => $wholesaleInterest + $depositInterest,
            'wholesale_rate' => $wholesaleRate
        ];
    }
}
