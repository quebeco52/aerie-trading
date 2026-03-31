<?php

namespace App\Service;

use App\Data\EconomicCycle;
use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles the simulation of quarterly earnings reports.
 * Models revenue, operating leverage, analyst consensus, and corporate saturation.
 */
class EarningsEngine
{
    /**
     * Constructor.
     *
     * @param EntityManagerInterface $entityManager The Doctrine entity manager.
     * @param MarketEvent $marketEvent Publisher for all market events, news headlines, and shocks.
     * @param MathUtility|null $mathUtility Utility for advanced mathematical operations (e.g., generating standard normal distribution).
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketEvent $marketEvent,
        private ?MathUtility $mathUtility = null
    ) {
        if ($this->mathUtility === null) {
            $this->mathUtility = new MathUtility();
        }
    }

    /**
     * Calculates and processes a quarterly earnings report for a given stock.
     *
     * This method simulates the outcome of an earnings report based on a probability
     * determined by the time step ($dt). If triggered, it calculates expected vs. actual
     * earnings per share (EPS), applying growth baselines and a saturation penalty for
     * larger companies. It also adjusts the stock's volatility based on the statistical
     * rarity (Z-Score) of the revenue shift.
     *
     * @param Stock $stock The stock entity to process earnings for.
     * @param float $dt    The time step (delta time) used to determine the probability of an earnings event.
     * @param EconomicCycle|null $economicCycle The current state of the macroeconomic cycle.
     * @return array|null  Returns the generated market event array if an earnings report occurred, otherwise null.
     */
    public function calculate(Stock $stock, float $dt, ?EconomicCycle $economicCycle = null, int $tickCount, int $ticksPerYear): ?array
    {
        
        $ticksPerQuarter = (int) ($ticksPerYear / 4);
        
        // Define the season length (e.g., 30 days out of ~91 days in a quarter)
        // 30 days is roughly 33% of a quarter.
        $ticksPerSeason = (int) ($ticksPerQuarter * 0.20); 
        
        // Where are we currently within the 3-month quarter?
        $currentQuarterTick = $tickCount % $ticksPerQuarter;
        
        // 1. Are we outside the Earnings Season?
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
        $sharesOutstanding = (int) $stock->getSharesOutstanding();
        $baselineVol = (float) $stock->getVolatility();
        $beta = (float) $stock->getBeta();

        // Saturation Penalty

        $totalEarnings = max(1.0, abs($oldEps) * $sharesOutstanding);
        $saturationPenalty = max(1.0, log10($totalEarnings / 20000000) + 1.0);

        // Floor the base so penny stocks/low EPS companies can still grow absolute cents
        $growthBase = max(abs($oldEps), 0.50);

        // Factor in the economic cycle
        $cycleModifier = $economicCycle ? $economicCycle->getGrowthModifier() : 0.0;

        $companyCycleModifier = $cycleModifier * $beta;

        // Analyst Consensus
        // Analysts expect the base growth
        $expectedEpsGrowth = (0.02 / $saturationPenalty) + $companyCycleModifier;
        // Round expected EPS to 2 decimals to prevent floating-point "ghost misses"
        $expectedEps = round($oldEps + ($growthBase * $expectedEpsGrowth), 2);

        // Model Revenue & Operating Leverage
        $quarterlyVol = $baselineVol * 0.5;
        $revenueZ = $this->mathUtility->generateStandardNormal();
        
        // Actual revenue shifts based on standard distribution
        $actualEpsGrowth = $expectedEpsGrowth + ($quarterlyVol * $revenueZ);
        
        // Calculate Actual EPS
        $actualEps = round($oldEps + ($growthBase * $actualEpsGrowth), 2);

        // Calculate the SURPRISE (Keep this strictly for the UI/News Feed)
        $surpriseAmount = round($actualEps - $expectedEps, 2);
        $surprisePct = $surpriseAmount / max(0.10, abs($expectedEps));

        // VOLATILITY SHOCK: Based strictly on the Z-Score (Statistical Rarity)
        $currentVol = (float) $stock->getCurrentVolatility();
        $zScore = abs($revenueZ); // How many standard deviations away from expectations

        if ($zScore > 1.5) {
            // A 1.5+ sigma event is a genuine surprise. Spike the volatility.
            // Example: Z=2.5 -> (2.5 - 1.0) * 0.2 = 0.3 (A 30% Volatility Spike)
            $shockMultiplier = 1.0 + (($zScore - 1.0) * 0.2);
            $newVol = min($currentVol * $shockMultiplier, $baselineVol * 3.0);
            
            $stock->setCurrentVolatility((string) $newVol);
            
        } elseif ($zScore < 0.5 && $currentVol > $baselineVol) {
            // A boring, highly predictable quarter (Z < 0.5). 
            // The market calms down. Volatility cools off by 25% toward the baseline.
            $newVol = $currentVol - (($currentVol - $baselineVol) * 0.25);
            
            $stock->setCurrentVolatility((string) max($newVol, $baselineVol));
        }

        // Update the Stock Entity
        $stock->setEarningsPerShare((string) $actualEps);

        $formattedEps = $actualEps < 0 ? '-$' . number_format(abs($actualEps), 2) : '$' . number_format($actualEps, 2);
        $formattedSurprise = '$' . number_format(abs($surpriseAmount), 2);

        if ($surpriseAmount > 0.0) {
            $description = "Q-Earnings: {$formattedEps} (Beat expectations by {$formattedSurprise}).";
        } elseif ($surpriseAmount < 0.0) {
            $description = "Q-Earnings: {$formattedEps} (Missed expectations by {$formattedSurprise}).";
        } else {
            $description = "Q-Earnings: {$formattedEps} (Met expectations exactly).";
        }

        return $this->marketEvent->publish($stock, 'EARNINGS', $description, $surprisePct * 100);
    }
}