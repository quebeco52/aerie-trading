<?php

namespace App\Service;

use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Entity\StockHistory;
use App\Data\SectorPE;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service responsible for tracking and updating stock prices.
 *
 * This service orchestrates the market simulation for individual stocks.
 * It calculates new prices based on market conditions, handles random market shocks,
 * processes earnings events, and persists historical data.
 */
class StockTracker
{
    /**
     * @param EntityManagerInterface $entityManager The Doctrine Entity Manager
     * @param MarketEngine           $marketEngine  Service for calculating stock price movements
     * @param EarningsEngine         $earningsEngine Service for simulating earnings reports
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketEngine $marketEngine,
        private EarningsEngine $earningsEngine
    ) {
    }

    /**
     * Updates all tracked stocks for a given time step.
     *
     * This method fetches all stocks, applies market noise and specific stock volatility
     * to calculate new prices, checks for market shocks and earnings events,
     * and persists the updates and history to the database.
     *
     * @param float $dt The time step for the simulation (e.g., fraction of a year).
     *
     * @return array{
     *     updates: list<array{ticker: string, price: float, market_cap: float}>,
     *     total_cap: float,
     *     events: list<array>
     * } An array containing stock updates, total market capitalization, and any events that occurred.
     */
    public function updateStocks(float $dt): array
    {
        // Fetch all Stock entities from the database
        $stocks = $this->entityManager->getRepository(Stock::class)->findAll();

        $stockUpdates = [];
        $totalMarketCap = 0.0;
        $events = [];

        // Market noise calculation
        do {
            $mx = mt_rand() / mt_getrandmax();
            $my = mt_rand() / mt_getrandmax();
        } while ($mx <= 0);
        $marketZ = sqrt(-2 * log($mx)) * cos(2 * M_PI * $my);
        $marketVol = 0.15;
        $marketNoise = $marketVol * sqrt($dt) * $marketZ;

        foreach ($stocks as $stock) {
            // Target PE fallback
            $targetPE = SectorPE::TARGETS[$stock->getSector()] ?? 20.0;

            // Calculate new price
            $calculation = $this->marketEngine->calculateNextPrice(
                (float) $stock->getPrice(),
                (float) $stock->getEarningsPerShare(),
                $targetPE,
                (float) $stock->getVolatility(),
                $dt,
                0.1, // Drift
                (float) $stock->getJumpIntensity(),
                (float) $stock->getJumpMean(),
                (float) $stock->getJumpVol(),
                (float) $stock->getBeta(),
                $marketNoise,
                0.3 // Reversion speed
            );

            $newPrice = $calculation['price'];

            // Handle Market Shocks via Doctrine Entities
            if ($calculation['shock'] !== null) {
                $color = $calculation['shock'] > 0 ? "\033[32m" : "\033[31m";
                echo " [!] {$color}MARKET SHOCK on {$stock->getTicker()}: " . number_format($calculation['shock'], 2) . "% \033[0m\n";

                $event = new StockEvent();
                $event->setStock($stock);
                $event->setEventType('SHOCK');
                $event->setDescription("Sudden market shock detected.");
                $event->setChangePercent((string) $calculation['shock']);
                
                // Save this new entity
                $this->entityManager->persist($event);

                $events[] = [
                    'type' => 'SHOCK',
                    'ticker' => $stock->getTicker(),
                    'change_percent' => round($calculation['shock'], 2)
                ];
            }

            // (You'll need to update EarningsEngine to use Entities just like we did above)
            $earningsEvent = $this->earningsEngine->calculate($stock, $dt);
            if ($earningsEvent) {
                $events[] = $earningsEvent;
            }

            // 4. Update the Stock Object
            $stock->setPrice((string) $newPrice);

            // 5. Create the History Record
            $history = new StockHistory();
            $history->setStock($stock);
            $history->setPrice((string) $newPrice);
            $this->entityManager->persist($history);

            // Calculate Market Cap
            $currentMarketCap = $newPrice * (float) $stock->getSharesOutstanding();
            $totalMarketCap += $currentMarketCap;

            $stockUpdates[] = [
                'ticker' => $stock->getTicker(),
                'price' => round($newPrice, 2),
                'market_cap' => $currentMarketCap,
            ];
        }

        // Executes all the SELECTs, UPDATEs, and INSERTs.
        $this->entityManager->flush();

        return [
            'updates' => $stockUpdates,
            'total_cap' => $totalMarketCap,
            'events' => $events
        ];
    }
}