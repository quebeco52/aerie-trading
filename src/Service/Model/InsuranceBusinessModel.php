<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Insurance companies.
 * 
 * Financial Physics:
 * - Revenue (Premiums) is highly sticky and predictable.
 * - Variance comes from Catastrophes (Claims/Underwriting losses).
 * - Structural profits come from "The Float" (investing premium cash before it's paid out).
 * - Evaluated on Return on Equity (ROE) rather than ROIC.
 */
class InsuranceBusinessModel implements BusinessModelInterface
{
    /**
     * Reverse engineers the required operating metrics based on Balance Sheet Capacity.
     * Insurance revenue (Premiums) is strictly constrained by Surplus Equity (The Kenney Rule).
     *
     * @param Stock       $stock       The insurance stock entity being evaluated.
     * @param array       $macroState  The current macroeconomic state.
     * @param MathUtility $mathUtility Mathematical utility for engine operations.
     * @return array{invested_capital: float, baseline_roic: float} Target operating metrics.
     */
    public function getTargetMetrics(Stock $stock, array $macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        // --- THE CLEAR BALANCE SHEET MATH ---
        // 1. Capacity Constraint: Revenue must NEVER be reverse-engineered from target EBIT.
        // It must be mathematically clamped to the firm's physical capital to prevent hyperinflation.
        
        $capacityRatio = 3.0; // Kenney Rule: Max 3.0x of surplus equity annually
        $operatingEquity = max(1.0, $equity);

        $taxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;

        // 2. Structural Revenue is anchored strictly to their capacity limit.
        $targetRevenue = $operatingEquity * $capacityRatio;

        // 3. The engine requires Baseline ROIC, which implies a specific Asset Turnover.
        // Turnover = Revenue / Invested Capital
        $impliedTurnover = $targetRevenue / $operatingEquity;

        return [
            'invested_capital' => $operatingEquity,
            'baseline_roic'    => ($impliedTurnover * $stableMargin) * (1.0 - $taxRate)
        ];
    }

    public function getMacroPhysics(Stock $stock, array $macroState): array
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $beta = (float) $stock->getBeta();
        
        return [
            'macro_demand_shift' => $outputGap * $beta * 0.10, // Highly immune to macro demand
            'pricing_power_multiplier' => 1.0, 
            'operating_leverage_rate' => 0.05, 
        ];
    }

    /**
     * Models the "Catastrophe Physics". Premium top-line revenue barely moves,
     * but cost margins can explode due to unpredictable massive claim payouts (Hurricanes, Mass Torts).
     *
     * @param Stock       $stock                  The insurance stock entity.
     * @param float       $expectedRevenue        The baseline expected revenue.
     * @param float       $realizedVariableMargin The expected variable cost margin.
     * @param float       $fixedCosts             The absolute fixed costs of operations.
     * @param float       $baselineVol            The stock's historical volatility.
     * @param array       $macroState             The current macroeconomic state.
     * @param MathUtility $mathUtility            Mathematical utility for Z-score generation.
     * @return array{actual_revenue: float, actual_variable_costs: float, ebit: float, primary_shock_z: float, event_lore: string|null}
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array $macroState, MathUtility $mathUtility): array
    {
        // 1. Premium Revenue Shock (Very low top-line variance)
        $revenueZ = $mathUtility->generateStandardNormal();
        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * 0.05)));
        
        // 2. The Combined Ratio Shock (Catastrophes/Underwriting Cycle)
        $claimZ = $mathUtility->generateStandardNormal();
        
        // Catastrophes are asymmetric. A hurricane causes massive losses, but a lack of hurricanes only mildly boosts profits.
        $underwritingShock = $claimZ < -1.5 ? abs($claimZ) * 0.15 : ($claimZ > 1.0 ? -0.05 : 0.0);
        
        $actualVariableCosts = $actualRevenue * min(0.99, max(0.01, $realizedVariableMargin + $underwritingShock));
        
        $eventLore = null;
        if ($claimZ < -2.0) {
            $eventLore = "Suffered catastrophic claim losses from a major systemic disaster.";
        } elseif ($claimZ < -1.5) {
            $eventLore = "Elevated claim payouts negatively impacted quarterly underwriting margins.";
        }

        return [
            'actual_revenue' => $actualRevenue, 
            'actual_variable_costs' => $actualVariableCosts, 
            'ebit' => $actualRevenue - $fixedCosts - $actualVariableCosts, 
            'primary_shock_z' => abs($claimZ) > abs($revenueZ) ? $claimZ : $revenueZ,
            'event_lore' => $eventLore
        ];
    }

    /**
     * Insurance companies invest their massive Float in long-duration bonds.
     * They earn a premium yield on virtually ALL their cash, not just the "excess" working capital.
     *
     * @param Stock       $stock       The insurance stock entity.
     * @param array       $macroState  The current macroeconomic state (provides bond yields and ERP).
     * @param MathUtility $mathUtility Mathematical utility.
     * @return float The total absolute interest income generated by the portfolio.
     */
    public function calculateInterestIncome(Stock $stock, array $macroState, MathUtility $mathUtility): float
    {
        $cash = (float) $stock->getCorporateTreasury();
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        
        $floatYield = $this->calculateCashYield($macroState, $policyRate);
        
        return $cash * $floatYield;
    }

    /**
     * Financial companies are evaluated strictly on Return on Equity (ROE), not ROIC.
     *
     * @param Stock $stock                The insurance stock entity.
     * @param float $actualTotalNetIncome The total physical net income generated this quarter.
     * @param float $investedCapital      The invested capital (Total Equity for financials).
     * @param float $ebit                 Earnings before interest and taxes.
     * @param float $corporateTaxRate     The effective corporate tax rate.
     * @return float The true post-tax return on equity.
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

    /**
     * Retrieves the effective corporate tax rate for the insurance business.
     *
     * @param float $macroTaxRate The baseline macroeconomic corporate tax rate.
     * @return float The effective tax rate applied to earnings.
     */
    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        return $macroTaxRate;
    }

    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return max($operatingBase * 0.05, $currentLiability * 1.0);
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return max($operatingBase * 0.03, $currentLiability * 0.85);
    }

    public function evaluateHoardingStatus(float $excessCash, float $operatingBase, float $totalDebt): array
    {
        return [
            'is_hoarder'      => $excessCash > ($totalDebt * 0.90),
            'is_mega_hoarder' => $excessCash > ($totalDebt * 1.10),
        ];
    }

    public function calculateDepositBeta(float $totalDebt, float $equity, float $equityLimit, float $customerDeposits): float { return 0.0; }

    public function calculateCapacityModifier(float $totalDebt, float $equity, float $equityLimit, ?float $coreLiabilities = null): float
    {
        $utilization = $equity > 0.0 ? ($totalDebt / ($equity * $equityLimit)) : 1.0;
        $floatRatio = $totalDebt > 0 ? ($coreLiabilities / $totalDebt) : 0.0;
        
        $decayRate = 0.50 + (1.50 * $floatRatio);
        $capacityModifier = 1.50 * exp(-$decayRate * pow($utilization, 4.0));
        
        return max(0.01, min(1.50, $capacityModifier));
    }

    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float
    {
        return $isMegaHoarder ? $excessCash * 0.30 : min($excessCash * 0.15, $retainedEarningsThisQuarter * 1.5);
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array
    {
        $corporateDebt = (float) $stock->getWholesaleDebt();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $interestExpense = ($corporateDebt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($corporateDebt * $floatingRatio * $floatingInterestRate);
        return ['interest_expense' => $interestExpense, 'wholesale_rate' => $corporateDebt > 0 ? ($interestExpense / $corporateDebt) : $currentMarketFixedRate];
    }

    public function getInterestCoverage(float $ebit, float $interestExpense): float { return 999.0; }
    
    public function calculateCashYield(array $macroState, float $policyRate): float
    {
        // The Float Portfolio:
        // Insurance companies do not just hold cash in a vault; they invest their massive Float 
        // heavily into long-duration bonds, with a smaller allocation to equities for growth.
        $yield10y = $macroState['yield_10y_ema'] ?? ($macroState['policy_rate_ema'] ?? 0.02) + 0.01;
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        
        // 80% Fixed Income (Anchored to the 10-Year Treasury Yield)
        $bondReturn = $yield10y;
        
        // 20% Equities (Suffers capital losses during recessions)
        // A baseline 7% return, taking heavy realized losses during negative output gaps.
        $equityReturn = 0.07 + ($outputGap * 2.0);
        
        // Blended portfolio yield, floored at 0% so they don't mathematically lose the raw principal
        $floatYield = max(0.0, (0.80 * $bondReturn) + (0.20 * $equityReturn));
        
        // Dampening
        return $floatYield * 0.50;
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier): array 
    { 
        return ['probability' => 0.40 + ($spreadMultiplier * 0.30), 'aggressiveness' => 0.02 + (0.08 * $spreadMultiplier)]; 
    }
    
    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float 
    { 
        return max($organicSpend, $debtIssued * 0.80); 
    }

    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float
    {
        // Insurance companies should fund expansion using their premium float (excess cash) first
        return max(0.0, $baseCapacity - $excessCash);
    }
    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float { return $peFairValue; }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps): float
    {
        $bookWeight = $normalizedEps > 0 ? 0.40 : 0.80;
        return ($earningsValue * (1.0 - $bookWeight)) + ($pbFairValue * $bookWeight);
    }

    public function processPassiveLiabilityGrowth(Stock $stock, array $macroState, array &$state, MathUtility $mathUtility): void
    {
        $currentLiabilities = $state['customerDeposits']; 
        if ($currentLiabilities <= 0) return;

        $equity = (float) $stock->getTotalEquity();
        $totalDebt = $state['wholesaleDebt'] + $currentLiabilities;
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?: 'General']['equity_limit'] ?? 10.0;
        
        // Nominal Systemic Growth: The Float grows naturally alongside the M2 Money Supply.
        $systemicGrowthQuarterly = (($macroState['inflation_ema'] ?? 0.02) + 0.02 + ((($macroState['output_gap_ema'] ?? 0.0) > 0.0 ? ($macroState['output_gap_ema'] ?? 0.0) * 0.5 : ($macroState['output_gap_ema'] ?? 0.0) * 2.0))) / 4.0;
        
        // Premium-to-Surplus Capacity constraint (Kenney Rule) throttles growth if they don't have enough equity to back the policies.
        $baseGrowth = $systemicGrowthQuarterly * max(0.75, min(1.25, abs((float) $stock->getBeta()))) * $this->calculateCapacityModifier($totalDebt, $equity, $equityLimit, $currentLiabilities);
        $liabilityChange = $currentLiabilities * max(-0.15, min(0.15, $baseGrowth + ($mathUtility->generateStandardNormal() * 0.005)));

        if (abs($liabilityChange) > 0) {
            $state['treasury'] += $liabilityChange;
            $state['customerDeposits'] += $liabilityChange;
            
            if ($state['treasury'] < 0.0) {
                $liquidityShortfall = abs($state['treasury']);
                $state['treasury'] = 0.0;
                $state['wholesaleDebt'] += $liquidityShortfall;
                $amtB = number_format($liquidityShortfall / 1_000_000_000, 2);
                $state['events'][] = ['description' => "Catastrophe claim payouts exceeded cash reserves. Forced to borrow \${$amtB}B.", 'shock' => -5.0];
            }

            $stock->setCustomerDeposits((string) max(0.0, $state['customerDeposits']));
            if (($liabilityChange / $currentLiabilities) < -0.005) $state['events'][] = ['description' => "Suffered \$" . number_format(abs($liabilityChange) / 1_000_000_000, 2) . "B in policy roll-offs.", 'shock' => -2.0];
            elseif (($liabilityChange / $currentLiabilities) > 0.005) $state['events'][] = ['description' => "Captured \$" . number_format($liabilityChange / 1_000_000_000, 2) . "B in new premium Float.", 'shock' => 0.5];
        }
    }
}