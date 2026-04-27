<?php

namespace App\Service;

use App\Entity\Stock;

class DebtEngine
{
    // Maturity Wall Constants
    private const QUARTERLY_DEBT_TURNOVER = 0.05; // 5% of old debt expires every quarter (5-year average maturity)

    // Debt Analysis Constants
    private const CASH_YIELD_SPREAD = 0.02;
    private const ARBITRAGE_HURDLE = 0.025;
    private const MIN_INTEREST_COVERAGE_RATIO = 2.0;

    public function calculateInterestExpense(Stock $stock, array $macroState, bool $advanceMaturity = false): array
    {
        $debt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        
        $baselineCreditSpread = (float) $stock->getCreditSpread();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        // FUNDAMENTAL UNDERWRITING (Net Debt to EBIT)
        $revenue = (float) $stock->getTotalRevenue();
        
        // Failsafe: If the database is unseeded or hasn't caught up, fundamentally estimate revenue
        if ($revenue <= 0.0) {
            $investedCapital = $stock->getInvestedCapital();
            $baselineRoic = max(0.01, (float) $stock->getBaselineRoic());
            $marginFallback = max(0.01, (float) $stock->getOperatingMargin());
            $assetTurnover = $baselineRoic / $marginFallback;
            $revenue = $investedCapital * $assetTurnover;
        }
        
        $margin = max(0.01, (float) $stock->getOperatingMargin());
        $ebit = $revenue * $margin;
        
        // Failsafe for zero debt companies
        if ($debt <= 0.0) {
            return [
                'interest_expense' => 0.0, 
                'blended_rate' => 0.0, 
                'dynamic_spread' => $baselineCreditSpread,
                'current_market_rate' => $policyRate + $baselineCreditSpread,
                'ebit' => $ebit,
                'revenue' => $revenue
            ];
        }

        // Markets care about NET debt (Debt minus cash on hand)
        $netDebt = max(0.0, $debt - $treasury);
        
        if ($netDebt <= 0.0) {
            $leverageRatio = 0.0;
        } else {
            $leverageRatio = $ebit > 0 ? min(999.0, $netDebt / $ebit) : 999.0;
        }

        // SECTOR-SPECIFIC LEVERAGE TOLERANCE
        $leverageThreshold = $this->getSectorLeverageThreshold($stock->getSector());

        // THE JUNK BOND BLOWOUT (Convex Penalty)
        $leveragePenalty = 0.0;
        if ($leverageRatio > $leverageThreshold) {
            $excessLeverage = $leverageRatio - $leverageThreshold;
            
            // Real-world credit risk is exponential. As you pass the threshold, 
            $leveragePenalty = (exp($excessLeverage * 0.20) - 1.0) * 0.010; 
            
            // Cap the penalty so the math doesn't break the game engine during absolute collapse
            $leveragePenalty = min(0.25, $leveragePenalty); 
        }

        $dynamicSpread = $baselineCreditSpread + $leveragePenalty;
        $currentMarketFixedRate = $policyRate + $dynamicSpread;

        // THE MATURITY WALL (Refinancing Old Debt)
        $historicalRate = (float) $stock->getHistoricalFixedRate();
        
        if ($advanceMaturity) {
            $turnover = self::QUARTERLY_DEBT_TURNOVER;
            
            // OPPORTUNISTIC REFINANCING
            // If market rates are significantly cheaper (e.g., > 1.5% lower), CFOs aggressively call and refinance old debt
            if ($currentMarketFixedRate < ($historicalRate - 0.015)) {
                $turnover = 0.30; // Refinance 30% of the debt book this quarter instead of the passive 5%
            }

            // Companies refinance expiring or called debt at current market rates
            $blendedFixedRate = ($historicalRate * (1.0 - $turnover)) + 
                                ($currentMarketFixedRate * $turnover);
        } else {
            $blendedFixedRate = $historicalRate;
        }

        // FINAL INTEREST EXPENSE
        // Floating rate debt resets immediately. Fixed rate debt is insulated.
        $floatingInterestRate = $policyRate + $dynamicSpread;

        $interestExpense = ($debt * (1.0 - $floatingRatio) * $blendedFixedRate) +
                           ($debt * $floatingRatio * $floatingInterestRate);

        return [
            'interest_expense' => $interestExpense,
            'blended_rate' => $blendedFixedRate,
            'dynamic_spread' => $dynamicSpread,
            'current_market_rate' => $currentMarketFixedRate,
            'ebit' => $ebit,
            'revenue' => $revenue
        ];
    }

    public function analyzeDebtHealth(Stock $stock, array $macroState): array
    {
        $currentDebt = (float) $stock->getTotalDebt();
        $equity = (float) $stock->getTotalEquity();
        $policyRate = $macroState['policy_rate_ema'] ?? $macroState['policy_rate'] ?? 0.04;
        $corporateTaxRate = $macroState['corporate_tax_rate'] ?? 0.21;
        

        $debtMetrics = $this->calculateInterestExpense($stock, $macroState, false);

        // Gross Cost
        $grossCostOfDebt = $currentDebt > 0 
            ? $debtMetrics['interest_expense'] / $currentDebt 
            : $debtMetrics['current_market_rate'];
            
        // Tax Shield (Interest payments reduce taxable income)
        $effectiveCostOfDebt = $grossCostOfDebt * (1.0 - $corporateTaxRate);
            
        // Levered Beta (The Penalty for Greed)
        // Use abs() to capture high inverse volatility, floored at 0.5 for baseline risk
        $baseBeta = max(0.5, abs((float) $stock->getBeta()));
        $debtToEquity = $equity > 0 ? ($currentDebt / $equity) : 0.0;
        
        // Standard CAPM breaks down during insolvency. Cap D/E at 10.0 to prevent runaway WACC math.
        $effectiveDebtToEquity = min(10.0, $debtToEquity);
        $leveredBeta = $baseBeta * (1.0 + ((1.0 - $corporateTaxRate) * $effectiveDebtToEquity));

        // Cost of Equity (CAPM)
        $equityRiskPremium = 0.05;
        $costOfEquity = $policyRate + ($leveredBeta * $equityRiskPremium);

        // Weighted Average Cost of Capital (WACC)
        $totalCapital = $currentDebt + $equity;
        $weightEquity = $totalCapital > 0 ? ($equity / $totalCapital) : 1.0;
        $weightDebt = $totalCapital > 0 ? ($currentDebt / $totalCapital) : 0.0;
        $wacc = ($weightEquity * $costOfEquity) + ($weightDebt * $effectiveCostOfDebt);

        // Cash Yield & Arbitrage Hurdle (Money Market Funds)
        $yieldOnCash = max(0.0, $policyRate - self::CASH_YIELD_SPREAD);
        
        // "Negative Carry" means it costs more to hold the debt than the cash is earning in the bank
        // Compare Gross to Gross to avoid tax illusions (interest income on cash is also taxable)
        $isSevereNegativeCarry = $grossCostOfDebt > ($yieldOnCash + self::ARBITRAGE_HURDLE);

        // Interest Coverage Ratio (ICR)
        $ebit = $debtMetrics['ebit'];
        $interestExpense = $debtMetrics['interest_expense'];
        
        $interestCoverage = $interestExpense > 0 ? ($ebit / $interestExpense) : 999.0;

        // Strategic Debt Flags for the CFO AI
        $wantsToPaydownDebt = ($currentDebt > 0) && ($isSevereNegativeCarry || $interestCoverage < self::MIN_INTEREST_COVERAGE_RATIO);
        
        // M&A / Buyback Borrowing Capacity
        $canIssueDebt = !$isSevereNegativeCarry && $interestCoverage >= (self::MIN_INTEREST_COVERAGE_RATIO + 1.5);

        // Macro-Economic CFO Tolerance (How much debt are they comfortable holding?)
        // Drops rapidly if interest rates are punishingly high
        $macroDebtTolerance = min(4.0, max(0.50, 4.0 - ($effectiveCostOfDebt * 20.0)));

        return [
            'gross_cost' => $grossCostOfDebt,
            'effective_cost' => $effectiveCostOfDebt,
            'cash_yield' => $yieldOnCash,
            'is_severe_negative_carry' => $isSevereNegativeCarry,
            'interest_coverage' => $interestCoverage,
            'wants_to_paydown_debt' => $wantsToPaydownDebt,
            'can_issue_debt' => $canIssueDebt,
            'debt_tolerance' => $macroDebtTolerance,
            'wacc' => $wacc,
            'cost_of_equity' => $costOfEquity,
            'levered_beta' => $leveredBeta,
            'raw_metrics' => $debtMetrics
        ];
    }

    /**
     * Determines how much leverage (Net Debt / EBIT) a company can safely hold 
     * before bond markets panic, based on the stability of their sector.
     */
    private function getSectorLeverageThreshold(string $sector): float
    {
        return match ($sector) {
            // Highly stable, regulated cash flows. Can easily carry massive debt.
            'Utilities', 'Real Estate' => 5.0, 
            
            // Asset heavy, relatively stable cash flows
            'Industrials', 'Materials', 'Energy', 'Consumer Staples' => 3.5, 
            
            // Moderate cycle sensitivity
            'Healthcare', 'Communication Services' => 3.0, 

            // Highly volatile, cyclical, or asset-light. Debt is dangerous here.
            'Consumer Discretionary', 'Information Technology' => 2.0, 
            
            // Financials are a special case (they are naturally highly levered)
            'Financials' => 6.0, 
            
            default => 3.0,
        };
    }
}