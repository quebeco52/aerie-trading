<?php

namespace App\Service;

use App\Entity\Stock;

class DebtEngine
{
    // Maturity Wall Constants
    private const QUARTERLY_DEBT_TURNOVER = 0.05; // 5% of old debt expires every quarter (5-year average maturity)

    // Debt Analysis Constants
    private const CASH_YIELD_SPREAD = 0.02;
    private const ARBITRAGE_HURDLE = 0.030; // 300 bps spread is severe
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

        // Do not clamp margin here! We need TRUE EBIT to accurately calculate Interest Coverage Ratio later.
        $margin = (float) $stock->getOperatingMargin();
        $ebit = $revenue * $margin;

        // Failsafe for zero debt companies
        if ($debt <= 0.0) {
            return [
                'interest_expense' => 0.0,
                'blended_rate' => 0.0,
                'historical_fixed_rate' => (float) $stock->getHistoricalFixedRate(),
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
            // Failsafe: Prevent the Junk Bond Death Spiral during a cyclical earnings miss.
            // Bond markets will underwrite leverage based on a normalized worst-case margin (8% of revenue) rather than instantaneous negative EBIT.
            $normalizedEbit = max($ebit, $revenue * 0.08);
            // Cap the mathematically evaluated leverage ratio at 15.0 to prevent exp() blowouts.
            $leverageRatio = $normalizedEbit > 0 ? min(15.0, $netDebt / $normalizedEbit) : 15.0;
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

        $trueBlendedRate = $debt > 0 ? ($interestExpense / $debt) : 0.0;

        return [
            'interest_expense' => $interestExpense,
            'blended_rate' => $trueBlendedRate,
            'historical_fixed_rate' => $blendedFixedRate,
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
        $marketCap = (float) $stock->getPrice() * max(1.0, (float) $stock->getSharesOutstanding());
        $policyRate = $macroState['policy_rate_ema'] ?? $macroState['policy_rate'] ?? 0.04;
        $yield10y = $macroState['yield_10y'] ?? $policyRate; // Long-term risk-free rate for WACC
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

        // For Beta Levering and WACC weights, we MUST use Market Value of Equity, not Book Value!
        $debtToEquity = $marketCap > 0 ? ($currentDebt / $marketCap) : 2.5;

        // Standard CAPM breaks down during insolvency. Cap D/E at 2.5 for beta math to prevent runaway WACC.
        $effectiveDebtToEquity = min(2.5, $debtToEquity);

        // The $baseBeta from the DB already partially accounts for historical leverage. 
        // Dampen the Hamada equation multiplier (* 0.25) so we don't double-count the debt risk!
        $leveredBeta = $baseBeta * (1.0 + ((1.0 - $corporateTaxRate) * ($effectiveDebtToEquity * 0.25)));

        // Cost of Equity (CAPM) - Must use the 10-Year Yield as the Risk-Free Rate!
        $equityRiskPremium = $macroState['equity_risk_premium'] ?? 0.045;
        $costOfEquity = $yield10y + ($leveredBeta * $equityRiskPremium);

        // Weighted Average Cost of Capital (WACC)
        $totalCapital = $currentDebt + $marketCap;
        $weightEquity = $totalCapital > 0 ? ($marketCap / $totalCapital) : 1.0;
        $weightDebt = $totalCapital > 0 ? ($currentDebt / $totalCapital) : 0.0;
        $wacc = ($weightEquity * $costOfEquity) + ($weightDebt * $effectiveCostOfDebt);

        // Cash Yield & Arbitrage Hurdle (Money Market Funds)
        $yieldOnCash = max(0.0, $policyRate - self::CASH_YIELD_SPREAD);

        // "Negative Carry" means it costs more to hold the debt than the cash is earning in the bank
        // Scale the negative carry panic threshold by the sector's structural leverage tolerance.
        // A base 3.0 threshold = 1.0x multiplier (300 bps). Financials (6.0) = 2.0x multiplier (600 bps). Tech (2.0) = 0.66x (200 bps).
        $leverageThreshold = $this->getSectorLeverageThreshold($stock->getSector());
        $hurdleMultiplier = $leverageThreshold / 3.0;
        $hurdle = self::ARBITRAGE_HURDLE * $hurdleMultiplier;

        // Compare Gross to Gross to avoid tax illusions (interest income on cash is also taxable)
        $isSevereNegativeCarry = $grossCostOfDebt > ($yieldOnCash + $hurdle);

        // Interest Coverage Ratio (ICR)
        $ebit = $debtMetrics['ebit'];
        $interestExpense = $debtMetrics['interest_expense'];

        // If a company has zero debt, their ICR is excellent (999.0), UNLESS they are bleeding cash (EBIT < 0).
        $interestCoverage = $interestExpense > 0 ? ($ebit / $interestExpense) : ($ebit > 0 ? 999.0 : -999.0);

        // Strategic Debt Flags for the CFO AI
        $wantsToPaydownDebt = ($currentDebt > 0) && ($isSevereNegativeCarry || $interestCoverage < self::MIN_INTEREST_COVERAGE_RATIO);

        // M&A / CapEx Borrowing Capacity should purely be based on income statement health (ICR), not negative carry!
        // A CFO will gladly accept negative carry on idle cash if they are borrowing to immediately fund a 20% ROIC expansion.
        $canIssueDebt = $interestCoverage >= (self::MIN_INTEREST_COVERAGE_RATIO + 1.5);

        // Macro-Economic CFO Tolerance (How much debt are they comfortable holding?)
        // Base tolerance is dictated by the sector's structural leverage capacity (e.g., Utilities ~3.25 D/E, Tech ~1.0 D/E)
        $baseSectorToleranceDE = $leverageThreshold / 2.0;
        // Tolerance drops if interest rates are punishingly high (Multiplier softened to 7.5 to prevent extreme deleveraging in normal rate environments)
        $macroDebtTolerance = min($baseSectorToleranceDE, max(0.10, $baseSectorToleranceDE - ($effectiveCostOfDebt * 7.5)));

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
            'Utilities', 'Real Estate' => 6.5,

            // Asset heavy, relatively stable cash flows
            'Industrials', 'Materials', 'Energy', 'Consumer Staples' => 3.5,

            // Moderate cycle sensitivity
            'Health Care', 'Communication Services' => 3.0,

            // Highly volatile, cyclical, or asset-light. Debt is dangerous here.
            'Consumer Discretionary', 'Information Technology' => 2.0,

            // Financials are a special case (they are naturally highly levered)
            'Financials' => 6.0,

            default => 3.0,
        };
    }

    /**
     * Calculates the Altman Z-Score for corporate bankruptcy prediction.
     * 
     * @return array{z_score: float, zone: string, is_bankrupt: bool}
     */
    public function calculateAltmanZScore(Stock $stock, float $ebit, float $revenue, float $currentPrice): array
    {
        $equity = (float) $stock->getTotalEquity();
        $debt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();
        $retainedEarnings = (float) $stock->getRetainedEarnings();
        $shares = max(1.0, (float) $stock->getSharesOutstanding());

        // Accounting Equation: Assets = Liabilities + Equity
        $totalAssets = max(1.0, $equity + $debt);
        $marketCap = $currentPrice * $shares;

        // Estimate Working Capital (Treasury Cash minus an assumed 20% short-term current portion of debt)
        $currentLiabilities = $debt * 0.20;
        $workingCapital = $treasury - $currentLiabilities;

        // The 5 Z-Score Ratios
        $x1 = $workingCapital / $totalAssets;
        $x2 = $retainedEarnings / $totalAssets;
        $x3 = $ebit / $totalAssets;
        $x4 = $debt > 0 ? ($marketCap / $debt) : 10.0; // Cap at 10 to prevent infinity for zero-debt companies
        $x5 = $revenue / $totalAssets;

        // The Z-Score Formula
        $zScore = (1.2 * $x1) + (1.4 * $x2) + (3.3 * $x3) + (0.6 * $x4) + (1.0 * $x5);

        $zone = 'Safe';
        if ($zScore < 1.81) {
            $zone = 'Distress';
        } elseif ($zScore < 2.99) {
            $zone = 'Grey';
        }

        return [
            'z_score' => $zScore,
            'zone' => $zone,
            // Trigger actual bankruptcy if it falls deep into the abyss (e.g., < 0.50)
            'is_bankrupt' => $zScore < 0.50
        ];
    }
}
