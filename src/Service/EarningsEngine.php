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
        $saturationPenalty = max(1.0, log10($totalEarnings / 10000000) + 1.0);

        // Floor the base so penny stocks/low EPS companies can still grow absolute cents
        $growthBase = max(abs($oldEps), 0.50);

        // Analyst Consensus
        // Analysts expect the base growth
        $expectedEpsGrowth = 0.02 / $saturationPenalty;
        $expectedEps = $oldEps + ($growthBase * $expectedEpsGrowth);

        // Model Revenue & Operating Leverage
        $quarterlyVol = $baselineVol * 0.5;
        $revenueZ = $this->mathUtility->generateStandardNormal();
        
        // Actual revenue shifts based on standard distribution
        $actualEpsGrowth = $expectedEpsGrowth + ($quarterlyVol * $revenueZ);
        
        // Calculate Actual EPS
        $actualEps = round($oldEps + ($growthBase * $actualEpsGrowth), 2);

        // Calculate the SURPRISE (Actual vs Expected)
        $surpriseAmount = $actualEps - $expectedEps;
        $surprisePct = $surpriseAmount / max(0.10, abs($expectedEps));

        // VOLATILITY SHOCK
        $currentVol = (float) $stock->getCurrentVolatility();
        if (abs($surprisePct) > 0.10) {
            // A 20% surprise = 20% volatility spike
            $shockMultiplier = 1.0 + abs($surprisePct);
            $newVol = min($currentVol * $shockMultiplier, $baselineVol * 3.0);
            $stock->setCurrentVolatility((string) $newVol);
        }

        // Update the Stock Entity
        $stock->setEarningsPerShare((string) $actualEps);

        // Build the Financial Report String
        $beatOrMiss = $surpriseAmount >= 0 ? 'Beat' : 'Missed';
        $description = sprintf(
            "Q-Earnings: $%.2f (%s expectations by $%.2f).",
            $actualEps,
            $beatOrMiss,
            abs($surpriseAmount)
        );

        return $this->marketEvent->publish($stock, 'EARNINGS', $description, $surprisePct * 100);
    }
}