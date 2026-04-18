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

        // Calculate the dynamically shifting ROIC
        $dynamicRoic = $this->calculateDynamicRoic($stock, $macroState);
        $stock->setCurrentRoic((string) $dynamicRoic);

        // Fundamental Expected Earnings (Driven by Capital, not past EPS)
        $bookValuePerShare = (float) $stock->getBookValuePerShare();
        $safeBookValue = max($bookValuePerShare, 0.10); 
        
        // 1. Calculate the True Size of the Business (Invested Capital)
        $equity = (float) $stock->getTotalEquity();
        $debt = (float) $stock->getTotalDebt();
        $cash = (float) $stock->getCorporateTreasury();
        
        // THE FIX 1: The Core Business Floor. 
        // Even if they hoard massive cash, we assume at least 50% of their equity is actively driving the core business.
        $baseCapital = $equity + $debt - $cash;
        $investedCapital = max($equity * 0.50, $baseCapital);

        // 2. Calculate NOPAT (Net Operating Profit After Tax)
        $dynamicRoic = $this->calculateDynamicRoic($stock, $macroState);
        $stock->setCurrentRoic((string) $dynamicRoic);
        
        $nopat = $investedCapital * $dynamicRoic;

        // 3. The Macro Transmission Mechanism (Interest Expense & Income)
        $policyRate = $macroState['policy_rate'] ?? 0.04;
        $creditSpread = (float) $stock->getCreditSpread();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        
        $fixedInterestRate = 0.03; 
        $floatingInterestRate = $policyRate + $creditSpread;
        
        $interestExpense = ($debt * (1.0 - $floatingRatio) * $fixedInterestRate) + 
                           ($debt * $floatingRatio * $floatingInterestRate);

        // THE FIX 2: Interest Income. 
        // Mega-hoarders generate massive risk-free yield on their cash piles!
        $workingCapital = $equity * 0.05;
        $excessCash = max(0.0, $cash - $workingCapital);
        
        // Earning Policy Rate minus a 1% spread for standard money-market yields
        $cashYield = max(0.0, $policyRate - 0.01); 
        $interestIncome = $excessCash * $cashYield;

        // 4. Calculate True Fundamental Expected Net Income
        $expectedTotalNetIncome = $nopat - $interestExpense + $interestIncome;
        
        // 5. Convert to Expected EPS
        $expectedAnnualEps = $expectedTotalNetIncome / max(1.0, $sharesOutstanding);
        // Add the Macroeconomic Beta Modifier (Tailwinds/Headwinds)
        $beta = (float) $stock->getBeta();
        // Modifier scaled correctly for Annual EPS
        $macroCycleModifier = ($macroState['output_gap'] ?? 0.0) * $beta * $safeBookValue;
        
        // Smooth the transition from Old EPS to Expected EPS
        $targetAnnualEps = $expectedAnnualEps + $macroCycleModifier;
        $expectedAnnualEpsDrifted = round($oldAnnualEps + (($targetAnnualEps - $oldAnnualEps) * 0.25), 2);

        // Model Revenue/Earnings Volatility (The Surprise)
        $revenueZ = $this->mathUtility->generateStandardNormal();
        
        // The shock is proportional to capital size. Dropped from 0.10 to 0.05 to prevent 150%+ surprises.
        $epsShockAmountAnnual = $safeBookValue * $baselineVol * $revenueZ * 0.05; 

        // Calculate Actual ANNUAL EPS
        $actualAnnualEps = round($expectedAnnualEpsDrifted + $epsShockAmountAnnual, 2);

        $expectedQuarterlyEps = $expectedAnnualEpsDrifted / 4.0;
        $actualQuarterlyEps = $actualAnnualEps / 4.0;
        
        $surpriseAmountQuarterly = round($actualQuarterlyEps - $expectedQuarterlyEps, 2);
        $surprisePct = $surpriseAmountQuarterly / max(0.05, abs($expectedQuarterlyEps));

        // VOLATILITY SHOCK
        $this->applyVolatilityShock($stock, $revenueZ, $baselineVol);

        // Update the Stock Entity with the strict ANNUAL figure
        $stock->setEarningsPerShare((string) $actualAnnualEps);

        $annualFcfPerShare = $this->calculateFreeCashFlowPerShare($actualAnnualEps, $sharesOutstanding, $stock, $macroState);
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
            $liveTargetPE
        );

        $stock->setSharesOutstanding((string) $allocation['new_shares']);

        // APPLY THE GAP
        $currentPrice = (float) $stock->getPrice();
        $newPrice = max(0.01, ($currentPrice * (1.0 + $priceGapPct)) - $allocation['dividend_paid']);
        $stock->setPrice((string) round($newPrice, 2));

        $formattedEps = $actualQuarterlyEps < 0 ? '-$' . number_format(abs($actualQuarterlyEps), 2) : '$' . number_format($actualQuarterlyEps, 2);
        $formattedSurprise = '$' . number_format(abs($surpriseAmountQuarterly), 2);

        if ($surpriseAmountQuarterly > 0.0) {
            $description = "Q-Earnings: {$formattedEps} (Beat expectations by {$formattedSurprise}).";
        } elseif ($surpriseAmountQuarterly < 0.0) {
            $description = "Q-Earnings: {$formattedEps} (Missed expectations by {$formattedSurprise}).";
        } else {
            $description = "Q-Earnings: {$formattedEps} (Met expectations exactly).";
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
        $report->setNetIncome($stock->getTotalNetIncome());
        $report->setEquity($stock->getTotalEquity());
        $report->setTotalDebt($stock->getTotalDebt());
        $report->setTreasury($stock->getCorporateTreasury());
        $report->setRoic($stock->getCurrentRoic() ?: $stock->getBaselineRoic());
        $report->setShares((string) $stock->getSharesOutstanding());
        
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
        int $sharesOutstanding,
        Stock $stock,
        array $macroState
    ): float {
        if ($sharesOutstanding <= 0) return 0.0;

        $netIncome = $actualEps * $sharesOutstanding;
        $capExRatio = (float) $stock->getCapexRatio();
        
        // Fetch the depreciation rate!
        $depreciationRate = (float) $stock->getDepreciationRate();

        $outputGap = $macroState['output_gap'] ?? 0.0;
        $cycleCapExModifier = max(0.85, min(1.15, 1.00 + ($outputGap * 1.5)));

        $baselineIncomeForCapEx = max($netIncome, (float) $stock->getTotalEquity() * 0.02);
        $actualCapEx = $baselineIncomeForCapEx * ($capExRatio * $cycleCapExModifier);
        
        // Add back absolute depreciation (non-cash expense) to find True FCF
        $absoluteDepreciation = (float) $stock->getTotalEquity() * $depreciationRate;
        
        $fcff = $netIncome + $absoluteDepreciation - $actualCapEx;

        return $fcff / $sharesOutstanding;
    }

    /**
     * Calculates the dynamically shifting ROIC based on Macro conditions and Corporate Saturation.
     */
    private function calculateDynamicRoic(Stock $stock, array $macroState): float
    {
        $baselineRoic = (float) $stock->getBaselineRoic();
        $currentRoic = (float) $stock->getCurrentRoic();
        
        if ($currentRoic === 0.0) {
            $currentRoic = $baselineRoic;
        }

        // Fetch the fundamental size of the company, not the market hype!
        $equity = (float) $stock->getTotalEquity();
        $debt = (float) $stock->getTotalDebt();
        $cash = (float) $stock->getCorporateTreasury();
        
        $investedCapital = max($equity * 0.50, ($equity + $debt - $cash));

        // Macroeconomic Modifier
        $outputGap = $macroState['output_gap'] ?? 0.0;
        $macroModifier = $outputGap * 0.5;

        // Corporate Saturation Penalty (Now driven by Book Value)
        $saturationThreshold = 500_000_000_000; // $500 Billion in Equity
        $saturationPenalty = 0.0;
        
        if ($investedCapital > $saturationThreshold) {
            $excessSize = $investedCapital / $saturationThreshold;
            // The heavier the balance sheet, the harder it is to steer the ship
            $saturationPenalty = log($excessSize) * 0.015; 
        }

        // Calculate where the ROIC "wants" to be
        $targetRoic = $baselineRoic + $macroModifier - $saturationPenalty;

        // Mean Reversion: Smoothly drift the current ROIC toward the target
        $pull = ($targetRoic - $currentRoic) * 0.25;

        // Add standard deviation noise
        $fundamentalNoise = $this->mathUtility->generateStandardNormal() * 0.015;

        $dynamicRoic = $currentRoic + $pull + $fundamentalNoise;

        return max(-0.10, min(0.50, $dynamicRoic));
    }
}
