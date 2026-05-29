<?php

namespace App\Service;

use App\Entity\Stock;

/**
 * Handles the simulation of quarterly earnings reports.
 * Models revenue, operating leverage, analyst consensus, and corporate saturation.
 */
class EarningsEngine
{
    // Volatility Adjustment Constants
    /** @var float Z-Score threshold required to trigger an earnings surprise volatility spike. */
    private const SURPRISE_Z_SCORE_THRESHOLD = 1.5;
    /** @var float Z-Score threshold below which an earnings report is considered highly predictable, cooling volatility. */
    private const BORING_Z_SCORE_THRESHOLD = 0.5;
    /** @var float The multiplier applied to volatility when an earnings surprise occurs. */
    private const VOLATILITY_SHOCK_FACTOR = 0.2;
    /** @var float The percentage by which volatility cools after a boring report. */
    private const VOLATILITY_COOLING_FACTOR = 0.25;
    /** @var float The maximum allowable volatility multiplier from a single earnings event. */
    private const MAX_VOLATILITY_MULTIPLIER = 3.0;

    // Price Gap Constants
    /** @var float Dampens the immediate price jump/drop to prevent unrealistic fractional penny wipes. */
    private const PRICE_GAP_DAMPENING = 0.20;
    /** @var float Caps the maximum immediate price gap from a single earnings report. */
    private const MAX_PRICE_GAP = 0.25;

    // Financial Sector Asset Turnover Proxies
    private const BANK_BASE_SPREAD = 0.06;
    private const INSURANCE_PREMIUM_TO_EQUITY = 0.65;
    private const BROKERAGE_FEE_TO_EQUITY = 0.40;

    /**
     * Constructor.
     *
     * @param MarketEvent $marketEvent Publisher for all market events, news headlines, and shocks.
     * @param MathUtility $mathUtility Utility for advanced mathematical operations (e.g., generating standard normal distribution).
     */
    public function __construct(
        private \Doctrine\ORM\EntityManagerInterface $entityManager,
        private MarketEvent $marketEvent,
        private CorporateActionEngine $corporateActionEngine,
        private DebtEngine $debtEngine,
        private MathUtility $mathUtility
    ) {}

    /**
     * Calculates and processes a quarterly earnings report for a given stock.
     *
     * This method simulates the outcome of an earnings report based on a deterministic
     * schedule within the simulation's "Earnings Season". If it is the stock's turn to report, 
     * it calculates expected vs. actual earnings per share (EPS), factoring in economic cycles 
     * and statistical drift. It also adjusts the stock's volatility based on the statistical rarity 
     * (Z-Score) of the revenue shift (e.g., punishing or rewarding surprise reports).
     *
     * @param Stock $stock The stock entity to process earnings for.
     * @param array $macroState The current state of the macroeconomic cycle.
     * @param int $tickCount The current simulation tick, used to determine if it is earnings season.
     * @param int $ticksPerYear The total number of ticks in a simulated year.
     * @return array<string, mixed>|null  Returns the generated market event array if an earnings report occurred, otherwise null.
     */
    public function calculate(Stock $stock, array $macroState = [], int $tickCount = 0, int $ticksPerYear = 252): ?array
    {

        $ticksPerQuarter = (int) ($ticksPerYear / 4);

        // Define the season length
        $ticksPerSeason = (int) ($ticksPerQuarter * 0.15);

        // Where are we currently within the 3-month quarter?
        $currentQuarterTick = $tickCount % $ticksPerQuarter;

        // Are we outside the Earnings Season?
        if ($currentQuarterTick > $ticksPerSeason) {
            return null;
        }

        //  Assign this stock a permanent, deterministic reporting tick.
        $reportingTick = abs(crc32($stock->getTicker())) % max(1, $ticksPerSeason);

        // Is it this specific stock's exact turn to report
        if ($currentQuarterTick !== $reportingTick) {
            return null;
        }

        $oldAnnualEps = (float) $stock->getEarningsPerShare();
        $baselineVol = (float) $stock->getVolatility();
        $sharesOutstanding = (float) $stock->getSharesOutstanding();

        // Calculate the True Size of the Business (Invested Capital)
        $investedCapital = $stock->getInvestedCapital();
        $equity = (float) $stock->getTotalEquity();
        $cash = (float) $stock->getCorporateTreasury();
        $debt = (float) $stock->getTotalDebt();


        // STRUCTURAL COST BASE (Sticky)
        $baselineRoic = max(0.01, (float) $stock->getBaselineRoic());
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());
        
        $industry = $stock->getIndustry() ?: 'General';
        $leverageType = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['leverage_type'] ?? 'none';
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;

        if ($leverageType === 'commercial_bank') {
            // 1. COMMERCIAL BANKS (Net Interest Margin)
            $investedCapital = $equity; 
            
            $structuralSpread = (float) $stock->getCreditSpread();
            $wholesaleDebt = (float) $stock->getWholesaleDebt();
            $customerDeposits = (float) $stock->getCustomerDeposits();
            
            $wholesaleRate = $policyRate + $structuralSpread;
            $totalDebt = $wholesaleDebt + $customerDeposits;
            
            // Apply Deposit APY Beta
            $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 10.0;
            $depositBeta = $this->mathUtility->calculateDepositBeta($totalDebt, $equity, $equityLimit, $customerDeposits);
            $depositRate = max(0.001, $policyRate * $depositBeta);
            
            $structuralInterestExpense = ($wholesaleDebt * $wholesaleRate) + ($customerDeposits * $depositRate);
            
            // Apply NIM Modifier (Yield Curve)
            $yieldCurveSlope = $macroState['ns_slope'] ?? 0.0;
            $nimModifier = max(0.1, 1.0 + ($yieldCurveSlope * 10.0));
            
            $baselineRoe = max(0.01, (float) $stock->getBaselineRoe()) * $nimModifier;
            $targetNetIncome = $equity * $baselineRoe;
            $targetEbt = $targetNetIncome / (1.0 - ($macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE));
            
            $cashYield = max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
            $expectedInterestIncome = $cash * $cashYield; // Simplified for brevity
            
            $requiredEbit = max(0.01 * $equity, $targetEbt + $structuralInterestExpense - $expectedInterestIncome);
            $baselineRoic = $equity > 0 ? ($requiredEbit / $equity) : 0.01;

        } elseif ($leverageType === 'insurance') {
            // 2. INSURANCE COMPANIES (The Float)
            $investedCapital = $equity; // Evaluated on Equity
            
            // Insurance companies hold massive cash/bond reserves ("The Float").
            // High interest rates are wildly profitable for them.
            $floatYield = max(0.01, $policyRate + 0.01); // Earning Policy + 100bps
            $expectedInvestmentIncome = $cash * $floatYield;
            
            // Target Net income is driven by underwriting profit + float yield
            $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
            $targetNetIncome = $equity * $baselineRoe;
            $targetEbt = $targetNetIncome / (1.0 - ($macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE));
            
            // Operating EBIT only needs to cover the gap left by investment income
            $requiredEbit = max(0.01 * $equity, $targetEbt - $expectedInvestmentIncome);
            $baselineRoic = $equity > 0 ? ($requiredEbit / $equity) : 0.01;

        } elseif ($leverageType === 'brokerage') {
            // 3. ASSET MANAGERS & BROKERAGES (AUM Fees)
            $investedCapital = $equity; 
            
            // Asset light, high margin business. They don't use deposits or float.
            $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());
            $targetNetIncome = $equity * $baselineRoe;
            $targetEbt = $targetNetIncome / (1.0 - ($macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE));
            
            // Add their wholesale interest expense so EBIT scales to cover it!
            $wholesaleDebt = (float) $stock->getWholesaleDebt();
            $expectedInterestExpense = $wholesaleDebt * ($policyRate + (float) $stock->getCreditSpread());
            
            $requiredEbit = max(0.01 * $equity, $targetEbt + $expectedInterestExpense);
            $baselineRoic = $equity > 0 ? ($requiredEbit / $equity) : 0.01;
        }
        
        // REVENUE GENERATION PHYSICS
        if ($leverageType === 'commercial_bank') {
            // Banks have massive balance sheets but relatively low nominal revenue (Net Interest Income proxy)
            $baseSpread = self::BANK_BASE_SPREAD; 
            $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
            
            $assetTurnover = $baseSpread + $policyRate; 
            $baselineRevenue = $debt * $assetTurnover;
        } elseif ($leverageType === 'insurance') {
            // Insurance premiums scale strictly with their Equity capacity (Underwriting Limits)
            $assetTurnover = self::INSURANCE_PREMIUM_TO_EQUITY; 
            $baselineRevenue = $equity * $assetTurnover;
        } elseif ($leverageType === 'brokerage') {
            // Asset-light fee collectors scale revenue off AUM and Equity
            $assetTurnover = self::BROKERAGE_FEE_TO_EQUITY;
            $baselineRevenue = $equity * $assetTurnover;
        } else {
            // Normal physical companies use the backing-out margin math
            $assetTurnover = $baselineRoic / $stableMargin;
            $baselineRevenue = $investedCapital * $assetTurnover;
        }

        $fixedCostRatio = $stock->getFixedCostRatio();
        $structuralTotalCosts = $baselineRevenue * (1.0 - $stableMargin);
        $fixedCosts = $structuralTotalCosts * $fixedCostRatio;
        $baselineVariableCosts = $structuralTotalCosts * (1.0 - $fixedCostRatio);
        
        // The core operational reality: how much does it cost to produce one unit of revenue?
        $structuralVariableMargin = $baselineVariableCosts / max(1.0, $baselineRevenue);

        // MACROECONOMIC SHIFTS (Volume & Pricing Power)
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $beta = (float) $stock->getBeta();
        
        // Volume swings with the economy, scaled by beta. Defensive stocks (low beta) ignore the cycle.
        $macroVolumeModifier = 1.0 + ($outputGap * $beta);
        
        // Corporate Saturation: As a company captures its Addressable Market, its growth stalls.
        $saturationPenalty = $this->mathUtility->calculateMarketSaturationPenalty($stock, $investedCapital, $macroState);
        $macroVolumeModifier *= (1.0 - $saturationPenalty);

        $expectedRevenue = $baselineRevenue * $macroVolumeModifier;

        // Pricing Power: In a boom, companies raise prices. In inflation, margins are crushed.
        if ($leverageType === 'commercial_bank') {
            // Banks don't buy physical goods, so inflation doesn't crush their supply chain.
            $pricingPowerModifier = ($outputGap * $beta * 0.25);
            
        } elseif ($leverageType === 'insurance') {
            // Insurance pricing is highly regulated but sticky. Inflation hurts them 
            // slightly (cost of repairs/claims goes up), but they raise premiums to match.
            $inflationPenalty = $inflation > 0.04 ? ($inflation - 0.04) * 0.5 : 0;
            $pricingPowerModifier = ($outputGap * $beta * 0.20) - $inflationPenalty;
            
        } elseif ($leverageType === 'brokerage') {
            // Brokerage revenues (AUM fees) are directly tied to the stock market's performance.
            // If the market is booming (+ gap), their margins explode upward.
            $pricingPowerModifier = ($outputGap * $beta * 1.5);
            
        } else {
            // Normal physical companies (Supply chain inflation kills them)
            $pricingPowerModifier = ($outputGap * $beta * 0.5) - ($inflation > 0.03 ? ($inflation - 0.03) * $beta * 1.5 : 0);
        }
        
        // Variable margin shifts inversely to pricing power (higher prices = lower relative cost margin)
        $realizedVariableMargin = max(0.01, min(0.99, $structuralVariableMargin - $pricingPowerModifier));

        // Expected EBIT (Pre-Shock)
        $expectedVariableCosts = $expectedRevenue * $realizedVariableMargin;
        $expectedEbit = $expectedRevenue - $fixedCosts - $expectedVariableCosts;

        // APPLY THE IDIOSYNCRATIC Z-SCORE SHOCK (The "Earnings Surprise")
        // Shock the volume/revenue, NOT the EPS directly.
        $revenueZ = $this->mathUtility->generateStandardNormal();
        
        if ($leverageType === 'insurance') {
            // Insurance Premium Revenue is highly sticky and predictable. 
            $revenueShock = $revenueZ * ($baselineVol * 0.05); // Very low top-line variance
            $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);
            
            // CATASTROPHE PHYSICS (The Combined Ratio Shock)
            // Insurance variance comes from massive, unpredictable claim payouts.
            $claimZ = $this->mathUtility->generateStandardNormal();
            $underwritingShock = 0.0;
            
            if ($claimZ < -1.5) {
                // A 1.5+ sigma catastrophe event (Hurricanes, Mass Torts) spikes claim costs!
                $underwritingShock = abs($claimZ) * 0.15; // Adds ~22%+ to their cost margin
            } elseif ($claimZ > 1.0) {
                // A quiet quarter (no major natural disasters) means higher underwriting profits
                $underwritingShock = -0.05; 
            }
            
            $actualVariableCosts = $actualRevenue * min(1.50, ($realizedVariableMargin + $underwritingShock));
            $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;
            
        } else {
            // Normal Companies, Banks, and Brokerages take the shock directly to sales volume
            $revenueShock = $revenueZ * ($baselineVol * 0.15);
            $actualRevenue = $expectedRevenue * (1.0 + $revenueShock);
            
            $actualVariableCosts = $actualRevenue * $realizedVariableMargin;
            $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;
        }


        // Interest & Debt Physics
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;

        // Calculate EXPECTED Interest Expense (Pre-Shock)
        $expectedOperatingMargin = $expectedEbit / max(1.0, $expectedRevenue);
        $stock->setTotalRevenue((string) $expectedRevenue);
        $stock->setOperatingMargin((string) $expectedOperatingMargin);
        $expectedDebtMetrics = $this->debtEngine->calculateInterestExpense($stock, $macroState, false);
        $expectedInterestExpense = $expectedDebtMetrics['interest_expense'];

        // Calculate ACTUAL Interest Expense (Post-Shock, Advancing Maturity)
        $stock->setTotalRevenue((string) $actualRevenue);
        // Sync the true dynamic margin to the Stock Entity so DebtEngine calculates the precise Net Debt Leverage!
        $trueOperatingMargin = $ebit / max(1.0, $actualRevenue);
        $stock->setOperatingMargin((string) $trueOperatingMargin);

        try {
            $debtMetrics = $this->debtEngine->calculateInterestExpense($stock, $macroState, true);
            $actualInterestExpense = $debtMetrics['interest_expense'];

            $stock->setHistoricalFixedRate((string) $debtMetrics['historical_fixed_rate']);

            // Interest Income. 
            // Mega-hoarders generate massive risk-free yield on their cash piles!
            $operatingBase = $this->mathUtility->calculateOperatingBase((float) $stock->getTotalRevenue(), $equity);
            $workingCapital = $operatingBase * 0.05;
            $excessCash = max(0.0, $cash - $workingCapital);

            if ($leverageType === 'insurance') {
                // Insurance companies invest their massive Float in long-duration bonds.
                // They earn a premium yield on virtually ALL their cash, not just the "excess".
                $floatYield = max(0.01, $policyRate + 0.01); 
                $interestIncome = $cash * $floatYield; 
            } else {
                // Normal companies and Banks earn standard money-market yields only on excess liquidity
                $cashYield = max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
                $interestIncome = $excessCash * $cashYield;
            }

            // CALCULATE PHYSICAL DEPRECIATION (The Rusting of Assets)
            $customDepreciation = (float) $stock->getDepreciationRate();
            $depreciationRate = $customDepreciation > 0.0 ? $customDepreciation : $this->mathUtility->getIndustryDepreciationRate($industry);

            // Physical assets rust, not equity. Invested Capital perfectly isolates the physical operating base.
            $absoluteDepreciation = $investedCapital * $depreciationRate;

            // Calculate Earnings Before Tax (EBT)
            // DEPRECIATION IS AN OPERATING EXPENSE ALREADY ACCOUNTED FOR IN EBIT.
            $expectedEbt = $expectedEbit - $expectedInterestExpense + $interestIncome;
            $actualEbt = $ebit - $actualInterestExpense + $interestIncome;

            // Apply Corporate Taxes to find True Net Income
            $corporateTaxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;
            $expectedTotalNetIncome = $expectedEbt > 0 ? $expectedEbt * (1.0 - $corporateTaxRate) : $expectedEbt;
            $actualTotalNetIncome = $actualEbt > 0 ? $actualEbt * (1.0 - $corporateTaxRate) : $actualEbt;

            // UPDATE DYNAMIC ROIC AS AN OUTCOME (Moved down to access Net Income) ---
            $truePostTaxReturn = 0.0;
            if ($leverageType !== 'none') {
                // ALL financial companies (Banks, Insurance, Brokerages) are evaluated on Equity
                $truePostTaxReturn = $equity > 0 ? ($actualTotalNetIncome / $equity) : 0.0;
                
                $oldRoe = (float) $stock->getCurrentRoe();
                $smoothedRoe = $oldRoe === 0.0 ? $truePostTaxReturn : $oldRoe + (($truePostTaxReturn - $oldRoe) * 0.50);
                $stock->setCurrentRoe((string) max(-0.50, min(1.0, $smoothedRoe)));
            } else {
                // Normal companies evaluated on Invested Capital
                $nopatProxy = $ebit > 0 ? $ebit * (1.0 - $corporateTaxRate) : $ebit;
                $truePostTaxReturn = $investedCapital > 0 ? ($nopatProxy / $investedCapital) : 0.0;
                
                $oldRoic = (float) $stock->getCurrentRoic();
                $smoothedRoic = $oldRoic === 0.0 ? $truePostTaxReturn : $oldRoic + (($truePostTaxReturn - $oldRoic) * 0.50);
                $stock->setCurrentRoic((string) max(-0.50, min(1.0, $smoothedRoic)));
            }

            // Modifier scaled correctly for Annual EPS
            $expectedAnnualEps = $expectedTotalNetIncome / max(1.0, $sharesOutstanding);
            $actualAnnualEpsRaw = $actualTotalNetIncome / max(1.0, $sharesOutstanding);

            // Calculate Market Expectations (Smoothed for the Surprise/Shock generation only)
            $expectedAnnualEpsDrifted = $oldAnnualEps + (($expectedAnnualEps - $oldAnnualEps) * 0.50);
            $epsDifference = $actualAnnualEpsRaw - $expectedAnnualEps;
            $perceivedActualAnnualEps = $expectedAnnualEpsDrifted + $epsDifference;

            // Calculate Quarterly metrics for the UI and price gap logic
            $actualQuarterlyEps = $perceivedActualAnnualEps / 4.0;
            $expectedQuarterlyEps = $expectedAnnualEpsDrifted / 4.0;
            $surpriseAmountQuarterly = $actualQuarterlyEps - $expectedQuarterlyEps;

            $surprisePct = abs($expectedQuarterlyEps) > 0.01
                ? $surpriseAmountQuarterly / abs($expectedQuarterlyEps)
                : ($surpriseAmountQuarterly > 0 ? 0.10 : ($surpriseAmountQuarterly < 0 ? -0.10 : 0.0));

            // VOLATILITY SHOCK
            $this->applyVolatilityShock($stock, $revenueZ, $baselineVol);

            // Overwriting EPS alters Total Net Income. We MUST save the raw 
            // physical number to maintain a mathematically flawless Balance Sheet.
            $stock->setEarningsPerShare((string) $actualAnnualEpsRaw);

            $isLeveraged = $leverageType !== 'none';
            $fcfData = $this->calculateFreeCashFlowPerShare($actualTotalNetIncome, $sharesOutstanding, $stock, $macroState, $absoluteDepreciation, $isLeveraged);
            $annualFcfPerShare = $fcfData['fcf_per_share'];
            $actualAnnualCapEx = $fcfData['capex'];

            $priceGapPct = $this->calculatePriceGap($surprisePct);
            $currentPrice = (float) $stock->getPrice();
            $quarterlyFcfPerShare = $annualFcfPerShare / 4.0;

            // ALLOCATE CAPITAL
            $allocation = $this->corporateActionEngine->allocateCapital(
                $stock,
                $actualAnnualEpsRaw,
                $quarterlyFcfPerShare,
                $currentPrice,
                $sharesOutstanding,
                $macroState,
                $actualTotalNetIncome
            );

            $stock->setSharesOutstanding((string) $allocation['new_shares']);

            // Subtract the Growth CapEx (Organic CapEx) spent by the CEO to find True FCF
            $organicCapex = $allocation['organic_capex'] ?? 0.0;
            
            // For banks, loan book expansion is a balance sheet transaction (Cash -> Loans), not physical CapEx
            $reportedOrganicCapex = $leverageType == 'commercial_bank' ? 0.0 : $organicCapex;

            // Convert quarterly organic CapEx to an annualized per-share impact
            $annualizedOrganicCapex = $reportedOrganicCapex * 4.0;
            $organicCapexPerShare = $sharesOutstanding > 0 ? ($annualizedOrganicCapex / $sharesOutstanding) : 0.0;

            // True FCF accounts for BOTH Maintenance CapEx and Growth CapEx
            $trueAnnualFcfPerShare = $annualFcfPerShare - $organicCapexPerShare;
            $stock->setFreeCashFlowPerShare((string) $trueAnnualFcfPerShare);

            // Aggregate total shock from earnings and corporate actions
            $totalShockPct = $priceGapPct;
            $corporateActionDescriptions = "";

            if (!empty($allocation['events'])) {
                foreach ($allocation['events'] as $subEvent) {
                    $corporateActionDescriptions .= "\n• " . $subEvent['description'];
                    $totalShockPct += ($subEvent['shock'] / 100.0);
                }
            }

            // APPLY THE GAP
            $currentPrice = (float) $stock->getPrice();

            // The Circuit Breaker (Limit Up / Limit Down)
            $totalShockPct = max(-0.40, min(0.40, $totalShockPct));

            // The Dividend Ex-Date Adjustment
            // A stock's price drops by the exact dividend amount, but market physics prevent it from going to absolute zero.
            // We floor it at $0.01 to prevent fractional penny infinite reverse-split loops.
            $exDivPrice = ($currentPrice * (1.0 + $totalShockPct)) - $allocation['dividend_paid'];
            $newPrice = max(0.01, $exDivPrice);

            // Precision Assignment
            $stock->setPrice(number_format($newPrice, 8, '.', ''));

            $formattedEps = $actualQuarterlyEps < 0 ? '-$' . number_format(abs($actualQuarterlyEps), 2) : '$' . number_format($actualQuarterlyEps, 2);
            $formattedSurprise = '$' . number_format(abs($surpriseAmountQuarterly), 2);

            // Calculate Economic Value Added (EVA)
            $health = $this->debtEngine->analyzeDebtHealth($stock, $macroState);
            
            if ($leverageType !== 'none') {
                // Financials create EVA when Return on Equity > Cost of Equity
                $costOfEquity = $health['cost_of_equity'] ?? 0.10;
                $annualEconomicProfit = $equity * ($truePostTaxReturn - $costOfEquity);
                $wacc = $costOfEquity; // Fallback for reporting purposes
            } else {
                // Normal companies create EVA when ROIC > WACC
                $wacc = $health['wacc'];
                $annualEconomicProfit = $investedCapital * ($truePostTaxReturn - $wacc);
            }
            
            $evaAbs = abs($annualEconomicProfit);
            $formattedEva = $evaAbs >= 1_000_000_000 
                ? '$' . number_format($evaAbs / 1_000_000_000, 2) . 'B' 
                : '$' . number_format($evaAbs / 1_000_000, 2) . 'M';
                
            $evaString = $annualEconomicProfit >= 0 ? "+{$formattedEva} EVA" : "-{$formattedEva} EVA";

            if ($surpriseAmountQuarterly > 0.0) {
                $description = "Q-Earnings: {$formattedEps} (Beat expectations by {$formattedSurprise} | {$evaString}).";
            } elseif ($surpriseAmountQuarterly < 0.0) {
                $description = "Q-Earnings: {$formattedEps} (Missed expectations by {$formattedSurprise} | {$evaString}).";
            } else {
                $description = "Q-Earnings: {$formattedEps} (Met expectations exactly | {$evaString}).";
            }

            $description .= $corporateActionDescriptions;

            // Create the main Earnings Event
            $earningsEvent = $this->marketEvent->publish($stock, 'EARNINGS', $description, $totalShockPct * 100);

            $allEvents = [$earningsEvent];

            $report = new \App\Entity\CorporateReport();
            $report->setStock($stock);
            $report->setRecordedAt(new \DateTime());

            // The Holy Trinity of the Income Statement
            $report->setRevenue((string) $actualRevenue);
            $report->setNetIncome((string) $actualTotalNetIncome);
            
            $report->setOperatingMargin((string) $trueOperatingMargin);

            // Debt & Treasury Data
            $report->setInterestExpense((string) $debtMetrics['interest_expense']);
            $report->setInterestIncome((string) $interestIncome);
            $report->setBlendedRate((string) $debtMetrics['blended_rate']);
            $report->setDynamicSpread((string) $debtMetrics['dynamic_spread']);

            // Cash Flow & Balance Sheet
            $totalReportedCapex = ($actualAnnualCapEx / 4.0) + $reportedOrganicCapex;
            $report->setCapitalExpenditures((string) $totalReportedCapex);
            $report->setEquity($stock->getTotalEquity());
            $report->setTotalDebt($stock->getTotalDebt());
            $report->setTreasury($stock->getCorporateTreasury());

            // Metrics
            $report->setRoic((string) $truePostTaxReturn);
            $report->setShares((string) $stock->getSharesOutstanding());
            $report->setWacc((string) $wacc);
            $report->setEva((string) $annualEconomicProfit);
            $report->setDividendPaid((string) $allocation['total_paid']);
            $report->setStockBuybacks((string) $allocation['total_cash_spent']);
            $report->setCashYield((string) $health['cash_yield']);
            $report->setDepositApy(isset($allocation['bank_apy']) ? (string) $allocation['bank_apy'] : null);

            // Leveraged/Banking specific metrics
            $finalEquity = (float) $stock->getTotalEquity();
            $finalTotalDebt = (float) $stock->getTotalDebt();
            $roe = $finalEquity > 0 ? ($actualTotalNetIncome / $finalEquity) : 0.0;
            $report->setReturnOnEquity((string) $roe);
            $report->setCostOfEquity((string) ($health['cost_of_equity'] ?? 0.10));
            $capitalRatio = ($finalEquity + $finalTotalDebt) > 0 ? ($finalEquity / ($finalEquity + $finalTotalDebt)) : 1.0;
            $report->setCapitalRatio((string) $capitalRatio);

            if ($leverageType == 'commercial_bank') {
                $customerDeposits = (float) $stock->getCustomerDeposits();
                $depositRatio = $finalTotalDebt > 0 ? ($customerDeposits / $finalTotalDebt) : 0.0;
                $report->setCustomerDepositRatio((string) $depositRatio);
            }

            $this->entityManager->persist($report);
        } finally {
            // Restore the structural margin so Asset Turnover math isn't corrupted next quarter
            $stock->setOperatingMargin((string) $stableMargin);
        }

        // Return the array of events
        return $allEvents;
    }

    /**
     * Applies a volatility shock or cooling effect based on the statistical rarity of the earnings report.
     *
     * @param Stock $stock The stock entity to update.
     * @param float $revenueZ The Z-score (standard normal) representing the revenue shift.
     * @param float $baselineVol The baseline long-term volatility of the stock.
     */
    private function applyVolatilityShock(Stock $stock, float $revenueZ, float $baselineVol): void
    {
        $currentVol = (float) $stock->getCurrentVolatility();
        $zScore = abs($revenueZ); // How many standard deviations away from expectations

        if ($zScore > self::SURPRISE_Z_SCORE_THRESHOLD) {
            // A 1.5+ sigma event is a genuine surprise. Spike the volatility.
            $shockMultiplier = 1.0 + (($zScore - 1.0) * self::VOLATILITY_SHOCK_FACTOR);
            $newVol = min($currentVol * $shockMultiplier, $baselineVol * self::MAX_VOLATILITY_MULTIPLIER);
            $stock->setCurrentVolatility((string) $newVol);
        } elseif ($zScore < self::BORING_Z_SCORE_THRESHOLD && $currentVol > $baselineVol) {
            // A boring, highly predictable quarter. Volatility cools off.
            $newVol = $currentVol - (($currentVol - $baselineVol) * self::VOLATILITY_COOLING_FACTOR);
            $stock->setCurrentVolatility((string) max($newVol, $baselineVol));
        }
    }

    /**
     * Calculates the dampened and capped price gap percentage based on the earnings surprise.
     *
     * Limits the immediate post-earnings price jump/drop to prevent the stock from
     * completely breaking the simulation on a single massive outlier report.
     *
     * @param float $surprisePct The raw percentage by which the company missed or beat expectations.
     * @return float The bounded percentage for the immediate price gap.
     */
    private function calculatePriceGap(float $surprisePct): float
    {
        $priceGapPct = $surprisePct * self::PRICE_GAP_DAMPENING;
        return max(-self::MAX_PRICE_GAP, min(self::MAX_PRICE_GAP, $priceGapPct));
    }

    /**
     * Converts accrual EPS into Free Cash Flow per Share based on Sector CapEx requirements.
     *
     * Factors in the specific Capital Expenditure (CapEx) requirements of the sector
     * and adjusts for macroeconomic cycles (e.g., companies invest more during booms).
     *
     * @param float $actualTotalNetIncome The total net income generated this quarter.
     * @param float $sharesOutstanding    The total shares currently outstanding.
     * @param Stock $stock                The stock entity.
     * @param array $macroState           The current macroeconomic state.
     * @param float $absoluteDepreciation The absolute depreciation amount (non-cash expense).
     * @param bool  $isLeveraged          Whether the company is a leveraged financial institution.
     * @return array{fcf_per_share: float, capex: float}
     */
    private function calculateFreeCashFlowPerShare(
        float $actualTotalNetIncome,
        float $sharesOutstanding,
        Stock $stock,
        array $macroState,
        float $absoluteDepreciation,
        bool $isLeveraged
    ): array {
        if ($sharesOutstanding <= 0) {
            return ['fcf_per_share' => 0.0, 'capex' => 0.0];
        }

        $capExRatio = (float) $stock->getCapexRatio();

        $outputGap = $macroState['output_gap'] ?? 0.0;
        $cycleCapExModifier = max(0.85, min(1.15, 1.00 + ($outputGap * 1.5)));

        $physicalCapital = $isLeveraged ? (float) $stock->getTotalEquity() : $stock->getInvestedCapital();
        $baselineIncomeForCapEx = max($actualTotalNetIncome, $physicalCapital * 0.02);
        $actualCapEx = $baselineIncomeForCapEx * ($capExRatio * $cycleCapExModifier);

        // Add back absolute depreciation (non-cash expense) to find True FCF
        $fcff = $actualTotalNetIncome + $absoluteDepreciation - $actualCapEx;

        return [
            'fcf_per_share' => $fcff / $sharesOutstanding,
            'capex' => $actualCapEx
        ];
    }
}
