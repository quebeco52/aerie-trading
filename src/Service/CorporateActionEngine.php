<?php

namespace App\Service;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service responsible for executing corporate actions like stock splits.
 * It adjusts the underlying corporate math and securely patches user portfolios.
 */
class CorporateActionEngine
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketEvent $marketEvent
    ) {}

    /**
     * Evaluates a stock's price and executes a split or reverse-split if necessary.
     *
     * @return array{price: float, eps: float, shares: int, event: array<string, mixed>|null} Contains the potentially modified price, eps, shares, and any event generated.
     */
    public function processSplits(Stock $stock, float $newPrice, float $newEps, int $sharesOutstanding): array
    {
        $splitEvent = null;

  
        // FORWARD SPLIT

        $splitFactor = 1;
        while ($newPrice >= 400.0) {
            $newPrice = $newPrice / 4.0;
            $newEps = $newEps / 4.0;
            $splitFactor *= 4;
        }

        if ($splitFactor > 1) {
            $sharesOutstanding *= $splitFactor;
            $desc = "{$stock->getName()} has executed a {$splitFactor}-for-1 stock split.";
            $splitEvent = $this->marketEvent->publish($stock, 'SPLIT', $desc, 0.00);
            
            $this->entityManager->flush();

            // Safely multiply the players' shares by the dynamic factor
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE user_stocks SET quantity = quantity * :factor, version = version + 1 WHERE stock_id = :stock_id',
                ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
            );

            // Retroactively divide all historical chart prices by the dynamic factor
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE stock_history SET price = GREATEST(price / :factor, 0.00000001) WHERE stock_id = :stock_id',
                ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
            );
        }
        

        // REVERSE SPLIT

        $reverseFactor = 1;
        // Keep a snapshot of the price BEFORE the math changes for the cash refunds
        $preSplitPrice = $newPrice; 

        while ($newPrice < 2.0) {
            $newPrice = $newPrice * 10.0;
            $newEps = $newEps * 10.0;
            $reverseFactor *= 10;
        }

        // Clamp EPS to prevent SQL DECIMAL(20,8) out of range errors
        $newEps = max(-99999999999.0, min(99999999999.0, $newEps));

        if ($reverseFactor > 1) {
            $sharesOutstanding = max(1, (int)($sharesOutstanding / $reverseFactor));
            $desc = "{$stock->getName()} executed a 1-for-{$reverseFactor} reverse split.";
            $splitEvent = $this->marketEvent->publish($stock, 'REVSPLIT', $desc, 0.00);
            
            $this->entityManager->flush();

            // CASH IN LIEU: Refund users for the fractional shares they are about to lose
            // (Quantity Modulo Factor) gives us the exact number of fractional shares left over.
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE users u
                 INNER JOIN user_stocks us ON u.id = us.user_id
                 SET u.cash_balance = u.cash_balance + ((us.quantity % :factor) * :pre_split_price)
                 WHERE us.stock_id = :stock_id',
                [
                    'factor' => $reverseFactor, 
                    'pre_split_price' => $preSplitPrice, 
                    'stock_id' => $stock->getId()
                ]
            );

            // Safely divide players' shares by the dynamic factor using FLOOR
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE user_stocks SET quantity = FLOOR(quantity / :factor), version = version + 1 WHERE stock_id = :stock_id',
                ['factor' => $reverseFactor, 'stock_id' => $stock->getId()]
            );

            // CLEANUP: Delete the user_stock row entirely if their quantity dropped to 0
            $this->entityManager->getConnection()->executeStatement(
                'DELETE FROM user_stocks WHERE stock_id = :stock_id AND quantity = 0',
                ['stock_id' => $stock->getId()]
            );

            // Retroactively multiply all historical chart prices by the dynamic factor
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE stock_history SET price = LEAST(price * :factor, 900000000000.0) WHERE stock_id = :stock_id',
                ['factor' => $reverseFactor, 'stock_id' => $stock->getId()]
            );
        }

        return [
            'price' => $newPrice,
            'eps' => $newEps,
            'shares' => $sharesOutstanding,
            'event' => $splitEvent
        ];
    }

    /**
     * Executes the Lintner Dividend Model and FCF Share Buybacks using a pure Cash Flow approach.
     */
    public function allocateCapital(
        Stock $stock, 
        float $actualAnnualEps, 
        float $quarterlyFcfPerShare, 
        float $currentPrice, 
        int $sharesOutstanding,
        float $liveTargetPE
    ): array {
        $events = [];
        
        $targetPayout = (float) $stock->getTargetPayoutRatio();
        $speed = (float) $stock->getDividendSpeed();
        $lastDividend = (float) $stock->getLastDividend();

        // A company targets a dividend based on its QUARTERLY earnings
        $quarterlyEps = $actualAnnualEps / 4.0;
        $targetDividend = $quarterlyEps > 0 ? ($quarterlyEps * $targetPayout) : 0.0;

        // The Lintner Formula: Smooths out the volatility of earnings
        $newDividend = $lastDividend + ($speed * ($targetDividend - $lastDividend));
        $newDividend = max(0.0, round($newDividend, 4));

        // Strict Cash Flow Failsafe: A company cannot pay more cash than it generated this quarter.
        $newDividend = min($newDividend, max(0.0, $quarterlyFcfPerShare));

        if ($newDividend > 0.0) {
            // PAY TO USERS
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE users u
                 INNER JOIN user_stocks us ON u.id = us.user_id
                 SET u.cash_balance = u.cash_balance + (us.quantity * :dividend)
                 WHERE us.stock_id = :stock_id',
                ['dividend' => $newDividend, 'stock_id' => $stock->getId()]
            );

            $stock->setLastDividend((string) $newDividend);
            
            // Financial Standard: Yields are always reported Annualized!
            $annualizedYield = (($newDividend * 4) / max($currentPrice, 0.01)) * 100;
            $events[] = $this->marketEvent->publish($stock, 'DIVIDEND', "{$stock->getTicker()} distributed a quarterly dividend of $" . number_format($newDividend, 2) . "/share (Yield: " . number_format($annualizedYield, 2) . "%).", 0.0);
        } else {
            $stock->setLastDividend('0.00');
        }

        // SHARE BUYBACKS (EPS ACCRETION)
        $remainingFcfPerShare = $quarterlyFcfPerShare - $newDividend;
        $newAnnualEps = $actualAnnualEps;

        // Calculate the company's current P/E ratio
        $currentPE = $actualAnnualEps > 0 ? ($currentPrice / $actualAnnualEps) : 9999;
        
        if ($remainingFcfPerShare > 0.0 && $currentPE < ($liveTargetPE + 2.0)) {
            $totalBuybackCash = $remainingFcfPerShare * $sharesOutstanding;
            
            $sharesRepurchased = (int) ($totalBuybackCash / max($currentPrice, 0.01));

            $maxAllowableRepurchase = (int) ($sharesOutstanding * 0.015);
            $sharesRepurchased = min($sharesRepurchased, $maxAllowableRepurchase);

            if ($sharesRepurchased > 0) {
                $sharesOutstanding -= $sharesRepurchased;

                // Mathematical EPS Accretion
                $annualNetIncome = $actualAnnualEps * ($sharesOutstanding + $sharesRepurchased);
                $newAnnualEps = $annualNetIncome / max($sharesOutstanding, 1);

                $pctRetired = ($sharesRepurchased / ($sharesOutstanding + $sharesRepurchased)) * 100;
                
                $events[] = $this->marketEvent->publish($stock, 'BUYBACK', "{$stock->getTicker()} executed a stock buyback, retiring " . number_format($sharesRepurchased) . " shares. EPS accreted to $" . number_format($newAnnualEps, 2) . ".", $pctRetired);
            }
        }

        $this->entityManager->flush();

        return [
            'new_eps' => round($newAnnualEps, 2),
            'new_shares' => $sharesOutstanding,
            'dividend_paid' => $newDividend,
            'events' => $events
        ];
    }
}