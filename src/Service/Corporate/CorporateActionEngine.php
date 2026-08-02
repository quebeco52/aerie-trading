<?php

namespace App\Service\Corporate;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Macro\MacroEngine;
use App\Service\Event\MarketEventPublisher;

/**
 * Service responsible for executing corporate actions.
 * Strictly handles structural Market Actions like Stock Splits and Reverse Splits.
 */
class CorporateActionEngine
{
    // --- Split Physics ---
    /** The price ceiling ($400) that triggers a forward stock split. */
    private const FORWARD_SPLIT_THRESHOLD = 400.0;
    /** The multiplier (4x) for shares during a forward split. */
    private const FORWARD_SPLIT_FACTOR = 4.0;
    /** The price floor ($2) that triggers a reverse stock split. */
    private const REVERSE_SPLIT_THRESHOLD = 2.0;
    /** The divisor (10x) for shares during a reverse split. */
    private const REVERSE_SPLIT_FACTOR = 10.0;
    /** The minimum shares required to execute a reverse split. */
    private const MIN_SHARES_REVERSE_SPLIT = 10.0;
    /** The maximum absolute multiplier allowed in a single recursive split action. */
    private const MAX_SPLIT_MULTIPLIER = 1_000_000;

    /**
     * Constructor.
     *
     * @param EntityManagerInterface $entityManager The Doctrine entity manager.
     * @param MarketEventPublisher   $marketEvent   Service for publishing market events.
     * @param \Redis                 $redis         The Redis connection for managing live chart buffers.
     */
    public function __construct(
        private CorporateLedgerService $corporateLedgerService,
        private MarketEventPublisher $marketEvent,
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

        if ($newPrice >= self::FORWARD_SPLIT_THRESHOLD) {
            $result = $this->executeForwardSplit($stock, $newPrice, $sharesOutstanding);
            $newPrice = $result['price'];
            $sharesOutstanding = $result['shares'];
            $splitEvent = $result['event'];
        } elseif ($newPrice < self::REVERSE_SPLIT_THRESHOLD && $sharesOutstanding >= self::MIN_SHARES_REVERSE_SPLIT) {
            $result = $this->executeReverseSplit($stock, $newPrice, (int) self::REVERSE_SPLIT_FACTOR);
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
        while ($newPrice >= self::FORWARD_SPLIT_THRESHOLD && $splitFactor <= self::MAX_SPLIT_MULTIPLIER && !is_infinite($newPrice)) {
            $newPrice = $newPrice / self::FORWARD_SPLIT_FACTOR;
            $splitFactor *= self::FORWARD_SPLIT_FACTOR;
        }

        $sharesOutstanding *= $splitFactor;

        $oldDiv = (float) $stock->getLastDividend();
        $oldEps = (float) $stock->getEarningsPerShare();
        $oldFcf = (float) $stock->getFreeCashFlowPerShare();

        $stock->setSharesOutstanding((string) $sharesOutstanding);
        $stock->setPrice((string) $newPrice);

        $stock->setLastDividend((string) ($oldDiv / $splitFactor));
        $stock->setEarningsPerShare((string) ($oldEps / $splitFactor));
        $stock->setFreeCashFlowPerShare((string) ($oldFcf / $splitFactor));

        $desc = "{$stock->getName()} has executed a {$splitFactor}-for-1 stock split.";
        $splitEvent = $this->marketEvent->publish($stock, 'SPLIT', $desc, 0.00);

        $this->corporateLedgerService->processStockSplit($stock, (float) $splitFactor, false);

        $this->adjustRedisBuffer($stock->getTicker(), $splitFactor, 'divide');

        return ['price' => $newPrice, 'shares' => $sharesOutstanding, 'event' => $splitEvent];
    }


    /**
     * Executes a recursive reverse split (e.g., 1-for-10) to bring the price back above $2.
     * Cashes out fractional shares directly to user accounts to prevent loss of wealth.
     *
     * @param Stock $stock The stock entity.
     * @param float $newPrice The price that breached the lower threshold.
     * @param int   $reverseFactor The factor to multiply the price by (default 10).
     *
     * @return array Returns details about the executed split.
     * @throws \Exception
     */
    public function executeReverseSplit(Stock $stock, float $newPrice, int $reverseFactor = 10): array
    {
        $oldShares = (float) $stock->getSharesOutstanding();
        $sharesOutstanding = (string) floor($oldShares / $reverseFactor);
        $stock->setSharesOutstanding($sharesOutstanding);

        $oldPrice = (float) $stock->getPrice();
        $stock->setPrice((string) round($oldPrice * $reverseFactor, 4));

        $oldEps = (float) $stock->getEarningsPerShare();
        $stock->setEarningsPerShare((string) ($oldEps * $reverseFactor));

        $oldDiv = (float) $stock->getLastDividend();
        $stock->setLastDividend((string) ($oldDiv * $reverseFactor));

        $oldFcf = (float) $stock->getFreeCashFlowPerShare();
        $stock->setFreeCashFlowPerShare((string) ($oldFcf * $reverseFactor));

        $desc = "{$stock->getName()} has executed a 1-for-{$reverseFactor} reverse stock split.";
        $splitEvent = $this->marketEvent->publish($stock, 'REVERSE_SPLIT', $desc, 0.00);

        $this->corporateLedgerService->processStockSplit($stock, (float) $reverseFactor, true, $oldPrice);

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
}
