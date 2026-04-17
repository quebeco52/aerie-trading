<?php

namespace App\Service;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service responsible for executing corporate actions.
 * Handles Splits, Dividends, Buybacks, and Clean Surplus Balance Sheet updates.
 */
class CorporateActionEngine
{
    /**
     * Constructor.
     *
     * @param EntityManagerInterface $entityManager The Doctrine entity manager.
     * @param MarketEvent            $marketEvent   Service for publishing market events.
     * @param \Redis                 $redis         The Redis connection for managing live chart buffers.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketEvent $marketEvent,
        private \Redis $redis
    ) {}


    /**
     * Evaluates and processes potential stock splits based on the current price.
     *
     * Triggers a forward split if the price exceeds $400, or a reverse split
     * if the price falls below $2. Ensures the stock remains highly liquid and
     * tradeable without altering the underlying corporate value.
     *
     * @param Stock $stock             The stock entity to evaluate.
     * @param float $newPrice          The proposed new price.
     * @param float $sharesOutstanding The current number of shares outstanding.
     * @return array{price: float, shares: float, event: array|null} The adjusted price, shares, and any generated event.
     */
    public function processSplits(Stock $stock, float $newPrice, float $sharesOutstanding): array
    {
        $splitEvent = null;

        if ($newPrice >= 400.0) {
            $result = $this->executeForwardSplit($stock, $newPrice, $sharesOutstanding);
            $newPrice = $result['price'];
            $sharesOutstanding = $result['shares'];
            $splitEvent = $result['event'];
        } elseif ($newPrice < 2.0) {
            $result = $this->executeReverseSplit($stock, $newPrice, $sharesOutstanding);
            $newPrice = $result['price'];
            $sharesOutstanding = $result['shares'];
            $splitEvent = $result['event'];
        }

        return [
            'price' => $newPrice,
            'shares' => $sharesOutstanding,
            'event' => $splitEvent
        ];
    }


    /**
     * Executes a recursive forward split (e.g., 4-for-1) to bring the price back below $400.
     *
     * @param Stock $stock The stock entity.
     * @param float $newPrice The price that breached the upper threshold.
     * @param float $sharesOutstanding The current shares outstanding.
     * @return array{price: float, shares: float, event: array}
     */
    private function executeForwardSplit(Stock $stock, float $newPrice, float $sharesOutstanding): array
    {
        $splitFactor = 1;
        while ($newPrice >= 400.0) {
            $newPrice = $newPrice / 4.0;
            $splitFactor *= 4;
        }

        $sharesOutstanding *= $splitFactor;
        
        $stock->setSharesOutstanding((string) $sharesOutstanding);
        $stock->setPrice((string) $newPrice);

        $desc = "{$stock->getName()} has executed a {$splitFactor}-for-1 stock split.";
        $splitEvent = $this->marketEvent->publish($stock, 'SPLIT', $desc, 0.00);

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE user_stocks SET quantity = quantity * :factor, version = version + 1 WHERE stock_id = :stock_id',
            ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
        );

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE stock_history SET price = GREATEST(price / :factor, 0.00000001) WHERE stock_id = :stock_id',
            ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
        );

        $this->adjustRedisBuffer($stock->getTicker(), $splitFactor, 'divide');

        return ['price' => $newPrice, 'shares' => $sharesOutstanding, 'event' => $splitEvent];
    }


    /**
     * Executes a recursive reverse split (e.g., 1-for-10) to bring the price back above $2.
     * Cashes out fractional shares directly to user accounts to prevent loss of wealth.
     *
     * @param Stock $stock The stock entity.
     * @param float $newPrice The price that breached the lower threshold.
     * @param float $sharesOutstanding The current shares outstanding.
     * @return array{price: float, shares: float, event: array}
     */
    private function executeReverseSplit(Stock $stock, float $newPrice, float $sharesOutstanding): array
    {
        $reverseFactor = 1;
        $preSplitPrice = $newPrice; 

        while ($newPrice < 2.0) {
            $newPrice = $newPrice * 10.0;
            $reverseFactor *= 10;
        }

        $sharesOutstanding = max(1.0, $sharesOutstanding / $reverseFactor);
        
        $stock->setSharesOutstanding((string) $sharesOutstanding);
        $stock->setPrice((string) $newPrice);

        $desc = "{$stock->getName()} executed a 1-for-{$reverseFactor} reverse split.";
        $splitEvent = $this->marketEvent->publish($stock, 'REVSPLIT', $desc, 0.00);

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE users u
             INNER JOIN user_stocks us ON u.id = us.user_id
             SET u.cash_balance = u.cash_balance + ((us.quantity % :factor) * :pre_split_price)
             WHERE us.stock_id = :stock_id',
            ['factor' => $reverseFactor, 'pre_split_price' => $preSplitPrice, 'stock_id' => $stock->getId()]
        );

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE user_stocks SET quantity = FLOOR(quantity / :factor), version = version + 1 WHERE stock_id = :stock_id',
            ['factor' => $reverseFactor, 'stock_id' => $stock->getId()]
        );

        $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM user_stocks WHERE stock_id = :stock_id AND quantity = 0',
            ['stock_id' => $stock->getId()]
        );

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE stock_history SET price = LEAST(price * :factor, 900000000000.0) WHERE stock_id = :stock_id',
            ['factor' => $reverseFactor, 'stock_id' => $stock->getId()]
        );

        $this->adjustRedisBuffer($stock->getTicker(), $reverseFactor, 'multiply');

        return ['price' => $newPrice, 'shares' => $sharesOutstanding, 'event' => $splitEvent];
    }


    /**
     * Mutates the live Redis chart buffer to prevent massive visual vertical spikes on the UI during a split.
     *
     * @param string $ticker The stock ticker symbol.
     * @param float $factor The split factor.
     * @param string $operation The mathematical operation to apply ('divide' for forward splits, 'multiply' for reverse splits).
     */
    private function adjustRedisBuffer(string $ticker, float $factor, string $operation): void
    {
        $cacheKey = "chart_buffer:{$ticker}";
        $redisData = $this->redis->lRange($cacheKey, 0, -1);
        
        if (empty($redisData)) return;

        $this->redis->del($cacheKey);
        
        foreach (array_reverse($redisData) as $jsonStr) {
            $point = json_decode($jsonStr, true);
            if ($operation === 'divide') {
                $point['price'] = max(0.01, $point['price'] / $factor);
            } else {
                $point['price'] = $point['price'] * $factor;
            }
            $this->redis->lPush($cacheKey, json_encode($point));
        }
    }


    /**
     * Allocates quarterly capital via dividends and buybacks, and updates the balance sheet.
     *
     * This method acts as the CFO, determining how much of the generated Free Cash Flow
     * should be returned to shareholders versus retained for future stability.
     *
     * @param Stock $stock             The stock entity allocating capital.
     * @param float $actualAnnualEps   The actual annualized EPS calculated this quarter.
     * @param float $quarterlyFcfPerShare The generated Free Cash Flow per share for the quarter.
     * @param float $currentPrice      The current market price of the stock.
     * @param float $sharesOutstanding The total shares currently outstanding.
     * @param float $liveTargetPE      The live target P/E ratio for the stock's sector.
     * @return array{new_shares: float, dividend_paid: float, events: array<mixed>} Data regarding the capital allocation.
     */
    public function allocateCapital(
        Stock $stock, 
        float $actualAnnualEps, 
        float $quarterlyFcfPerShare, 
        float $currentPrice, 
        float $sharesOutstanding,
        float $liveTargetPE
    ): array {
        $events = [];
        $oldShares = $sharesOutstanding;
        $quarterlyEps = $actualAnnualEps / 4.0;

        $divData = $this->executeDividends($stock, $quarterlyEps, $quarterlyFcfPerShare, $oldShares, $currentPrice);
        if ($divData['event']) $events[] = $divData['event'];

        $buybackData = $this->executeBuybacks(
            $stock, 
            $quarterlyFcfPerShare, 
            $divData['dividend_per_share'], 
            $oldShares, 
            $currentPrice, 
            $actualAnnualEps, 
            $liveTargetPE
        );
        if ($buybackData['event']) $events[] = $buybackData['event'];

        $this->updateBalanceSheet(
            stock: $stock,
            quarterlyNetIncome: $quarterlyEps * $oldShares,
            totalDividendsPaid: $divData['total_paid'],
            totalBuybackCash: $buybackData['total_cash_spent'],
            totalFcfGenerated: $quarterlyFcfPerShare * $oldShares,
            currentPrice: $currentPrice,
            newSharesOutstanding: $buybackData['new_shares']
        );

        return [
            'new_shares' => $buybackData['new_shares'],
            'dividend_paid' => $divData['dividend_per_share'],
            'events' => $events
        ];
    }



    /**
     * Calculates and distributes the quarterly dividend using a Lintner-style partial adjustment model.
     *
     * @param Stock $stock The stock paying the dividend.
     * @param float $quarterlyEps Quarterly earnings per share.
     * @param float $quarterlyFcfPerShare Quarterly free cash flow per share.
     * @param float $shares Total shares outstanding.
     * @param float $currentPrice Current market price (used for yield calculation).
     * @return array{dividend_per_share: float, total_paid: float, event: array|null}
     */
    private function executeDividends(Stock $stock, float $quarterlyEps, float $quarterlyFcfPerShare, float $shares, float $currentPrice): array
    {
        $targetPayout = (float) $stock->getTargetPayoutRatio();
        $speed = (float) $stock->getDividendSpeed();
        $lastDividend = (float) $stock->getLastDividend();

        $targetDividend = $quarterlyEps > 0 ? ($quarterlyEps * $targetPayout) : 0.0;
        $newDividend = $lastDividend + ($speed * ($targetDividend - $lastDividend));
        $newDividend = min(max(0.0, $newDividend), max(0.0, $quarterlyFcfPerShare));

        $totalPaid = $newDividend * $shares;
        $event = null;

        if ($newDividend > 0.0) {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE users u
                 INNER JOIN user_stocks us ON u.id = us.user_id
                 SET u.cash_balance = u.cash_balance + (us.quantity * :dividend)
                 WHERE us.stock_id = :stock_id',
                ['dividend' => $newDividend, 'stock_id' => $stock->getId()]
            );

            $stock->setLastDividend((string) $newDividend);
            $yield = (($newDividend * 4) / max($currentPrice, 0.01)) * 100;
            $event = $this->marketEvent->publish($stock, 'DIVIDEND', "{$stock->getTicker()} distributed a quarterly dividend of $" . number_format($newDividend, 2) . "/share (Yield: " . number_format($yield, 2) . "%).", 0.0);
        } else {
            $stock->setLastDividend('0.00');
        }

        return ['dividend_per_share' => $newDividend, 'total_paid' => $totalPaid, 'event' => $event];
    }

    /**
     * Executes a stock buyback program using remaining Free Cash Flow.
     *
     * @param Stock $stock            The stock entity.
     * @param float $fcfPerShare      The quarterly Free Cash Flow per share.
     * @param float $dividendPerShare The dividend already allocated per share.
     * @param float $shares           The current shares outstanding.
     * @param float $currentPrice     The current market price.
     * @param float $annualEps        The actual annualized EPS.
     * @param float $targetPE         The sector's target P/E ratio.
     * @return array{new_shares: float, total_cash_spent: float, event: array|null} Buyback execution details.
     */
    private function executeBuybacks(Stock $stock, float $fcfPerShare, float $dividendPerShare, float $shares, float $currentPrice, float $annualEps, float $targetPE): array
    {
        $remainingFcfPerShare = $fcfPerShare - $dividendPerShare;
        $currentPE = $annualEps > 0 ? ($currentPrice / $annualEps) : 9999;
        
        $totalCashSpent = 0.0;
        $event = null;
        
        if ($remainingFcfPerShare > 0.0 && $currentPE < ($targetPE + 2.0)) {
            $maxCash = $remainingFcfPerShare * $shares;
            $sharesRepurchased = (int) ($maxCash / max($currentPrice, 0.01));
            $sharesRepurchased = min($sharesRepurchased, (int) ($shares * 0.015));

            if ($sharesRepurchased > 0) {
                $shares -= $sharesRepurchased;
                $stock->setSharesOutstanding((string) $shares);
                
                $totalCashSpent = $sharesRepurchased * max($currentPrice, 0.01);
                $pctRetired = ($sharesRepurchased / ($shares + $sharesRepurchased)) * 100;
                
                $event = $this->marketEvent->publish($stock, 'BUYBACK', "{$stock->getTicker()} executed a stock buyback, retiring " . number_format($sharesRepurchased) . " shares.", $pctRetired);
            }
        }

        return ['new_shares' => $shares, 'total_cash_spent' => $totalCashSpent, 'event' => $event];
    }


    /**
     * Updates the corporate balance sheet using Clean Surplus Accounting principles.
     *
     * @param Stock $stock                The stock entity to update.
     * @param float $quarterlyNetIncome   The absolute net income generated this quarter.
     * @param float $totalDividendsPaid   The absolute total cash paid out as dividends.
     * @param float $totalBuybackCash     The absolute total cash spent on buybacks.
     * @param float $totalFcfGenerated    The absolute total Free Cash Flow generated.
     * @param float $currentPrice         The current market price of the stock.
     * @param float $newSharesOutstanding The new total shares outstanding after buybacks.
     */
    private function updateBalanceSheet(
        Stock $stock, 
        float $quarterlyNetIncome, 
        float $totalDividendsPaid, 
        float $totalBuybackCash, 
        float $totalFcfGenerated,
        float $currentPrice,
        float $newSharesOutstanding
    ): void {
        $totalCashSpent = $totalDividendsPaid + $totalBuybackCash;

        // RETAINED EARNINGS
        $currentRetained = (float) $stock->getRetainedEarnings();
        $newRetained = $currentRetained + $quarterlyNetIncome - $totalDividendsPaid;
        $stock->setRetainedEarnings((string) max(0.0, $newRetained));

        // TOTAL EQUITY
        $currentEquity = (float) $stock->getTotalEquity();
        $newEquity = $currentEquity + $quarterlyNetIncome - $totalCashSpent;
        $stock->setTotalEquity((string) max(1000.0, $newEquity));

        //  Cash
        $netCashChange = $totalFcfGenerated - $totalCashSpent;
        $currentTreasury = (float) $stock->getCorporateTreasury();
        $newTreasury = $currentTreasury + $netCashChange;

        // THE DEBT TRAP (If they overspent cash they don't have)
        if ($newTreasury < 0.0) {
            $cashShortfall = abs($newTreasury);
            $marketCap = $currentPrice * max($newSharesOutstanding, 1);
            
            $debtPenalty = $cashShortfall / max($marketCap, 1); 
            $currentDebtRatio = (float) $stock->getDebtToEquityRatio();
            $stock->setDebtToEquityRatio((string) ($currentDebtRatio + ($debtPenalty * 2.5)));
            
            $newTreasury = 0.0;
        }
        $stock->setCorporateTreasury((string) $newTreasury);
    }
}