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
    private const SURPRISE_Z_SCORE_THRESHOLD = 1.5;
    private const BORING_Z_SCORE_THRESHOLD = 0.5;
    private const VOLATILITY_SHOCK_FACTOR = 0.2;
    private const VOLATILITY_COOLING_FACTOR = 0.25;
    private const MAX_VOLATILITY_MULTIPLIER = 3.0;

    // Price Gap Constants
    private const PRICE_GAP_DAMPENING = 0.20;
    private const MAX_PRICE_GAP = 0.25;

    /**
     * Constructor.
     *
     * @param MarketEvent $marketEvent Publisher for all market events, news headlines, and shocks.
     * @param MathUtility|null $mathUtility Utility for advanced mathematical operations (e.g., generating standard normal distribution).
     */
    public function __construct(
        private \Doctrine\ORM\EntityManagerInterface $entityManager,
        private MarketEvent $marketEvent,
        private CorporateActionEngine $corporateActionEngine,
        private DebtEngine $debtEngine,
        private ?MathUtility $mathUtility = null
    ) {
        if ($this->mathUtility === null) {
            $this->mathUtility = new MathUtility();
        }
    }

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
    public function calculate(Stock $stock, array $macroState = [], array $liveSectorPEs = [], int $tickCount = 0, int $ticksPerYear = 252): ?array
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

        // Calculate EBIT (Earnings Before Interest and Taxes)
        $roicData = $this->calculateDynamicRoic($stock, $macroState);
        $stock->setCurrentRoic((string) $roicData['core_roic']);
        $dynamicRoic = $roicData['reported_roic'];

        // Calculate a STABLE Asset Turnover using baseline ROIC to prevent revenue collapse
        $baselineRoic = max(0.01, (float) $stock->getBaselineRoic());
        $assetTurnover = $baselineRoic / max(0.01, (float) $stock->getOperatingMargin());

        // Determine baseline Revenue and Cost Structure
        $baselineRevenue = $investedCapital * $assetTurnover;
        $fixedCostRatio = $stock->getFixedCostRatio(); // 0.80 for Tech, 0.20 for Retail

        // Calculate Baseline Costs
        $targetOperatingMargin = $dynamicRoic / $assetTurnover;
        $baselineTotalCosts = $baselineRevenue * (1.0 - $targetOperatingMargin);
        $fixedCosts = $baselineTotalCosts * $fixedCostRatio;
        $variableCostMargin = ($baselineTotalCosts - $fixedCosts) / max(1.0, $baselineRevenue);

        // APPLY THE Z-SCORE SHOCK TO REVENUE, NOT EPS
        // Scale the shock based on the company's inherent baseline volatility (e.g. 0.20 * 0.15 = 3% StDev)
        $revenueZ = $this->mathUtility->generateStandardNormal();
        $revenueShock = $revenueZ * ($baselineVol * 0.15); 
        $actualRevenue = $baselineRevenue * (1.0 + $revenueShock);
        
        // Update the Stock Entity BEFORE calling DebtEngine so the CFO has the right Top-Line
        $stock->setTotalRevenue((string) $actualRevenue);

        // The Operating Leverage Engine
        $actualVariableCosts = $actualRevenue * $variableCostMargin;
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;
        $expectedEbit = $baselineRevenue - $fixedCosts - ($baselineRevenue * $variableCostMargin);

        // Interest & Debt Physics
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        
        $debtMetrics = $this->debtEngine->calculateInterestExpense($stock, $macroState, true);
        $interestExpense = $debtMetrics['interest_expense'];

        $stock->setHistoricalFixedRate((string) $debtMetrics['blended_rate']);


        // Interest Income. 
        // Mega-hoarders generate massive risk-free yield on their cash piles!
        $workingCapital = $equity * 0.05;
        $excessCash = max(0.0, $cash - $workingCapital);

        // Earning Policy Rate minus a 1% spread for standard money-market yields
        $cashYield = max(0.0, $policyRate - 0.01);
        $interestIncome = $excessCash * $cashYield;

        // CALCULATE PHYSICAL DEPRECIATION (The Rusting of Assets)
        $sector = $stock->getSector();
        $depreciationRate = match ($sector) {
            'Information Technology' => 0.15,
            'Communication Services' => 0.12,
            'Healthcare' => 0.08,
            'Consumer Discretionary', 'Consumer Staples' => 0.06,
            'Industrials', 'Materials', 'Energy' => 0.04,
            'Utilities', 'Real Estate' => 0.03,
            'Financials' => 0.02,
            default => (float) $stock->getDepreciationRate() ?: 0.05,
        };
        
        // Physical assets rust, not equity. Invested Capital perfectly isolates the physical operating base.
        $absoluteDepreciation = $investedCapital * $depreciationRate;

        // Calculate Earnings Before Tax (EBT)
        $expectedEbt = $expectedEbit - $absoluteDepreciation - $interestExpense + $interestIncome;
        $actualEbt = $ebit - $absoluteDepreciation - $interestExpense + $interestIncome;

        // Apply Corporate Taxes to find True Net Income
        $corporateTaxRate = $macroState['corporate_tax_rate'] ?? 0.21;
        $expectedTotalNetIncome = $expectedEbt * (1.0 - $corporateTaxRate);
        $actualTotalNetIncome = $actualEbt * (1.0 - $corporateTaxRate);

        // Modifier scaled correctly for Annual EPS
        $expectedAnnualEps = $expectedTotalNetIncome / max(1.0, $sharesOutstanding);
        $actualAnnualEpsRaw = $actualTotalNetIncome / max(1.0, $sharesOutstanding);

        // Smooth the transition from Old EPS to Expected EPS
        $expectedAnnualEpsDrifted = $oldAnnualEps + (($expectedAnnualEps - $oldAnnualEps) * 0.50);

        // Calculate Actual ANNUAL EPS (shifted by the exact same smoothed drift)
        $epsDifference = $actualAnnualEpsRaw - $expectedAnnualEps;
        $actualAnnualEps = $expectedAnnualEpsDrifted + $epsDifference;

        // Calculate Quarterly metrics for the UI and price gap logic
        $actualQuarterlyEps = $actualAnnualEps / 4.0;
        $expectedQuarterlyEps = $expectedAnnualEpsDrifted / 4.0;
        $surpriseAmountQuarterly = $actualQuarterlyEps - $expectedQuarterlyEps;
        
        $surprisePct = abs($expectedQuarterlyEps) > 0.01 
            ? $surpriseAmountQuarterly / abs($expectedQuarterlyEps) 
            : ($surpriseAmountQuarterly > 0 ? 0.10 : ($surpriseAmountQuarterly < 0 ? -0.10 : 0.0));

        // VOLATILITY SHOCK
        $this->applyVolatilityShock($stock, $revenueZ, $baselineVol);

        // Update the Stock Entity with the raw, unrounded ANNUAL figure to maintain perfect Clean Surplus Accounting.
        // The Entity's setter will automatically update the Total Net Income mathematically.
        $stock->setEarningsPerShare((string) $actualAnnualEps);

        $fcfData = $this->calculateFreeCashFlowPerShare($actualAnnualEps, $sharesOutstanding, $stock, $macroState, $absoluteDepreciation);
        $annualFcfPerShare = $fcfData['fcf_per_share'];
        $actualAnnualCapEx = $fcfData['capex'];
        $stock->setFreeCashFlowPerShare((string) $annualFcfPerShare);

        $priceGapPct = $this->calculatePriceGap($surprisePct);
        $currentPrice = (float) $stock->getPrice();
        $quarterlyFcfPerShare = $annualFcfPerShare / 4.0;
        $liveTargetPE = $liveSectorPEs[$stock->getSector()] ?? 20.0;

        // ALLOCATE CAPITAL
        $allocation = $this->corporateActionEngine->allocateCapital(
            $stock,
            $actualAnnualEps,
            $quarterlyFcfPerShare,
            $currentPrice,
            $sharesOutstanding,
            $liveTargetPE,
            $macroState
        );

        $stock->setSharesOutstanding((string) $allocation['new_shares']);

        // APPLY THE GAP
        $currentPrice = (float) $stock->getPrice();
        $newPrice = max(0.01, ($currentPrice * (1.0 + $priceGapPct)) - $allocation['dividend_paid']);
        $stock->setPrice((string) round($newPrice, 2));

        $formattedEps = $actualQuarterlyEps < 0 ? '-$' . number_format(abs($actualQuarterlyEps), 2) : '$' . number_format($actualQuarterlyEps, 2);
        $formattedSurprise = '$' . number_format(abs($surpriseAmountQuarterly), 2);

        // Calculate Economic Value Added (EVA)
        $health = $this->debtEngine->analyzeDebtHealth($stock, $macroState);
        $wacc = $health['wacc'];
        $economicProfit = $investedCapital * ($dynamicRoic - $wacc);
        $formattedEva = '$' . number_format(abs($economicProfit / 1_000_000_000), 2) . 'B';
        $evaString = $economicProfit >= 0 ? "+{$formattedEva} EVA" : "-{$formattedEva} EVA";

        if ($surpriseAmountQuarterly > 0.0) {
            $description = "Q-Earnings: {$formattedEps} (Beat expectations by {$formattedSurprise} | {$evaString}).";
        } elseif ($surpriseAmountQuarterly < 0.0) {
            $description = "Q-Earnings: {$formattedEps} (Missed expectations by {$formattedSurprise} | {$evaString}).";
        } else {
            $description = "Q-Earnings: {$formattedEps} (Met expectations exactly | {$evaString}).";
        }

        // Create the main Earnings Event
        $earningsEvent = $this->marketEvent->publish($stock, 'EARNINGS', $description, $priceGapPct * 100);

        // Merge it with any Dividend or Buyback events generated by the CorporateActionEngine
        $allEvents = [$earningsEvent];
        if (!empty($allocation['events'])) {
            $allEvents = array_merge($allEvents, $allocation['events']);
        }

        $report = new \App\Entity\CorporateReport();
        $report->setStock($stock);
        $report->setRecordedAt(new \DateTime());
        
        // The Holy Trinity of the Income Statement
        $report->setRevenue((string) $actualRevenue);
        $report->setNetIncome((string) $actualTotalNetIncome);
        
        // Debt & Treasury Data
        $report->setInterestExpense((string) $debtMetrics['interest_expense']);
        $report->setInterestIncome((string) $interestIncome);
        $report->setBlendedRate((string) $debtMetrics['blended_rate']);
        $report->setDynamicSpread((string) $debtMetrics['dynamic_spread']);
        
        // Cash Flow & Balance Sheet
        $report->setCapitalExpenditures((string) $actualAnnualCapEx);
        $report->setEquity($stock->getTotalEquity());
        $report->setTotalDebt($stock->getTotalDebt());
        $report->setTreasury($stock->getCorporateTreasury());
        
        // Metrics
        $report->setRoic((string) $dynamicRoic);
        $report->setShares((string) $stock->getSharesOutstanding());
        $report->setWacc((string) $wacc);
        $report->setEva((string) $economicProfit);

        $this->entityManager->persist($report);

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
     */
    private function calculatePriceGap(float $surprisePct): float
    {
        $priceGapPct = $surprisePct * self::PRICE_GAP_DAMPENING;
        return max(-self::MAX_PRICE_GAP, min(self::MAX_PRICE_GAP, $priceGapPct));
    }

    /**
     * Converts accrual EPS into Free Cash Flow per Share based on Sector CapEx requirements.
     */
    private function calculateFreeCashFlowPerShare(
        float $actualEps,
        float $sharesOutstanding,
        Stock $stock,
        array $macroState,
        float $absoluteDepreciation
    ): array {
        if ($sharesOutstanding <= 0) {
            return ['fcf_per_share' => 0.0, 'capex' => 0.0];
        }

        $netIncome = $actualEps * $sharesOutstanding;
        $capExRatio = (float) $stock->getCapexRatio();

        $outputGap = $macroState['output_gap'] ?? 0.0;
        $cycleCapExModifier = max(0.85, min(1.15, 1.00 + ($outputGap * 1.5)));

        $baselineIncomeForCapEx = max($netIncome, (float) $stock->getTotalEquity() * 0.02);
        $actualCapEx = $baselineIncomeForCapEx * ($capExRatio * $cycleCapExModifier);

        // Add back absolute depreciation (non-cash expense) to find True FCF
        $fcff = $netIncome + $absoluteDepreciation - $actualCapEx;

        return [
            'fcf_per_share' => $fcff / $sharesOutstanding,
            'capex' => $actualCapEx
        ];
    }

    /**
     * Calculates the dynamically shifting ROIC based on Macro conditions and Corporate Saturation.
     */
    private function calculateDynamicRoic(Stock $stock, array $macroState): array
    {
        $baselineRoic = (float) $stock->getBaselineRoic();
        $currentRoic = (float) $stock->getCurrentRoic();
        $beta = (float) $stock->getBeta();
        
        if ($currentRoic === 0.0) {
            $currentRoic = $baselineRoic;
        }

        $investedCapital = $stock->getInvestedCapital();

        // Macroeconomic Modifier
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $macroModifier = $outputGap * $beta;

        // DYNAMIC TAM LOGIC
        $nominalGdpIndex = $macroState['nominal_gdp_index'] ?? 1.0;

        $baselineSectorTam = \App\Data\SectorPE::getBaselineTam($stock->getSector());
        $dynamicTam = $baselineSectorTam * $nominalGdpIndex;
        $marketShare = $investedCapital / $dynamicTam;

        $saturationThreshold = 0.25; // 25% Market Share triggers bloat gravity
        $saturationPenalty = 0.0;

        $systemic_importance = $stock->getSystemicImportance();

        // The larger the systemic importance, the stronger the "moat" protecting their ROIC
        $moat = match ($systemic_importance) {
            'titan'    => 0.3, // Only takes 30% of the saturation penalty
            'systemic' => 0.6, // Takes 60% of the penalty
            'base'     => 0.8, // Takes 80% of the penalty
            default    => 1.0, // Takes the full 100% saturation penalty
        };
        
        if ($marketShare > $saturationThreshold) {
            $excessSize = $marketShare / $saturationThreshold;
            
            // Dynamic Margin Gravity
            // We floor the bleed factor at 0.10 so normal companies still face gravity,
            // but a company with a 30% ROIC will bleed 3x faster than a 10% company
            $roicBleedFactor = max(0.10, $baselineRoic);
            $gravityMultiplier = $roicBleedFactor * 0.30; 
            
            // The heavier the balance sheet and the higher the margins, the harder the fall
            $MarketSaturationPenalty = log($excessSize) * $gravityMultiplier;
            $saturationPenalty = $MarketSaturationPenalty * $moat;

            // Hyper-scale gravity triggers at 60% Monopoly Market Share
            if ($marketShare > 0.60) {
                $hyperScaleExcess = $marketShare / 0.60;
                
                // Bypasses the titan moat. Hyper-scale gravity also scales with their fat margins
                $rawGravity = log($hyperScaleExcess) * $gravityMultiplier;
                $saturationPenalty += $rawGravity; 
            }
            
            // Terminal Monopoly gravity triggers at 85% Market Share
            if ($marketShare > 0.85) {
                // Bypasses the titan moat. sheer physical limits brutally crush margins.
                $terminalExcess = $marketShare - 0.85;
                $saturationPenalty += ($terminalExcess * 0.75);
            }
        }

        // Calculate where the core business ROIC "wants" to be (Saturation Penalty is smoothed)
        $targetRoic = $baselineRoic - $saturationPenalty;

        // Mean Reversion: Smoothly drift the current ROIC toward the target
        $pull = ($targetRoic - $currentRoic) * 0.25;

        // Add standard deviation noise
        $fundamentalNoise = $this->mathUtility->generateStandardNormal() * 0.015;

        // The core ROIC (saved to DB so it doesn't infinitely compound macro shocks)
        $newCoreRoic = $currentRoic + $pull + $fundamentalNoise;
        
        // The actual reported ROIC for this quarter (Core + Instant Macro Shock)
        $reportedRoic = $newCoreRoic + $macroModifier;

        return [
            'core_roic'     => max(-0.10, min(0.50, $newCoreRoic)),
            'reported_roic' => max(-0.10, min(0.50, $reportedRoic))
        ];
    }
}
