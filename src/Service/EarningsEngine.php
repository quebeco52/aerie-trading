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
    public function calculate(Stock $stock, array $macroState = [],array $liveSectorPEs = [], int $tickCount = 0, int $ticksPerYear = 252): ?array
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

        $oldEps = (float) $stock->getEarningsPerShare();
        $baselineVol = (float) $stock->getVolatility();
        $beta = (float) $stock->getBeta();
        $sharesOutstanding = (int) $stock->getSharesOutstanding();

        // Floor the base so penny stocks/low EPS companies can still grow absolute cents
        $growthBase = max(abs($oldEps), 0.50);

        // Analyst Consensus (Expected EPS Growth)
        $expectedEpsGrowth = $this->calculateExpectedEpsGrowth($stock, $macroState);

        // Round expected EPS to 2 decimals to prevent floating-point "ghost misses"
        $expectedEps = round($oldEps + ($growthBase * $expectedEpsGrowth), 2);

        // Model Revenue & Operating Leverage
        $quarterlyVol = $baselineVol * 0.5;
        $revenueZ = $this->mathUtility->generateStandardNormal();

        // Actual revenue shifts based on standard distribution
        $actualEpsGrowth = $expectedEpsGrowth + ($quarterlyVol * $revenueZ);

        // Calculate Actual EPS
        $actualEps = round($oldEps + ($growthBase * $actualEpsGrowth), 2);



        // Calculate the SURPRISE
        $surpriseAmount = round($actualEps - $expectedEps, 2);
        $surprisePct = $surpriseAmount / max(0.10, abs($expectedEps));

        // VOLATILITY SHOCK: Based strictly on the Z-Score (Statistical Rarity)
        $this->applyVolatilityShock($stock, $revenueZ, $baselineVol);

        // Update the Stock Entity
        $stock->setEarningsPerShare((string) $actualEps);

        $annualFcfPerShare = $this->calculateFreeCashFlowPerShare($actualEps, $sharesOutstanding, $stock, $macroState);
        $stock->setFreeCashFlowPerShare((string) $annualFcfPerShare);

        $priceGapPct = $this->calculatePriceGap($surprisePct);
        $currentPrice = (float) $stock->getPrice();

        $quarterlyFcfPerShare = $annualFcfPerShare / 4.0;

        $liveTargetPE = $liveSectorPEs[$stock->getSector()] ?? 20.0;

        // ALLOCATE CAPITAL

        $allocation = $this->corporateActionEngine->allocateCapital(
            $stock,
            $actualEps,
            $quarterlyFcfPerShare,
            $currentPrice,
            $sharesOutstanding,
            $liveTargetPE
        );

        $stock->setEarningsPerShare((string) $allocation['new_eps']);
        $stock->setSharesOutstanding((string) $allocation['new_shares']);

        // APPLY THE GAP
        $currentPrice = (float) $stock->getPrice();
        $newPrice = $currentPrice * (1.0 + $priceGapPct);

        $newPrice -= $allocation['dividend_paid'];

        $newPrice = max(0.01, $newPrice);

        $stock->setPrice((string) round($newPrice, 2));

        $formattedEps = $actualEps < 0 ? '-$' . number_format(abs($actualEps), 2) : '$' . number_format($actualEps, 2);
        $formattedSurprise = '$' . number_format(abs($surpriseAmount), 2);

        if ($surpriseAmount > 0.0) {
            $description = "Q-Earnings: {$formattedEps} (Beat expectations by {$formattedSurprise}).";
        } elseif ($surpriseAmount < 0.0) {
            $description = "Q-Earnings: {$formattedEps} (Missed expectations by {$formattedSurprise}).";
        } else {
            $description = "Q-Earnings: {$formattedEps} (Met expectations exactly).";
        }

        // Create the main Earnings Event
        $earningsEvent = $this->marketEvent->publish($stock, 'EARNINGS', $description, $surprisePct * 100);

        // Merge it with any Dividend or Buyback events generated by the CorporateActionEngine
        $allEvents = [$earningsEvent];
        if (!empty($allocation['events'])) {
            $allEvents = array_merge($allEvents, $allocation['events']);
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
     */
    private function calculatePriceGap(float $surprisePct): float
    {
        $priceGapPct = $surprisePct * self::PRICE_GAP_DAMPENING;
        return max(-self::MAX_PRICE_GAP, min(self::MAX_PRICE_GAP, $priceGapPct));
    }

    /**
     * Calculates the expected quarter-over-quarter EPS growth rate based on the 
     * macroeconomic cycle and the stock's sensitivity to it (beta).
     */
    private function calculateExpectedEpsGrowth(Stock $stock, array $macroState): float
    {
        $capExRatio = (float) $stock->getCapexRatio();
        
        // Calculate the dynamically shifting ROIC
        $dynamicRoic = $this->calculateDynamicRoic($stock, $macroState);
        
        $stock->setCurrentRoic((string) $dynamicRoic);

        //Fundamental Organic Growth Formula: Growth = Retention * ROIC
        $organicQuarterlyGrowth = ($capExRatio * $dynamicRoic) / 4.0;

        // Add the systemic Beta tailwind/headwind
        $beta = (float) $stock->getBeta();
        $macroCycleModifier = (($macroState['output_gap'] ?? 0.0) / 4.0) * $beta;

        return $organicQuarterlyGrowth + $macroCycleModifier;
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
        $operatingCashFlow = $netIncome * 1.20; // OCF proxy

        $capExRatio = (float) $stock->getCapexRatio();

        // If the Output Gap is positive, the economy is booming, CapEx increases.
        $outputGap = $macroState['output_gap'] ?? 0.0;
        $cycleCapExModifier = 1.00 + ($outputGap * 5.0); 

        // Floor the modifier so CapEx doesn't go negative in a deep depression
        $cycleCapExModifier = max(0.50, $cycleCapExModifier);

        $actualCapEx = $operatingCashFlow * ($capExRatio * $cycleCapExModifier);
        $fcff = $operatingCashFlow - $actualCapEx;

        return $fcff / $sharesOutstanding;
    }

    /**
     * Calculates the dynamically shifting ROIC based on Macro conditions and Corporate Saturation.
     */
    private function calculateDynamicRoic(Stock $stock, array $macroState): float
    {
        $baselineRoic = (float) $stock->getBaselineRoic();
        $currentRoic = (float) $stock->getCurrentRoic();
        
        // Failsafe for a freshly reset database
        if ($currentRoic === 0.0) {
            $currentRoic = $baselineRoic;
        }

        $shares = (int) $stock->getSharesOutstanding();
        $price = (float) $stock->getPrice();
        $marketCap = $shares * $price;

        // Macroeconomic Modifier
        $outputGap = $macroState['output_gap'] ?? 0.0;
        $macroModifier = $outputGap * 0.5;

        // Corporate Saturation Penalty
        $saturationThreshold = 1_000_000_000_000;
        $saturationPenalty = 0.0;
        if ($marketCap > $saturationThreshold) {
            $excessSize = $marketCap / $saturationThreshold;
            $saturationPenalty = log($excessSize) * 0.015; 
        }

        // Calculate where the ROIC "wants" to be based on the current economy
        $targetRoic = $baselineRoic + $macroModifier - $saturationPenalty;

        // Mean Reversion: Smoothly drift the current ROIC toward the target (25% step per quarter)
        $pull = ($targetRoic - $currentRoic) * 0.25;

        // Add standard deviation noise
        $fundamentalNoise = $this->mathUtility->generateStandardNormal() * 0.015;

        // The new ROIC is the old ROIC + the drift + the noise
        $dynamicRoic = $currentRoic + $pull + $fundamentalNoise;

        return max(-0.10, $dynamicRoic);
    }
}
