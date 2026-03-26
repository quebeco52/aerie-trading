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

        foreach ($stocks as $stock) {
            $price = (float) $stock->getPrice();
            $shares = (int) $stock->getSharesOutstanding();
            $marketCap = $price * $shares;
            $eps = (float) $stock->getEarningsPerShare();
            $name = $stock->getName();
            $ticker = $stock->getTicker();

            // RULE 1: THE OLIGARCHY (Plot Armor for the Titans LAKE, SWAN and BRKW)
            if (in_array($ticker, ['LAKE', 'SWAN', 'BRKW'])) {
                if ($marketCap < 600000000000) { // $600B Floor
                    $stock->setPrice((string) ($price * 1.03));
                    
                    // Gradually push EPS up to $1.00 if it falls below, otherwise buff by 3%
                    $newEps = $eps < 1.0 ? min(1.0, $eps + 0.20) : $eps * 1.03;
                    $stock->setEarningsPerShare((string) $newEps); 
                    
                    $this->logger->info("TITAN PROTECTION: {$ticker} subsidized by the District.");
                    
                    // Flush to DB
                    $this->entityManager->flush(); 

                }
            }

            // RULE 2: second class protection ()
            if (in_array($ticker, ['IBHI', 'KING', 'PERE', 'OWLS', 'SHRK', 'VULT', 'SAFE'])) {
                if ($marketCap < 200000000000) { // $200B Floor
                    $stock->setPrice((string) ($price * 1.01));
                    
                    // Gradually push EPS up to $1.00 if it falls below, otherwise buff by 2%
                    $newEps = $eps < 1.0 ? min(1.0, $eps + 0.20) : $eps * 1.02;
                    $stock->setEarningsPerShare((string) $newEps); 
                    
                    $this->logger->info("TITAN PROTECTION: {$ticker} subsidized by the District.");
                    
                    // Flush to DB
                    $this->entityManager->flush(); 

                }
            }
            
            

            // RULE 3: THE RESTRUCTURING (Hostile Takeover vs. White Knight Bailout)

            if ($marketCap < 1000000000) {


                // Roll the dice: 50% chance for Swan (Hostile), 50% chance for Lakebird (Savior)
                $isHostile = mt_rand(1, 100) > 50;

                // FLUSH RAM TO DB BEFORE RAW SQL! (Prevents the vertical chart spike bug)
                $this->entityManager->flush();

                if ($isHostile) {
                    // BLACK SWAN CAPITAL (Hostile)
                    $this->logger->info("BANKRUPTCY DETECTED: {$ticker} collapsed. Black Swan Capital initiating hostile liquidation.");

                    // Vaporize the players shares 0
                    $this->entityManager->getConnection()->executeStatement(
                        'UPDATE user_stocks SET quantity = 0, version = version + 1 WHERE stock_id = :id',
                        ['id' => $stock->getId()]
                    );

                    // The Restructuring Stats
                    $stock->setPrice("50.00");
                    $stock->setSharesOutstanding("1000000000"); // 1B shares
                    $stock->setEarningsPerShare((string) (mt_rand(325, 433) / 100));


                    $event1Desc = "{$name} ({$ticker}) was liquidated in a hostile takeover by Black Swan Capital. Shareholder equity wiped to 0.";
                    $event2Desc = "Black Swan Capital has stripped {$ticker} of its assets and relisted the hollowed-out shell at $50.00.";
                } else {
                    //  LAKEBIRD BANK (Savior)
                    $this->logger->info("BANKRUPTCY IMMINENT: {$ticker} collapsing. Lakebird Bank initiating a bailout.");

                    // Dilute the players shares to 0
                    $this->entityManager->getConnection()->executeStatement(
                        'UPDATE user_stocks SET quantity = 0, version = version + 1 WHERE stock_id = :id',
                        ['id' => $stock->getId()]
                    );

                    // The Restructuring Stats
                    $stock->setPrice("50.00");
                    $stock->setSharesOutstanding("1000000000"); // 1B shares
                    $stock->setEarningsPerShare((string) (mt_rand(325, 433) / 100));


                    $event1Desc = "{$name} ({$ticker}) secured a last-minute emergency bailout from Lakebird Bank. Retail shares diluted to secure funding.";
                    $event2Desc = "Lakebird Bank has stabilized {$ticker}'s balance sheet. Trading resumes at $50.00.";
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

            // RULE 4: The Anti-Hyperinflation Gravity Well
            if ($marketCap > 20000000000000) { // $20 Trillion
                $stock->setEarningsPerShare((string) ($eps * 0.98));
                $this->logger->info("{$ticker} cut down");
            }

            // RULE 5: Volatility Dampening

            $currentVol = (float) $stock->getCurrentVolatility();
            if ($currentVol > 1.50) {
                $stock->setCurrentVolatility((string) ($currentVol * 0.90));
            }
        }

        return $generatedEvents;
    }
}
