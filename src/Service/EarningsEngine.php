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
        private EntityManagerInterface $entityManager
    ) {
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

            // Box-Muller transform for normal distribution
            do {
                $x = mt_rand() / mt_getrandmax();
                $y = mt_rand() / mt_getrandmax();
            } while ($x <= 0);
            $z = sqrt(-2 * log($x)) * cos(2 * M_PI * $y);

            $volatility = (float) $stock->getVolatility();
            $earningsSurprise = ($volatility / 2) * $z;
            
            // 2% per quarter = ~8% annual corporate earnings growth
            $quarterlyGrowth = 0.02;

            $pctChangeDecimal = $quarterlyGrowth + $earningsSurprise;
            $pctChange = round($pctChangeDecimal * 100, 2);

            $changeAmount = abs($oldEps) * $pctChangeDecimal;
            $newEps = round($oldEps + $changeAmount, 2);
            
            // 1. Update the Stock Entity
            $stock->setEarningsPerShare((string) $newEps);

            $description = "EPS Update: $" . number_format($oldEps, 2) . " -> $" . number_format($newEps, 2);

            // 2. Create and Persist the Event Entity
            $event = new StockEvent();
            $event->setStock($stock);
            $event->setEventType('EARNINGS');
            $event->setDescription($description);
            $event->setChangePercent((string) $pctChange);
            
            $this->entityManager->persist($event);

            echo "\n 🗞️ BREAKING NEWS: {$stock->getTicker()} just released earnings! ({$pctChange}%)\n";

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