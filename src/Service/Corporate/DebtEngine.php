<?php

namespace App\Service\Corporate;

use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;

class DebtEngine
{
    // Maturity Wall Constants
    private const QUARTERLY_DEBT_TURNOVER = 0.05; // 5% of old debt expires every quarter (5-year average maturity)

    // Debt Analysis Constants
    private const ARBITRAGE_HURDLE = 0.030; // 300 bps spread is severe

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

    public function __construct(
        private MathUtility $mathUtility,
        private CorporateMetrics $corporateMetrics
    ) {}

    /**
     * Calculates the gross and blended interest expenses for a company's debt structure.
     *
     * Applies the macro credit cycle, volatility risk premiums, and industry-specific
     * leverage penalties (Junk Bond blowouts) to determine the true cost of debt.
     *
     * @param Stock $stock           The stock entity being analyzed.
     * @param array &$macroState      The current macroeconomic state.
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
    public function calculateInterestExpense(Stock $stock, array &$macroState, bool $advanceMaturity = false): array
    {
        $debt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        $yield5y = $macroState['yield_5y_ema'] ?? ($macroState['yield_5y'] ?? $policyRate + 0.005);

        $rawCreditSpread = (float) $stock->getCreditSpread();
        $volatility = (float) $stock->getCurrentVolatility() ?: (float) $stock->getVolatility();

        $rawBeta = (float) $stock->getBeta();

        // THE MACROECONOMIC CREDIT CYCLE
        // Spreads widen during recessions (negative gap) as lenders panic, and tighten during booms.
        // High-beta (cyclical) stocks see their spreads widen much faster than low-beta (defensive) stocks.
        $betaSensitivity = $rawBeta >= 0.0 ? max(0.5, $rawBeta) : min(-0.5, $rawBeta);

        // Use the aggregate Macro Credit Spread (excess over the 200bps baseline)
        $aggregateCreditSpread = $macroState['macro_credit_spread_ema'] ?? 0.02;
        $macroCreditExcess = max(0.0, $aggregateCreditSpread - 0.02);

        // High beta stocks suffer the full brunt (or more) of credit market blowouts
        $macroCreditAdjustment = $macroCreditExcess * abs($betaSensitivity);

        // IDIOSYNCRATIC VOLATILITY PREMIUM
        // Bondholders hate individual uncertainty. High stock volatility pays a risk premium.
        $volatilityPremium = max(0.0, ($volatility - 0.20) * 0.02);

        // Calculate the Dynamic Baseline Spread
        // Floored at 15 bps (0.0015) so ultra-safe Titans don't get negative spreads during massive economic booms.
        $baselineCreditSpread = max(0.0015, $rawCreditSpread + $macroCreditAdjustment + $volatilityPremium);

        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($stock->getCeoArchetype());
        $baselineCreditSpread = $archetypeStrategy->modifyCreditSpread($baselineCreditSpread);

        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);

        $revenue = (float) $stock->getTotalRevenue();

        if ($revenue <= 0.0) {
            $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);

            $targetMetrics = $strategy->getTargetMetrics($stock, $macroState, $this->mathUtility);
            $investedCapital = $targetMetrics['invested_capital'];
            $baselineRoic = max(0.01, (float) $targetMetrics['baseline_roic']);
            $marginFallback = max(0.01, (float) $stock->getOperatingMargin());
            $assetTurnover = $baselineRoic / $marginFallback;
            $revenue = $investedCapital * $assetTurnover;
        }

        $margin = (float) $stock->getOperatingMargin();
        $ebit = $revenue * $margin;

        // Calculate Depreciation to find true Cash Flow (EBITDA)
        $customDepreciation = (float) $stock->getDepreciationRate();
        $depreciationRate = $customDepreciation > 0.0 ? $customDepreciation : $this->corporateMetrics->getIndustryDepreciationRate($industry);

        $physicalCapital = $isFinancial ? (float) $stock->getTotalEquity() : $stock->getInvestedCapital();
        $depreciation = $physicalCapital * $depreciationRate;
        $ebitda = $ebit + $depreciation;

        if ($debt <= 0.0) {
            return [
                'interest_expense' => 0.0,
                'blended_rate' => 0.0,
                'historical_fixed_rate' => (float) $stock->getHistoricalFixedRate(),
                'dynamic_spread' => $baselineCreditSpread,
                'current_market_rate' => $yield5y + $baselineCreditSpread,
                'wholesale_rate' => $yield5y + $baselineCreditSpread,
                'ebit' => $ebit,
                'revenue' => $revenue,
                'depreciation' => $depreciation,
                'ebitda' => $ebitda
            ];
        }

        $netDebt = max(0.0, $debt - $treasury);
        $totalEquity = (float) $stock->getTotalEquity();

        // If a company is burning cash (negative EBITDA), immediately apply maximum leverage penalties.
        if ($ebitda <= 0.0) {
            $debtToEbitda = self::MAX_LEVERAGE_RATIO;
        } else {
            $debtToEbitda = min(self::MAX_LEVERAGE_RATIO, $netDebt / $ebitda);
        }

        $debtToEquity = $totalEquity > 0 ? min(self::MAX_LEVERAGE_RATIO, $debt / $totalEquity) : self::MAX_LEVERAGE_RATIO;

        // Fetch our Dual Constraints
        $metrics = \App\Data\Sectors::INDUSTRY_METRICS[$industry] ?? \App\Data\Sectors::INDUSTRY_METRICS['General'];
        $ebitdaLimit = $metrics['ebitda_limit'];
        $equityLimit = $metrics['equity_limit'];

        // THE JUNK BOND BLOWOUT (Convex Penalty)
        $leveragePenalty = 0.0;

        if ($isFinancial) {
            // Financial companies (Banks, Insurance, Brokerages, Asset Managers) evaluated solely on Debt/Equity
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

        // Fixed-rate corporate debt is priced off the 5-Year Yield curve, not the overnight Policy Rate
        $currentMarketFixedRate = $yield5y + $dynamicSpread;

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

        // Customer Deposits & Leverage Physics
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $expenseMetrics = $strategy->calculateInterestExpenseAndWholesaleRate($stock, $blendedFixedRate, $floatingInterestRate, $currentMarketFixedRate, $policyRate, $equityLimit, $totalEquity, $debt);
        $interestExpense = $expenseMetrics['interest_expense'];
        $wholesaleRate = $expenseMetrics['wholesale_rate'];

        $trueBlendedRate = $debt > 0 ? ($interestExpense / $debt) : 0.0;

        return [
            'interest_expense' => $interestExpense,
            'blended_rate' => $trueBlendedRate,
            'historical_fixed_rate' => $blendedFixedRate,
            'dynamic_spread' => $dynamicSpread,
            'current_market_rate' => $currentMarketFixedRate,
            'wholesale_rate' => $wholesaleRate,
            'ebit' => $ebit,
            'revenue' => $revenue,
            'depreciation' => $depreciation,
            'ebitda' => $ebitda
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
     * @param array &$macroState The current macroeconomic state.
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
    public function analyzeDebtHealth(Stock $stock, array &$macroState): array
    {
        $currentDebt = (float) $stock->getTotalDebt();
        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $equity = (float) $stock->getTotalEquity();
        $marketCap = (float) $stock->getPrice() * max(1.0, (float) $stock->getSharesOutstanding());
        $policyRate = $macroState['policy_rate_ema'] ?? $macroState['policy_rate'] ?? 0.04;
        $corporateTaxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;


        $industry = $stock->getIndustry() ?: 'General';
        $metrics = \App\Data\Sectors::INDUSTRY_METRICS[$industry] ?? \App\Data\Sectors::INDUSTRY_METRICS['General'];
        $businessModel = $metrics['business_model'] ?? 'none';

        $debtMetrics = $this->calculateInterestExpense($stock, $macroState, false);

        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);

        $ebit = $debtMetrics['ebit'];
        $interestExpense = $debtMetrics['interest_expense'];

        // Gross Cost
        if ($isFinancial) {
            // WACC and financial leverage should reflect the cost of wholesale capital markets, not checking accounts
            $grossCostOfDebt = $debtMetrics['wholesale_rate'];
            $totalInterestCost = $grossCostOfDebt * $wholesaleDebt;
            $evalDebt = max(1.0, $wholesaleDebt);
        } else {
            $grossCostOfDebt = $currentDebt > 0
                ? $interestExpense / $currentDebt
                : $debtMetrics['current_market_rate'];
            $totalInterestCost = $interestExpense;
            $evalDebt = max(1.0, $currentDebt);
        }

        // 1. DYNAMIC TAX SHIELD (Phantom Tax Shield Fix)
        // A company only receives a tax shield on its debt if it actually pays taxes.
        $taxesWithoutDebt = max(0.0, $ebit) * $corporateTaxRate;
        $taxesWithDebt = max(0.0, $ebit - $totalInterestCost) * $corporateTaxRate;
        $taxSavings = $taxesWithoutDebt - $taxesWithDebt;

        $impliedTaxShieldRate = $totalInterestCost > 0 ? ($taxSavings / $totalInterestCost) : ($ebit > 0 ? $corporateTaxRate : 0.0);

        if ($currentDebt > 0 || $wholesaleDebt > 0) {
            $effectiveCostOfDebt = ($totalInterestCost - $taxSavings) / $evalDebt;
        } else {
            $effectiveCostOfDebt = $grossCostOfDebt * (1.0 - $corporateTaxRate);
        }

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
            // 2. HAMADA EQUATION RISK UN-DAMPENER
            // Unprofitable companies get no tax dampening on their Beta!
            $leveredBeta = $this->mathUtility->calculateLeveredBeta($baseBeta, $impliedTaxShieldRate, $effectiveDebtToEquity, self::HAMADA_DAMPENING_FACTOR);
        }

        // Cost of Equity (CAPM) - Unified to Policy Rate to perfectly match MarketEngine valuation physics
        $equityRiskPremium = $macroState['equity_risk_premium'] ?? MacroEngine::BASE_EQUITY_RISK_PREMIUM;
        $costOfEquity = $this->mathUtility->calculateCAPM($policyRate, $leveredBeta, $equityRiskPremium);

        // Weighted Average Cost of Capital (WACC)
        $totalCapital = $netDebtCapital + $marketCap;

        $weightEquity = $totalCapital > 0 ? ($marketCap / $totalCapital) : 1.0;
        $weightDebt = $totalCapital > 0 ? ($netDebtCapital / $totalCapital) : 0.0;
        $baseWacc = $this->mathUtility->calculateWACC($weightEquity, $costOfEquity, $weightDebt, $effectiveCostOfDebt);

        $modelThresholds = \App\Data\Sectors::getModelThresholds($businessModel);
        $minIcr = $modelThresholds['min_icr'];

        // DISTRESS PENALTY
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);

        // For financial institutions, interest income generated by their treasury/float 
        // is a core component of their operating revenue. We must add it to EBIT to calculate true coverage.
        if ($isFinancial) {
            $interestIncome = $strategy->calculateInterestIncome($stock, $macroState, $this->mathUtility);
            $ebit += $interestIncome;
        }

        $depreciation = $debtMetrics['depreciation'] ?? 0.0;
        $interestCoverage = $strategy->getInterestCoverage($ebit, $interestExpense, $depreciation);

        // Check if the company has a massive cash hoard to weather the storm
        $operatingBase = $this->corporateMetrics->calculateOperatingBase((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity());
        $minOperatingCash = $strategy->calculateMinOperatingCash($operatingBase, (float) $stock->getCustomerDeposits(), (float) $stock->getWholesaleDebt());
        $hasCashBuffer = ((float) $stock->getCorporateTreasury()) > ($minOperatingCash * 1.5);

        $distressPremium = 0.0;
        if ($interestCoverage < $minIcr && $interestCoverage >= 0) {
            $maxPenalty = $hasCashBuffer ? 0.05 : 0.10;
            $penaltyMultiplier = $maxPenalty / max(0.01, $minIcr);
            $distressPremium = ($minIcr - $interestCoverage) * $penaltyMultiplier;
        } elseif ($interestCoverage < 0) {
            // Milder penalty if they have cash to survive the negative quarter
            $distressPremium = $hasCashBuffer ? 0.05 : 0.15;
        }

        $wacc = $baseWacc + $distressPremium;

        if ($isFinancial) {
            // Financial institutions use Cost of Equity as their hurdle rate, so it must also suffer the distress penalty!
            $costOfEquity += $distressPremium;
        }

        $yieldOnCash = $strategy->calculateCashYield($macroState, $policyRate);


        // Fetch the CFO's target Debt-to-Equity limit
        $equityLimit = $metrics['equity_limit'];

        // Scale the negative carry panic threshold by the sector's structural equity leverage tolerance.
        // A normal company (1.0) = 1.0x multiplier. Financials (9.0) = 9.0x multiplier (Banks do not care about negative carry!)
        $hurdleMultiplier = max(1.0, $equityLimit / 1.0);
        $hurdle = self::ARBITRAGE_HURDLE * $hurdleMultiplier;

        // 4. TAX-FREE CASH YIELDS FOR ZOMBIES
        // If a company is unprofitable, they have Net Operating Losses (NOLs) that shield interest income from taxes.
        $effectiveYieldTaxRate = $ebit > 0 ? $corporateTaxRate : 0.0;
        $effectiveYieldOnCash = $yieldOnCash * (1.0 - $effectiveYieldTaxRate);

        $isSevereNegativeCarry = $effectiveCostOfDebt > ($effectiveYieldOnCash + $hurdle);
        $isNegativeCarry = $effectiveCostOfDebt > $effectiveYieldOnCash;

        // A company should only execute an Arbitrage Paydown if the debt is bleeding them via negative carry.
        // A drop in earnings (low ICR) should NEVER cause a company to burn its precious liquidity buffer to pay off cheap principal!
        // They must hold the cash to weather the recession.
        $wantsToPaydownDebt = ($wholesaleDebt > 0) && $isSevereNegativeCarry;

        $icrBuffer = $isFinancial ? 0.05 : 1.5;
        $canIssueDebt = $interestCoverage >= ($minIcr + $icrBuffer);

        // Macro-Economic CFO Tolerance
        // Pass the pure D/E target limit and effective cost of debt to the CFO to calculate their personalized elasticity
        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($stock->getCeoArchetype());
        $macroDebtTolerance = $archetypeStrategy->modifyDebtToleranceLimit($equityLimit, $effectiveCostOfDebt);

        $currentDebtRatio = $currentDebt / max(1.0, $equity);
        $isUnderLeveraged = $currentDebtRatio < ($macroDebtTolerance * 0.60);

        $isLiquidityCrisis = $interestCoverage < 0;
        $isLiquidityWarning = $interestCoverage >= 0 && $interestCoverage < $minIcr;

        return [
            'gross_cost' => $grossCostOfDebt,
            'effective_cost' => $effectiveCostOfDebt,
            'cash_yield' => $yieldOnCash,
            'is_negative_carry' => $isNegativeCarry,
            'is_severe_negative_carry' => $isSevereNegativeCarry,
            'interest_coverage' => $interestCoverage,
            'wants_to_paydown_debt' => $wantsToPaydownDebt,
            'can_issue_debt' => $canIssueDebt,
            'debt_tolerance' => $macroDebtTolerance,
            'wacc' => $wacc,
            'cost_of_equity' => $costOfEquity,
            'levered_beta' => $leveredBeta,
            'raw_metrics' => $debtMetrics,
            'is_liquidity_crisis' => $isLiquidityCrisis,
            'is_liquidity_warning' => $isLiquidityWarning,
            'is_under_leveraged' => $isUnderLeveraged
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
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);

        if ($isFinancial || $businessModel === 'reit') {
            $capitalRatio = $equity / $totalAssets;
            $zScore = max(-100.0, min(100.0, $capitalRatio * 100.0)); // Convert to percentage points (e.g., 8% capital = 8.0 score)

            $modelThresholds = \App\Data\Sectors::getModelThresholds($businessModel);
            $distressThreshold = $modelThresholds['distress_equity'];
            $warningThreshold = $modelThresholds['warning_equity'];
            $bankruptThreshold = $modelThresholds['bankrupt_equity'];

            $zone = 'Safe';
            if ($zScore < $distressThreshold) {
                $zone = 'Distress';
            } elseif ($zScore < $warningThreshold) {
                $zone = 'Grey';
            }

            return [
                'z_score' => $zScore,
                'zone' => $zone,
                'is_bankrupt' => $zScore < $bankruptThreshold
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
        $zScore = max(-100.0, min(100.0, $zScore));

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

    /**
     * Issues new wholesale debt and recalculates the blended historical fixed rate.
     * 
     * @param Stock $stock         The stock entity issuing debt.
     * @param float $amountIssued  The amount of new debt issued.
     * @param float $costOfNewDebt The fixed interest rate for the newly issued debt.
     */
    public function issueDebt(Stock $stock, float $amountIssued, float $costOfNewDebt): void
    {
        if ($amountIssued <= 0.0) return;

        $currentWholesaleDebt = (float) $stock->getWholesaleDebt();
        $newWholesaleDebt = $currentWholesaleDebt + $amountIssued;

        $oldHistoricalRate = (float) $stock->getHistoricalFixedRate();
        $weightedRate = (($currentWholesaleDebt * $oldHistoricalRate) + ($amountIssued * $costOfNewDebt)) / $newWholesaleDebt;

        $stock->setHistoricalFixedRate((string) $weightedRate);
        $stock->setWholesaleDebt((string) $newWholesaleDebt);
    }
}
