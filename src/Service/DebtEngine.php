<?php

namespace App\Service;

use App\Entity\Stock;

class DebtEngine
{
    // Maturity Wall Constants
    private const QUARTERLY_DEBT_TURNOVER = 0.05; // 5% of old debt expires every quarter (5-year average maturity)

    // Debt Analysis Constants
    private const ARBITRAGE_HURDLE = 0.030; // 300 bps spread is severe
    private const MIN_INTEREST_COVERAGE_RATIO = 2.0;

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
    private const ACCELERATED_DEBT_TURNOVER = 0.15; // 15% of debt retired per quarter if refinancing

    // ICR Bounds
    private const LEVERAGED_INDUSTRY_MIN_ICR = 1.25;

    public function __construct(
        private MathUtility $mathUtility
    ) {}

    /**
     * Calculates the gross and blended interest expenses for a company's debt structure.
     *
     * Applies the macro credit cycle, volatility risk premiums, and industry-specific
     * leverage penalties (Junk Bond blowouts) to determine the true cost of debt.
     *
     * @param Stock $stock           The stock entity being analyzed.
     * @param array $macroState      The current macroeconomic state.
     * @param bool  $advanceMaturity Whether to advance the maturity wall and lock in new blended rates.
     * @return array{
     *     interest_expense: float,
     *     blended_rate: float,
     *     historical_fixed_rate: float,
     *     dynamic_spread: float,
     *     current_market_rate: float,
     *     wholesale_rate: float,
     *     ebit: float,
     *     revenue: float
     * }
     */
    public function calculateInterestExpense(Stock $stock, array $macroState, bool $advanceMaturity = false): array
    {
        $debt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;

        $rawCreditSpread = (float) $stock->getCreditSpread();
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $volatility = (float) $stock->getCurrentVolatility() ?: (float) $stock->getVolatility();
        
        $rawBeta = (float) $stock->getBeta();

        // THE MACROECONOMIC CREDIT CYCLE
        // Spreads widen during recessions (negative gap) as lenders panic, and tighten during booms.
        // High-beta (cyclical) stocks see their spreads widen much faster than low-beta (defensive) stocks.
        $betaSensitivity = $rawBeta >= 0.0 ? max(0.5, $rawBeta) : min(-0.5, $rawBeta);
        $macroCreditAdjustment = -$outputGap * 0.10 * $betaSensitivity;
        
        // THE VOLATILITY RISK PREMIUM
        // Bondholders hate uncertainty. Companies with high stock volatility pay a risk premium.
        // Volatility above 20% starts adding to the spread (e.g., 40% vol adds 40 bps).
        $volatilityPremium = max(0.0, ($volatility - 0.20) * 0.02);
        
        // Calculate the Dynamic Baseline Spread
        // Floored at 15 bps (0.0015) so ultra-safe Titans don't get negative spreads during massive economic booms.
        $baselineCreditSpread = max(0.0015, $rawCreditSpread + $macroCreditAdjustment + $volatilityPremium);

        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $industry = $stock->getIndustry() ?: 'General';
        $leverageType = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['leverage_type'] ?? 'none';
        $isLeveraged = $leverageType !== 'none';

        $revenue = (float) $stock->getTotalRevenue();

        if ($revenue <= 0.0) {
            $investedCapital = $isLeveraged ? (float) $stock->getTotalEquity() : $stock->getInvestedCapital();
            
            if ($isLeveraged) {
                $equity = (float) $stock->getTotalEquity();
                $targetNetIncome = $equity * max(0.01, (float) $stock->getBaselineRoe());
                $baselineRoic = $equity > 0 ? ($targetNetIncome / $equity) : 0.01;
            } else {
                $baselineRoic = (float) $stock->getBaselineRoic();
            }
            
            $baselineRoic = max(0.01, $baselineRoic);
            $marginFallback = max(0.01, (float) $stock->getOperatingMargin());
            $assetTurnover = $baselineRoic / $marginFallback;
            $revenue = $investedCapital * $assetTurnover;
        }

        $margin = (float) $stock->getOperatingMargin();
        $ebit = $revenue * $margin;

        // Calculate Depreciation to find true Cash Flow (EBITDA)
        $customDepreciation = (float) $stock->getDepreciationRate();
        $depreciationRate = $customDepreciation > 0.0 ? $customDepreciation : $this->mathUtility->getIndustryDepreciationRate($industry);
        
        $physicalCapital = $isLeveraged ? (float) $stock->getTotalEquity() : $stock->getInvestedCapital();
        $depreciation = $physicalCapital * $depreciationRate;
        $ebitda = $ebit + $depreciation;

        if ($debt <= 0.0) {
            return [
                'interest_expense' => 0.0,
                'blended_rate' => 0.0,
                'historical_fixed_rate' => (float) $stock->getHistoricalFixedRate(),
                'dynamic_spread' => $baselineCreditSpread,
                'current_market_rate' => $policyRate + $baselineCreditSpread,
                'wholesale_rate' => $policyRate + $baselineCreditSpread,
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

        if ($isLeveraged) {
            // Leveraged industries (Banks, Insurance, Brokerages) evaluated solely on Debt/Equity
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
        
        // CUSTOMER DEPOSIT & LEVERAGE PHYSICS
        if ($leverageType === 'commercial_bank') {
            $customerDeposits = (float) $stock->getCustomerDeposits();
            $wholesaleDebt = (float) $stock->getWholesaleDebt();
            
            $wholesaleInterest = ($wholesaleDebt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($wholesaleDebt * $floatingRatio * $floatingInterestRate);
            $wholesaleRate = $wholesaleDebt > 0 ? ($wholesaleInterest / $wholesaleDebt) : $currentMarketFixedRate;
            
            // Banks must pay APY
            $depositBeta = $this->mathUtility->calculateDepositBeta($debt, $totalEquity, $equityLimit, $customerDeposits);
            $depositRate = max(0.001, $policyRate * $depositBeta);
            $depositInterest = $customerDeposits * $depositRate;
            
            $interestExpense = $wholesaleInterest + $depositInterest;

        } elseif ($leverageType === 'insurance') {
            $floatDebt = (float) $stock->getCustomerDeposits(); 
            $corporateDebt = (float) $stock->getWholesaleDebt();

            // The Float is a true 0% interest loan. They only pay interest on Corporate Debt.
            $interestExpense = ($corporateDebt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($corporateDebt * $floatingRatio * $floatingInterestRate);
            $wholesaleRate = $corporateDebt > 0 ? ($interestExpense / $corporateDebt) : $currentMarketFixedRate;

        } else {
            // Normal companies & Brokerages pay standard market rates on all debt
            $interestExpense = ($debt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($debt * $floatingRatio * $floatingInterestRate);
            $wholesaleRate = $debt > 0 ? ($interestExpense / $debt) : $currentMarketFixedRate;
        }

        $trueBlendedRate = $debt > 0 ? ($interestExpense / $debt) : 0.0;

        return [
            'interest_expense' => $interestExpense,
            'blended_rate' => $trueBlendedRate,
            'historical_fixed_rate' => $blendedFixedRate,
            'dynamic_spread' => $dynamicSpread,
            'current_market_rate' => $currentMarketFixedRate,
            'wholesale_rate' => $wholesaleRate,
            'ebit' => $ebit,
            'revenue' => $revenue
        ];
    }

    /**
     * Analyzes the overarching debt health and capital structure of the company.
     *
     * Determines the Weighted Average Cost of Capital (WACC), Cost of Equity (CAPM),
     * Levered Beta (Hamada Equation), and evaluates if the company is in a liquidity
     * crisis or suffering from negative carry.
     *
     * @param Stock $stock      The stock entity being analyzed.
     * @param array $macroState The current macroeconomic state.
     * @return array{
     *     gross_cost: float,
     *     effective_cost: float,
     *     cash_yield: float,
     *     is_severe_negative_carry: bool,
     *     interest_coverage: float,
     *     wants_to_paydown_debt: bool,
     *     can_issue_debt: bool,
     *     debt_tolerance: float,
     *     wacc: float,
     *     cost_of_equity: float,
     *     levered_beta: float,
     *     raw_metrics: array
     * }
     */
    public function analyzeDebtHealth(Stock $stock, array $macroState): array
    {
        $currentDebt = (float) $stock->getTotalDebt();
        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $equity = (float) $stock->getTotalEquity();
        $marketCap = (float) $stock->getPrice() * max(1.0, (float) $stock->getSharesOutstanding());
        $policyRate = $macroState['policy_rate_ema'] ?? $macroState['policy_rate'] ?? 0.04;
        $corporateTaxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;

        
        $industry = $stock->getIndustry() ?: 'General';
        $metrics = \App\Data\Sectors::INDUSTRY_METRICS[$industry] ?? \App\Data\Sectors::INDUSTRY_METRICS['General'];
        $leverageType = $metrics['leverage_type'] ?? 'none';

        $debtMetrics = $this->calculateInterestExpense($stock, $macroState, false);

        $isFinancial = $leverageType !== 'none';


        // Gross Cost
        if ($isFinancial) {
            // WACC and financial leverage should reflect the cost of wholesale capital markets, not checking accounts
            $grossCostOfDebt = $debtMetrics['wholesale_rate'];
        } else {
            $grossCostOfDebt = $currentDebt > 0
                ? $debtMetrics['interest_expense'] / $currentDebt
                : $debtMetrics['current_market_rate'];
        }

        // Tax Shield (Interest payments reduce taxable income)
        $effectiveCostOfDebt = $grossCostOfDebt * (1.0 - $corporateTaxRate);

        // CALCULATE NET DEBT FIRST
        $treasury = (float) $stock->getCorporateTreasury();
        
        // For financials, Customer Deposits are operating liabilities (inventory), not capital structure financing.
        // We evaluate true financial leverage (for Beta and WACC) strictly using Wholesale Debt.
        $netDebtCapital = $isFinancial ? $wholesaleDebt : max(0.0, $currentDebt - $treasury);

        // Levered Beta (The Penalty for Greed)
        // Use abs() to capture high inverse volatility, floored at 0.5 for baseline risk
        $baseBeta = max(0.5, abs((float) $stock->getBeta()));

        if ($isFinancial) {
            // Banks and Financials inherently price their massive structural leverage into their baseline Beta.
            // Re-levering a bank using the Hamada equation creates a Cost of Equity death spiral!
            $leveredBeta = $baseBeta;
        } else {
            // For Beta Levering and WACC weights, we MUST use Market Value of Equity, not Book Value!
            // Use Net Debt Capital so Cash Hoarders aren't penalized with fake risk.
            $debtToEquity = $marketCap > 0 ? ($netDebtCapital / $marketCap) : self::MAX_BETA_DEBT_TO_EQUITY;

            // Standard CAPM breaks down during insolvency. Cap D/E to prevent runaway WACC.
            $effectiveDebtToEquity = min(self::MAX_BETA_DEBT_TO_EQUITY, $debtToEquity);

            // The $baseBeta from the DB already partially accounts for historical leverage. 
            // Dampen the Hamada equation multiplier (* 0.25) so to not double-count the debt risk
            $leveredBeta = $this->mathUtility->calculateLeveredBeta($baseBeta, $corporateTaxRate, $effectiveDebtToEquity, self::HAMADA_DAMPENING_FACTOR);
        }

        // Cost of Equity (CAPM) - Unified to Policy Rate to perfectly match MarketEngine valuation physics
        $equityRiskPremium = $macroState['equity_risk_premium'] ?? MacroEngine::BASE_EQUITY_RISK_PREMIUM;
        $costOfEquity = $this->mathUtility->calculateCAPM($policyRate, $leveredBeta, $equityRiskPremium);

        // Weighted Average Cost of Capital (WACC)
        $totalCapital = $netDebtCapital + $marketCap;

        $weightEquity = $totalCapital > 0 ? ($marketCap / $totalCapital) : 1.0;
        $weightDebt = $totalCapital > 0 ? ($netDebtCapital / $totalCapital) : 0.0;
        $baseWacc = $this->mathUtility->calculateWACC($weightEquity, $costOfEquity, $weightDebt, $effectiveCostOfDebt);

        // Leveraged industries inherently run lower interest coverage ratios as their core business is leverage
        $minIcr = $isFinancial ? self::LEVERAGED_INDUSTRY_MIN_ICR : self::MIN_INTEREST_COVERAGE_RATIO;

        // DISTRESS PENALTY: Prevent the "Anti-Gravity" WACC loop where crashing stocks get cheaper capital
        $ebit = $debtMetrics['ebit'];
        $interestExpense = $debtMetrics['interest_expense'];
        
        if ($isFinancial) {
            $interestCoverageProxy = 999.0;
        } else {
            $interestCoverageProxy = $interestExpense > 0 ? ($ebit / $interestExpense) : 999.0;
        }

        $distressPremium = 0.0;
        if ($interestCoverageProxy < $minIcr && $interestCoverageProxy >= 0) {
            // Add up to a 10% penalty as coverage drops from the safe limit to 0
            $distressPremium = ($minIcr - $interestCoverageProxy) * 0.05;
        } elseif ($interestCoverageProxy < 0) {
            // Flat 15% penalty for companies operating with negative EBIT
            $distressPremium = 0.15;
        }

        $wacc = $baseWacc + $distressPremium;

        // Cash Yield & Arbitrage Hurdle (Money Market Funds)
        $yieldOnCash = max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);


        // Fetch the CFO's target Debt-to-Equity limit
        $equityLimit = $metrics['equity_limit'];

        // Scale the negative carry panic threshold by the sector's structural equity leverage tolerance.
        // A normal company (1.0) = 1.0x multiplier. Financials (9.0) = 9.0x multiplier (Banks do not care about negative carry!)
        $hurdleMultiplier = max(1.0, $equityLimit / 1.0);
        $hurdle = self::ARBITRAGE_HURDLE * $hurdleMultiplier;

        $effectiveYieldOnCash = $yieldOnCash * (1.0 - $corporateTaxRate);
        
        $isSevereNegativeCarry = $effectiveCostOfDebt > ($effectiveYieldOnCash + $hurdle);

        $ebit = $debtMetrics['ebit'];
        $interestExpense = $debtMetrics['interest_expense'];

        if ($isFinancial) {
            // Financial institutions pay interest using Yield/Float, not underwriting EBIT.
            $interestCoverage = 999.0;
        } else {
            $interestCoverage = $interestExpense > 0 ? ($ebit / $interestExpense) : ($ebit > 0 ? 999.0 : -999.0);
        }

        $wantsToPaydownDebt = ($wholesaleDebt > 0) && ($isSevereNegativeCarry || $interestCoverage < $minIcr);
        
        $icrBuffer = $isFinancial ? 0.05 : 1.5;
        $canIssueDebt = $interestCoverage >= ($minIcr + $icrBuffer);

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
     * 
     * Evaluates working capital, retained earnings, operating income, and equity to 
     * determine if the company is at imminent risk of insolvency.
     * 
     * @param Stock $stock        The stock entity being evaluated.
     * @param float $ebit         Earnings Before Interest and Taxes.
     * @param float $revenue      Total Revenue.
     * @param float $currentPrice Current share price.
     * @return array{z_score: float, zone: string, is_bankrupt: bool}
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
        $leverageType = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['leverage_type'] ?? 'none';
        $isLeveraged = $leverageType !== 'none';

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
