<?php

namespace App\Service;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles the simulation of quarterly earnings reports.
 * Models revenue, operating leverage, analyst consensus, and corporate saturation.
 */
class EarningsEngine
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketEvent $marketEvent,
        private ?MathUtility $mathUtility = null
    ) {
        if ($this->mathUtility === null) {
            $this->mathUtility = new MathUtility();
        }
    }

    public function calculate(Stock $stock, float $dt): ?array
    {
        // Quarterly Earnings (Roughly 4 times per year)
        if ((mt_rand() / mt_getrandmax()) >= (4.0 * $dt)) {
            return null;
        }

        $oldEps = (float) $stock->getEarningsPerShare();
        $sharesOutstanding = (int) $stock->getSharesOutstanding();
        $baselineVol = (float) $stock->getVolatility();

        $totalEarnings = max(1.0, abs($oldEps) * $sharesOutstanding);
        $saturationPenalty = max(1.0, log10($totalEarnings / 20000000) + 1.0);

        // Floor the base so penny stocks/low EPS companies can still grow absolute cents
        $growthBase = max(abs($oldEps), 0.50);

        // Analyst Consensus
        // Analysts expect the base growth
        $expectedEpsGrowth = 0.02 / $saturationPenalty;
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