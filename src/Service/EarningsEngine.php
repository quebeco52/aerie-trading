<?php

namespace App\Service;

use App\Entity\Stock;
use App\Entity\StockEvent;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Handles the simulation of quarterly earnings reports.
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

    /**
     * Determines if an earnings event occurs and calculates the result.
     *
     * @param Stock $stock The stock entity to evaluate and update.
     * @param float $dt    Time step in years.
     *
     * @return array|null The event data if earnings occurred, or null.
     */
    public function calculate(Stock $stock, float $dt): ?array
    {
        // Quarterly Earnings (4 times per year)
        if ((mt_rand() / mt_getrandmax()) < (4.0 * $dt)) {
            $oldEps = (float) $stock->getEarningsPerShare();
            
            // The Law of Large Numbers: Massive companies grow slower
            $currentEpsForMath = max(0.10, abs($oldEps));
            $saturationPenalty = max(1.0, log10($currentEpsForMath / 60) + 1.0); 

            // Base economic drift
            $baseQuarterlyDrift = 0.02 / $saturationPenalty;

            // Convert annual baseline volatility to quarterly volatility 
            // (Volatility scales with the square root of time: sqrt(0.25) = 0.5)
            $baselineVol = (float) $stock->getVolatility();
            $quarterlyVol = $baselineVol * 0.5; 

            // Generate a random Z-score for this specific quarter's business performance
            $businessZ = $this->mathUtility->generateStandardNormal();

            // Calculate the actual earnings growth percentage for this quarter
            $pctChangeDecimal = $baseQuarterlyDrift + ($quarterlyVol * $businessZ);
            $pctChange = round($pctChangeDecimal * 100, 2);

            // VOLATILITY SHOCK
            $currentVol = (float) $stock->getCurrentVolatility();
            
            if (abs($businessZ) > 1.5) {
                // MASSIVE SURPRISE: Increase Volatility based on how extreme the Z-score was.
                // A Z-score of 2.0 means a 20% increase in volatility (2.0 * 0.10 = 0.20)
                $shockMultiplier = 1.0 + (abs($businessZ) * 0.15); 
                
                // Apply the shock, but cap the explosion at 3x the baseline
                $newVol = min($currentVol * $shockMultiplier, $baselineVol * 3.0);
                $stock->setCurrentVolatility((string) $newVol);
            }
            
            // Treat the EPS as at least $1.00 when calculating the raw dollar movement
            // This prevents penny stocks from getting permanently stuck due to rounding
            $effectiveEpsForChange = max(abs($oldEps), 1.00);
            $changeAmount = $effectiveEpsForChange * $pctChangeDecimal;

            // If the company is bleeding money, they ruthlessly cut costs to return to profitability
            if ($oldEps < 0) {
                $recoveryBoost = abs($oldEps) * 0.10; // Cut 10% of losses per quarter
                $changeAmount += $recoveryBoost;
            }

            $newEps = round($oldEps + $changeAmount, 2);

            // Update the Stock Entity
            $stock->setEarningsPerShare((string) $newEps);

            $description = "EPS Update: $" . number_format($oldEps, 2) . " -> $" . number_format($newEps, 2);

            // Create and Persist the Event Entity
            $event = new StockEvent();
            $event->setStock($stock);
            $event->setEventType('EARNINGS');
            $event->setDescription($description);
            $event->setChangePercent((string) $pctChange);

            $this->entityManager->persist($event);

            echo "\n [!] BREAKING NEWS: {$stock->getTicker()} reported earnings! ({$pctChange}%)\n";

            return [
                'type' => 'EARNINGS',
                'ticker' => $stock->getTicker(),
                'description' => $description,
                'change_percent' => $pctChange
            ];
        }

        return null;
    }
}