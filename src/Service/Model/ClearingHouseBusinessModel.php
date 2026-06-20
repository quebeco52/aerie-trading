<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Central Counterparty Clearing Houses (CCP).
 * 
 * Financial Physics:
 * - Revenue scales off transaction volume (benefiting from high VIX / Market Panics).
 * - Holds massive "Initial Margin" deposits from members, earning overnight repo rates.
 * - Carries extreme apocalyptic tail risk: if members default simultaneously, the CCP must cover the trades.
 */
class ClearingHouseBusinessModel extends InsuranceBusinessModel
{
    public function getTargetMetrics(Stock $stock, array &$macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());
        
        // Clearinghouses don't use massive wholesale debt for leverage; their leverage is the margin pool.
        $effectiveEquity = max(1.0, $equity);
        $taxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;
        
        // 1. Calculate Required EBT to hit Target ROE
        $optimalNetIncome = $effectiveEquity * $baselineRoe;
        $optimalEbt = $optimalNetIncome / (1.0 - $taxRate);
        
        // 2. Calculate Net Interest Income from the Margin Pool
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        $marginPool = (float) $stock->getCustomerDeposits();
        $corporateDebt = (float) $stock->getWholesaleDebt();
        
        $yield2y = $macroState['yield_2y_ema'] ?? ($macroState['yield_2y'] ?? $policyRate);
        $earnedYield = max(0.0, $yield2y - MacroEngine::CASH_YIELD_SPREAD);
        $rebateRate = max(0.001, $policyRate - 0.0015);
        
        $optimalInterestIncome = ($marginPool + $effectiveEquity + $corporateDebt) * $earnedYield;
        
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $yield5y = $macroState['yield_5y_ema'] ?? ($macroState['yield_5y'] ?? $policyRate + 0.005);
        $structuralSpread = (float) $stock->getCreditSpread();
        $blendedWholesaleRate = ($floatingRatio * $policyRate) + ((1.0 - $floatingRatio) * $yield5y) + $structuralSpread;
        $corporateInterest = $corporateDebt * $blendedWholesaleRate;
        $marginInterest = $marginPool * $rebateRate;
        
        $optimalInterestExpense = $corporateInterest + $marginInterest;
        
        // 3. Determine Required Operating EBIT
        $optimalEbit = $optimalEbt + $optimalInterestExpense - $optimalInterestIncome;
        
        // Clearinghouses must maintain a baseline transaction volume
        $minEbit = $effectiveEquity * 0.05;
        $targetEbit = max($minEbit, $optimalEbit);
        
        // 4. Reverse-engineer Revenue
        $unboundedRevenue = $targetEbit / $stableMargin;
        $targetRevenue = min($unboundedRevenue, $effectiveEquity * 2.0); // Cap turnover at 2.0x
        
        $impliedTurnover = $targetRevenue / $effectiveEquity;

        return [
            'invested_capital' => $effectiveEquity,
            'baseline_roic' => ($impliedTurnover * $stableMargin) * (1.0 - $taxRate)
        ];
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        
        // The Volatility Bonus (Transaction Volume):
        // Clearinghouses thrive on sheer volume. Market panics = massive liquidations = massive fees.
        $vixEma = $macroState['market_volatility_ema'] ?? ($macroState['market_volatility'] ?? 0.20);
        $volatilityBonus = max(0.0, ($vixEma - 0.20) * 0.4); 
        
        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.05)) + $volatilityBonus);
        
        // The Default Fund Shock (Catastrophic Tail Risk)
        $defaultZ = $mathUtility->generateStandardNormal();
        
        // Tail risk: if multiple titans default simultaneously, the clearinghouse eats the loss.
        $catastropheShock = $defaultZ < -2.5 ? abs($defaultZ) * 0.25 : ($defaultZ > 1.0 ? -0.02 : 0.0);
        
        $actualVariableCosts = $actualRevenue * min(1.50, max(0.01, $realizedVariableMargin + $catastropheShock));
        
        $eventLore = null;
        if ($defaultZ < -3.0) {
            $eventLore = "A massive systemic default breached the initial margin pool, forcing the clearinghouse to cover billions in toxic settlements.";
        } elseif ($vixEma > 0.30) {
            $eventLore = "Record transaction volume driven by market panic generated massive clearing fees.";
        }

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts, 
            'primary_shock_z' => abs($defaultZ) > abs($revenueZ) ? $defaultZ : $revenueZ,
            'event_lore' => $eventLore
        ];
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array
    {
        $corporateDebt = (float) $stock->getWholesaleDebt();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        
        // Corporate debt interest
        $corporateInterest = ($corporateDebt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($corporateDebt * $floatingRatio * $floatingInterestRate);
        
        // Margin Pool Rebate (Customer Deposits)
        // Clearinghouses MUST pay interest back to clearing members on their initial margin, keeping a small spread.
        $marginPool = (float) $stock->getCustomerDeposits();
        $rebateRate = max(0.001, $policyRate - 0.0015); // Pass back policy rate minus 15 bps
        $marginInterest = $marginPool * $rebateRate;
        
        $totalInterestExpense = $corporateInterest + $marginInterest;
        $wholesaleRate = $corporateDebt > 0 ? ($corporateInterest / $corporateDebt) : $currentMarketFixedRate;
        
        return [
            'interest_expense' => $totalInterestExpense, 
            'wholesale_rate' => $wholesaleRate
        ];
    }

    public function calculateCashYield(array &$macroState, float $policyRate): float
    {
        // Clearinghouses cannot take equity risk, but they do park margin in short-duration 
        // government bonds (up to 2 years) to capture slight duration premiums over overnight rates.
        $yield2y = $macroState['yield_2y_ema'] ?? ($macroState['yield_2y'] ?? $policyRate);
        
        return max(0.0, $yield2y - MacroEngine::CASH_YIELD_SPREAD);
    }

    public function processPassiveLiabilityGrowth(Stock $stock, array &$macroState, array &$state, MathUtility $mathUtility): void
    {
        $currentLiabilities = $state['customerDeposits']; 
        if ($currentLiabilities <= 0) return;

        // Nominal Systemic Growth: The baseline market grows over time.
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $realGdpGrowth = 0.02 + ($outputGap > 0.0 ? $outputGap * 0.5 : $outputGap * 2.0);
        $systemicGrowthQuarterly = ($inflation + $realGdpGrowth) / 4.0;
        
        // Volatility Driver: When markets get chaotic, clearinghouses demand higher initial margins.
        // If VIX is above 20%, margins expand. If below, margins contract.
        $vixEma = $macroState['market_volatility_ema'] ?? ($macroState['market_volatility'] ?? 0.20);
        $volatilityShift = ($vixEma - 0.20) * 0.50; 
        
        $baseGrowth = $systemicGrowthQuarterly + $volatilityShift;
        $liabilityChange = $currentLiabilities * max(-0.15, min(0.15, $baseGrowth + ($mathUtility->generateStandardNormal() * 0.01)));

        if (abs($liabilityChange) > 0) {
            $state['treasury'] += $liabilityChange;
            $state['customerDeposits'] += $liabilityChange;
            
            if ($state['treasury'] < 0.0) {
                $liquidityShortfall = abs($state['treasury']);
                $state['treasury'] = 0.0;
                $state['wholesaleDebt'] += $liquidityShortfall;
                $amtB = number_format($liquidityShortfall / 1_000_000_000, 2);
                $state['events'][] = ['description' => "Severe liquidity drain forced emergency borrowing of \${$amtB}B.", 'shock' => -5.0];
            }

            $stock->setCustomerDeposits((string) max(0.0, $state['customerDeposits']));
            
            $changePct = $liabilityChange / $currentLiabilities;
            if ($changePct < -0.01) {
                $state['events'][] = ['description' => "Margin pool contracted by \$" . number_format(abs($liabilityChange) / 1_000_000_000, 2) . "B.", 'shock' => -1.0];
            } elseif ($changePct > 0.01) {
                $state['events'][] = ['description' => "Collected \$" . number_format($liabilityChange / 1_000_000_000, 2) . "B in additional Initial Margin.", 'shock' => 0.5];
            }
        }
    }
}