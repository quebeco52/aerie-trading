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

            $z = $this->mathUtility->generateStandardNormal();

            $volatility = (float) $stock->getVolatility();
            $earningsSurprise = ($volatility / 2) * $z;

            // 60% Beta / 40% Volatility + Saturation
            $beta = (float) $stock->getBeta();
            $baselineVol = (float) $stock->getVolatility(); // Use baseline, not the Heston spiked

            // Normalize Volatility (Average market vol is ~0.20, so 0.20 becomes a score of 1.0)
            $volScore = $baselineVol / 0.20;

            // Blend Systematic Risk (Beta) with Idiosyncratic Risk (Vol)
            $riskMultiplier = (0.60 * $beta) + (0.40 * $volScore);
            
            // The Law of Large Numbers: Massive companies grow slower
            $currentEpsForMath = max(0.10, abs($oldEps));
            $saturationPenalty = max(1.0, log10($currentEpsForMath / 20) + 1.0); 

            // Base 2% quarterly growth, scaled by our 60/40 risk blend, dampened by size
            $quarterlyGrowth = (0.02 * $riskMultiplier) / $saturationPenalty;

            $pctChangeDecimal = $quarterlyGrowth + $earningsSurprise;
            $pctChange = round($pctChangeDecimal * 100, 2);

            $currentVol = (float) $stock->getCurrentVolatility();
            $baselineVol = (float) $stock->getVolatility();

            if (abs($pctChange) > 10.0) {
                // MASSIVE SURPRISE: Volatility explodes
                // Cap the explosion at 3x the baseline to prevent the math from breaking
                $newVol = min($currentVol * 1.5, $baselineVol * 3.0);
                $stock->setCurrentVolatility((string) $newVol);
            } else {
                // EXPECTED RESULT: "Vol Crush" - Uncertainty is removed
                $stock->setCurrentVolatility((string) $baselineVol);
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

            echo "\n BREAKING NEWS: {$stock->getTicker()} just released earnings! ({$pctChange}%)\n";

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
