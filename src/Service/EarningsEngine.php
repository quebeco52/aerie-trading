<?php

namespace App\Service;

use App\Entity\Stock;
use App\Entity\StockEvent;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles the simulation of quarterly earnings reports.
 * Models revenue, operating leverage, analyst consensus, and corporate saturation.
 */
class EarningsEngine
{
    public function __construct(
        private EntityManagerInterface $entityManager,
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
        
        // The Saturation Penalty (Gravity for massive corporations)
        $currentEpsForMath = max(0.10, abs($oldEps));
        $totalEarnings = $currentEpsForMath * $sharesOutstanding;
        $saturationPenalty = max(1.0, log10($totalEarnings / 10000000) + 1.0); 

        // Analyst Consensus
        // Analysts expect the base growth, slightly handicapped by how massive the company is
        $expectedEpsGrowth = 0.02 / $saturationPenalty;
        $expectedEps = $oldEps * (1.0 + $expectedEpsGrowth);

        // Model Revenue & Operating Leverage
        $quarterlyVol = $baselineVol * 0.5;
        $revenueZ = $this->mathUtility->generateStandardNormal();
        
        // Actual revenue shifts based on standard distribution
        $revenuePctChange = $expectedEpsGrowth + ($quarterlyVol * 0.5 * $revenueZ);
        
        // Operating Leverage: Fixed costs mean EPS swings harder than Revenue
        $operatingLeverage = 1.5 + $baselineVol; 
        $actualEpsGrowth = $revenuePctChange * $operatingLeverage;
        
        // Calculate Actual EPS
        $actualEps = round($oldEps + (max(abs($oldEps), 0.50) * $actualEpsGrowth), 2);

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

        // Create and Persist the Event
        $event = new StockEvent();
        $event->setStock($stock);
        $event->setEventType('EARNINGS');
        $event->setDescription($description);
        $event->setChangePercent((string) round($surprisePct * 100, 2));

        $this->entityManager->persist($event);

        echo "\n [!] BREAKING NEWS: {$stock->getTicker()} reported earnings! ({$beatOrMiss} expectations by $" . abs($surpriseAmount) . ")\n";

        return [
            'type' => 'EARNINGS',
            'ticker' => $stock->getTicker(),
            'description' => $description,
            'change_percent' => round($surprisePct * 100, 2)
        ];
    }
}