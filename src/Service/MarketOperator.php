<?php

namespace App\Service;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The "Invisible Hand" of the Aerie District.
 * Runs periodically to prevent the math models from destroying the economy.
 */
class MarketOperator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private MarketEvent $marketEvent
    ) {}

    /**
     * Wakes up periodically to enforce the laws of game-design physics.
     */
    public function enforceMarketStability(array $stocks): array
    {
        $this->logger->info("The Market Operator is reviewing the district...");
        $generatedEvents = [];

        // Calculate the Total Market Cap of the entire district on the fly
        $totalMarketCap = 0;
        foreach ($stocks as $stock) {
            $totalMarketCap += ((float) $stock->getPrice()) * ((int) $stock->getSharesOutstanding());
        }
        // Fallback to prevent division by zero in case of total economic collapse
        if ($totalMarketCap <= 0) $totalMarketCap = 1;

        foreach ($stocks as $stock) {
            $price = (float) $stock->getPrice();
            $shares = (int) $stock->getSharesOutstanding();
            $marketCap = $price * $shares;
            $eps = (float) $stock->getEarningsPerShare();
            $name = $stock->getName();
            $systemicImportance = $stock->getSystemicImportance() ?? 'none';

            // RULE : bailouts

            $bailoutFloor = 0.0;
            $bailoutMultiplier = 1.0;
            $bailoutTier = null;

            switch ($systemicImportance) {
                case 'titan':
                    $bailoutFloor = $totalMarketCap * 0.04; // Floor is 4% of the index economy
                    $bailoutMultiplier = 1.03;
                    $bailoutTier = 'TITAN PROTECTION';
                    break;
                case 'systemic':
                    $bailoutFloor = $totalMarketCap * 0.015; // Floor is 1.5% of the index economy
                    $bailoutMultiplier = 1.02;
                    $bailoutTier = 'SYSTEMIC BAILOUT';
                    break;
                case 'base':
                    $bailoutFloor = $totalMarketCap * 0.01; // Floor is 1% of the index economy
                    $bailoutMultiplier = 1.02;
                    $bailoutTier = 'BASE CLASS BAILOUT';
                    break;
            }

            if ($bailoutTier && $marketCap < $bailoutFloor) {
                $stock->setPrice((string) ($price * $bailoutMultiplier));

                // Gradually push EPS up to $0.40 if it falls below, otherwise buff by the multiplier
                $newEps = $eps < 0.4 ? min(0.4, $eps + 0.20) : $eps * $bailoutMultiplier;
                $stock->setEarningsPerShare((string) round($newEps, 2));

                $this->logger->info("{$bailoutTier}: {$stock->getTicker()} subsidized (Fell below dominance floor).");
            }
            
            

            // RULE : THE RESTRUCTURING (Hostile Takeover vs. White Knight Bailout)
            if ($marketCap < 1000000000) {


                // Roll the dice: 50% chance for Swan (Hostile), 50% chance for Lakebird (Savior)
                $isHostile = mt_rand(1, 100) > 50;

                if ($isHostile) {
                    // BLACK SWAN CAPITAL (Hostile)
                    $this->logger->info("BANKRUPTCY DETECTED: {$stock->getTicker()} collapsed. Black Swan Capital initiating hostile liquidation.");

                    // Vaporize the players shares 0
                    $this->entityManager->getConnection()->executeStatement(
                        'UPDATE user_stocks SET quantity = 0, version = version + 1 WHERE stock_id = :id',
                        ['id' => $stock->getId()]
                    );

                    // The Restructuring Stats
                    $stock->setPrice("50.00");
                    $stock->setSharesOutstanding("1000000000"); // 1B shares
                    $stock->setEarningsPerShare((string) (mt_rand(325, 433) / 100));


                    $event1Desc = "{$name} ({$stock->getTicker()}) was liquidated in a hostile takeover by Black Swan Capital. Shareholder equity wiped to 0.";
                    $event2Desc = "Black Swan Capital has stripped {$stock->getTicker()} of its assets and relisted the hollowed-out shell at $50.00.";
                } else {
                    //  LAKEBIRD BANK (Savior)
                    $this->logger->info("BANKRUPTCY IMMINENT: {$stock->getTicker()} collapsing. Lakebird Bank initiating a bailout.");

                    // Dilute the players shares to 0
                    $this->entityManager->getConnection()->executeStatement(
                        'UPDATE user_stocks SET quantity = 0, version = version + 1 WHERE stock_id = :id',
                        ['id' => $stock->getId()]
                    );

                    // The Restructuring Stats
                    $stock->setPrice("50.00");
                    $stock->setSharesOutstanding("1000000000"); // 1B shares
                    $stock->setEarningsPerShare((string) (mt_rand(325, 433) / 100));


                    $event1Desc = "{$name} ({$stock->getTicker()}) secured a last-minute emergency bailout from Lakebird Bank. Retail shares diluted to secure funding.";
                    $event2Desc = "Lakebird Bank has stabilized {$stock->getTicker()}'s balance sheet. Trading resumes at $50.00.";
                }

                // Erase the historical chart data for the fresh start
                $this->entityManager->getConnection()->executeStatement(
                    'DELETE FROM stock_history WHERE stock_id = :id',
                    ['id' => $stock->getId()]
                );


                // Record the Events for the News Feed
                $generatedEvents[] = $this->marketEvent->publish($stock, 'BANKRUPTCY', $event1Desc, -100.00);
                $generatedEvents[] = $this->marketEvent->publish($stock, 'BAILOUT', $event2Desc, 0.00);

                // Skip the rest of the checks for this stock since it was just reset
                continue;
            }

            // RULE : The Market Dominance Rubber Band (Law of Large Numbers)
            $dominanceRatio = $marketCap / $totalMarketCap;

            // Soft cap: Gravity starts pulling at 15% of the total index
            // Hard cap: Maximum gravity applied at 25% of the total index
            $softCap = 0.15; 
            $hardCap = 0.25;

            if ($dominanceRatio > $softCap) {
                $excess = ($dominanceRatio - $softCap) / ($hardCap - $softCap);
                $excess = min(1.0, max(0.0, $excess));

                // Progressive gravity: 0% at soft cap, max 2.5% drag per tick at hard cap
                $maxDrag = 0.025; 
                $gravityPull = $excess * $maxDrag;

                // CHECK P/E RATIO
                $peRatio = $eps > 0 ? $price / $eps : 999;
                
                // If P/E is healthy, apply EPS drag (Bureaucracy) small price drag
                if ($peRatio < 35.0 && $eps > 0) {
                    $stock->setEarningsPerShare((string) ($eps * (1.0 - $gravityPull)));
                    $stock->setPrice((string) ($price * (1.0 - ($gravityPull / 2.0))));
                    $dragType = "EPS";
                } else {
                    // If P/E is a hype bubble, apply Price drag (Multiple Compression)
                    $stock->setPrice((string) ($price * (1.0 - $gravityPull)));
                    $dragType = "Price";
                }
                
                $pct = round($dominanceRatio * 100, 2);
                $this->logger->info("GRAVITY WELL: {$stock->getTicker()} {$dragType} rubber-banded (Dominance: {$pct}%, PE: " . round($peRatio, 1) . ")");
            }

            // RULE : Volatility Dampening

            $currentVol = (float) $stock->getCurrentVolatility();
            if ($currentVol > 1.50) {
                $stock->setCurrentVolatility((string) ($currentVol * 0.90));
            }
        }

        return $generatedEvents;
    }
}
