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

    // Macro Defaults
    private const DEFAULT_CORPORATE_TAX_RATE = 0.21;
    private const DEFAULT_EQUITY_RISK_PREMIUM = 0.045;

    // Leverage Physics
    private const MAX_LEVERAGE_RATIO = 15.0;     // Cap extreme D/E or D/EBITDA ratios
    private const MAX_LEVERAGE_PENALTY = 0.25;   // 25% max Junk Bond penalty spread
    private const LEVERAGE_PENALTY_RATE = 0.20;
    private const LEVERAGE_PENALTY_BASE = 0.010;

    // CAPM / Beta Limits
    private const MAX_BETA_DEBT_TO_EQUITY = 2.5; // Prevent runaway WACC in standard CAPM
    private const HAMADA_DAMPENING_FACTOR = 0.25; // Dampen double-counting of historical debt

    // Refinancing Hurdles
    private const RATE_REFINANCE_THRESHOLD = 0.015; // 150 bps drop triggers early refinancing
    private const ACCELERATED_DEBT_TURNOVER = 0.30; // 30% of debt retired per quarter if refinancing

    // ICR Bounds
    private const LEVERAGED_INDUSTRY_MIN_ICR = 1.25;

    public function __construct(
        private MathUtility $mathUtility
    ) {}

    public function calculateInterestExpense(Stock $stock, array $macroState, bool $advanceMaturity = false): array
    {
        $debt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        
        $baselineCreditSpread = (float) $stock->getCreditSpread();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $industry = $stock->getIndustry() ?: 'General';

        $revenue = (float) $stock->getTotalRevenue();

        if ($revenue <= 0.0) {
            $investedCapital = $stock->getInvestedCapital();
            $baselineRoic = max(0.01, (float) $stock->getBaselineRoic());
            $marginFallback = max(0.01, (float) $stock->getOperatingMargin());
            $assetTurnover = $baselineRoic / $marginFallback;
            $revenue = $investedCapital * $assetTurnover;
        }

        $margin = (float) $stock->getOperatingMargin();
        $ebit = $revenue * $margin;

        // Calculate Depreciation to find true Cash Flow (EBITDA)
        $depreciationRate = $this->mathUtility->getIndustryDepreciationRate($industry, (float) $stock->getDepreciationRate() ?: 0.05);
        $depreciation = $stock->getInvestedCapital() * $depreciationRate;
        $ebitda = $ebit + $depreciation;

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

        $netDebt = max(0.0, $debt - $treasury);
        $totalEquity = (float) $stock->getTotalEquity();

        // Normalize EBITDA to prevent mathematical blowouts during temporary losses
        $normalizedEbitda = max($ebitda, $revenue * 0.08);
        $debtToEbitda = $normalizedEbitda > 0 ? min(self::MAX_LEVERAGE_RATIO, $netDebt / $normalizedEbitda) : self::MAX_LEVERAGE_RATIO;
        $debtToEquity = $totalEquity > 0 ? min(self::MAX_LEVERAGE_RATIO, $debt / $totalEquity) : self::MAX_LEVERAGE_RATIO;

        // Fetch our Dual Constraints
        $metrics = \App\Data\Sectors::INDUSTRY_METRICS[$industry] ?? \App\Data\Sectors::INDUSTRY_METRICS['General'];
        $ebitdaLimit = $metrics['ebitda_limit'];
        $equityLimit = $metrics['equity_limit'];

        // THE JUNK BOND BLOWOUT (Convex Penalty)
        $leveragePenalty = 0.0;
        
        if ($metrics['leveraged_industry']) {
            // Leveraged industries (Banks, Insurance) evaluated solely on Debt/Equity
            if ($debtToEquity > $equityLimit) {
                $excessLeverage = $debtToEquity - $equityLimit;
                $leveragePenalty = (exp($excessLeverage * self::LEVERAGE_PENALTY_RATE) - 1.0) * self::LEVERAGE_PENALTY_BASE;
            }
        } else {
            // Everyone else evaluated on Debt/EBITDA
            if ($debtToEbitda > $ebitdaLimit) {
                $excessLeverage = $debtToEbitda - $ebitdaLimit;
                $leveragePenalty = (exp($excessLeverage * self::LEVERAGE_PENALTY_RATE) - 1.0) * self::LEVERAGE_PENALTY_BASE;
            }
        }

        $leveragePenalty = min(self::MAX_LEVERAGE_PENALTY, $leveragePenalty);
        $dynamicSpread = $baselineCreditSpread + $leveragePenalty;
            $currentMarketFixedRate = $policyRate + $dynamicSpread;

        $historicalRate = (float) $stock->getHistoricalFixedRate();

        if ($advanceMaturity) {
            $turnover = self::QUARTERLY_DEBT_TURNOVER;
            if ($currentMarketFixedRate < ($historicalRate - self::RATE_REFINANCE_THRESHOLD)) {
                $turnover = self::ACCELERATED_DEBT_TURNOVER; 
            }
            $blendedFixedRate = ($historicalRate * (1.0 - $turnover)) + ($currentMarketFixedRate * $turnover);
        } else {
            $blendedFixedRate = $historicalRate;
        }

        $floatingInterestRate = $policyRate + $dynamicSpread;
        $interestExpense = ($debt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($debt * $floatingRatio * $floatingInterestRate);
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
        $corporateTaxRate = $macroState['corporate_tax_rate'] ?? self::DEFAULT_CORPORATE_TAX_RATE;


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
        $debtToEquity = $marketCap > 0 ? ($currentDebt / $marketCap) : self::MAX_BETA_DEBT_TO_EQUITY;

        // Standard CAPM breaks down during insolvency. Cap D/E to prevent runaway WACC.
        $effectiveDebtToEquity = min(self::MAX_BETA_DEBT_TO_EQUITY, $debtToEquity);

        // The $baseBeta from the DB already partially accounts for historical leverage. 
        // Dampen the Hamada equation multiplier (* 0.25) so we don't double-count the debt risk!
        $leveredBeta = $this->mathUtility->calculateLeveredBeta($baseBeta, $corporateTaxRate, $effectiveDebtToEquity, self::HAMADA_DAMPENING_FACTOR);

        // Cost of Equity (CAPM) - use the 10-Year Yield as the Risk-Free Rate!
        $equityRiskPremium = $macroState['equity_risk_premium'] ?? self::DEFAULT_EQUITY_RISK_PREMIUM;
        $costOfEquity = $this->mathUtility->calculateCAPM($yield10y, $leveredBeta, $equityRiskPremium);

        // Weighted Average Cost of Capital (WACC)
        $totalCapital = $currentDebt + $marketCap;
        $weightEquity = $totalCapital > 0 ? ($marketCap / $totalCapital) : 1.0;
        $weightDebt = $totalCapital > 0 ? ($currentDebt / $totalCapital) : 0.0;
        $baseWacc = $this->mathUtility->calculateWACC($weightEquity, $costOfEquity, $weightDebt, $effectiveCostOfDebt);

        // DISTRESS PENALTY: Prevent the "Anti-Gravity" WACC loop where crashing stocks get cheaper capital
        $ebit = $debtMetrics['ebit'];
        $interestExpense = $debtMetrics['interest_expense'];
        $interestCoverageProxy = $interestExpense > 0 ? ($ebit / $interestExpense) : 999.0;
        
        $distressPremium = 0.0;
        if ($interestCoverageProxy < 2.0 && $interestCoverageProxy >= 0) {
            // Add up to a 10% penalty as coverage drops from 2.0 to 0
            $distressPremium = (2.0 - $interestCoverageProxy) * 0.05; 
        } elseif ($interestCoverageProxy < 0) {
            // Flat 15% penalty for companies operating with negative EBIT
            $distressPremium = 0.15; 
        }

        $wacc = $baseWacc + $distressPremium;

        // Cash Yield & Arbitrage Hurdle (Money Market Funds)
        $yieldOnCash = max(0.0, $policyRate - self::CASH_YIELD_SPREAD);

        $industry = $stock->getIndustry() ?: 'General';
        $metrics = \App\Data\Sectors::INDUSTRY_METRICS[$industry] ?? \App\Data\Sectors::INDUSTRY_METRICS['General'];
        // Fetch the CFO's target Debt-to-Equity limit
        $equityLimit = $metrics['equity_limit'];

        // Scale the negative carry panic threshold by the sector's structural equity leverage tolerance.
        // A normal company (1.0) = 1.0x multiplier. Financials (9.0) = 9.0x multiplier (Banks do not care about negative carry!)
        $hurdleMultiplier = max(1.0, $equityLimit / 1.0);
        $hurdle = self::ARBITRAGE_HURDLE * $hurdleMultiplier;

        $isSevereNegativeCarry = $grossCostOfDebt > ($yieldOnCash + $hurdle);

        $ebit = $debtMetrics['ebit'];
        $interestExpense = $debtMetrics['interest_expense'];

        $interestCoverage = $interestExpense > 0 ? ($ebit / $interestExpense) : ($ebit > 0 ? 999.0 : -999.0);

        // Leveraged industries inherently run lower interest coverage ratios as their core business is leverage
        $minIcr = $metrics['leveraged_industry'] ? self::LEVERAGED_INDUSTRY_MIN_ICR : self::MIN_INTEREST_COVERAGE_RATIO;

        $wantsToPaydownDebt = ($currentDebt > 0) && ($isSevereNegativeCarry || $interestCoverage < $minIcr);
        $canIssueDebt = $interestCoverage >= ($minIcr + 1.5);

        // Macro-Economic CFO Tolerance
        // Pass the pure D/E target limit to the CFO, shrinking it proportionally if rates are painfully high
        $macroDebtTolerance = min($equityLimit, max(0.10, $equityLimit * (1.0 - ($effectiveCostOfDebt * 3.0))));

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
     * Calculates the Altman Z''-Score (Double Prime) for modern, non-manufacturing corporate bankruptcy prediction.
     * * @return array{z_score: float, zone: string, is_bankrupt: bool}
     */
    public function calculateAltmanZScore(Stock $stock, float $ebit, float $revenue, float $currentPrice): array
    {
        $equity = (float) $stock->getTotalEquity();
        $debt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();
        $retainedEarnings = (float) $stock->getRetainedEarnings();
        $shares = max(1.0, (float) $stock->getSharesOutstanding());

        // Accounting Proxy: Assets = Liabilities + Equity
        $totalAssets = max(1.0, $equity + $debt);
        $marketCap = $currentPrice * $shares;

        // The Altman Z-Score explicitly excludes Financials because customer deposits (debt) skew their working capital.
        // Instead, evaluate Financials using a simplified Tier 1 Capital Ratio proxy (Equity / Total Assets).
        $industry = $stock->getIndustry() ?: 'General';
        $isLeveraged = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['leveraged_industry'] ?? false;

        if ($isLeveraged) {
            $capitalRatio = $equity / $totalAssets;
            $zScore = $capitalRatio * 100.0; // Convert to percentage points (e.g., 8% capital = 8.0 score)

            $zone = 'Safe';
            if ($zScore < 4.0) {
                $zone = 'Distress'; // Below 4% equity buffer triggers distress
            } elseif ($zScore < 6.0) {
                $zone = 'Grey'; // Between 4% and 6% is a warning zone
            }

            return [
                'z_score' => $zScore,
                'zone' => $zone,
                'is_bankrupt' => $zScore < 2.0 // Below 2% triggers regulatory seizure / bankruptcy
            ];
        }

        // Estimate Working Capital
        $currentLiabilities = $debt * 0.20;
        $workingCapital = $treasury - $currentLiabilities;

        // The 4 Z''-Score Ratios (X5 Revenue/Assets is removed for non-manufacturing)
        $x1 = $workingCapital / $totalAssets;
        $x2 = $retainedEarnings / $totalAssets;
        $x3 = $ebit / $totalAssets;
        $x4 = $debt > 0 ? ($marketCap / $debt) : 10.0; // Cap at 10

        // The Z''-Score Formula (Modern Service/Tech/Financial Weights)
        $zScore = (6.56 * $x1) + (3.26 * $x2) + (6.72 * $x3) + (1.05 * $x4);

        // Z'' has different threshold thresholds than the 1968 model
        $zone = 'Safe';
        if ($zScore < 1.10) {
            $zone = 'Distress';
        } elseif ($zScore < 2.60) {
            $zone = 'Grey';
        }

        return [
            'z_score' => $zScore,
            'zone' => $zone,
            // A negative Z'' score is a near-mathematical certainty of insolvency
            'is_bankrupt' => $zScore < 0.00
        ];
    }
}
