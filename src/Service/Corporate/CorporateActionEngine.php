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
    /** Shares a listing must still have after a reverse split (Nasdaq Rule 5550(a)(4): 500,000 publicly held shares for continued listing); a consolidation that would go below it is not done. */
    public const MIN_SHARES_AFTER_REVERSE_SPLIT = 500_000.0;
    /** The maximum absolute multiplier allowed in a single recursive split action. */
    private const MAX_SPLIT_MULTIPLIER = 1_000_000;
    /** Simulated years before a stock can split again: a split is a board action, and under Nasdaq Rule 5810(c)(3)(A)(iv) a company that reverse split within the past year gets no compliance period to do it again. */
    public const SPLIT_COOLDOWN_YEARS = 1.0;

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
     * At most one split a year: without it a name whose value had collapsed split every few ticks, and each
     * cycle of a price chasing its target through the threshold compounded the share count (TIER: 93 splits
     * in eight minutes, a board capitalisation ~1e23).
     *
     * @param Stock $stock             The stock entity to evaluate.
     * @param float $newPrice          The proposed new price.
     * @param float $sharesOutstanding The current number of shares outstanding.
     * @param float $simTime           Simulated time (years) of this tick.
     * @return array{price: float, shares: float, event: array|null} The adjusted price, shares, and any generated event.
     */
    public function processSplits(Stock $stock, float $newPrice, float $sharesOutstanding, float $simTime): array
    {
        if ($stock->isBankrupt() || $newPrice <= 0.0001) {
            return [
                'price' => 0.0,
                'shares' => max(1.0, $sharesOutstanding),
                'event' => null
            ];
        }

        $splitEvent = null;

        // A clock rewound behind the last split (an unclean Redis stop) leaves a negative gap: that split
        // belongs to a timeline that no longer exists and does not hold the next one back.
        $lastSplitAt = $stock->getLastSplitAt();
        $sinceLastSplit = $lastSplitAt === null ? INF : $simTime - $lastSplitAt;
        $coolingDown = $sinceLastSplit >= 0.0 && $sinceLastSplit < self::SPLIT_COOLDOWN_YEARS;

        if ($coolingDown) {
            // No split this tick.
        } elseif ($newPrice >= self::FORWARD_SPLIT_THRESHOLD) {
            $result = $this->executeForwardSplit($stock, $newPrice, $sharesOutstanding, $simTime);
            $newPrice = $result['price'];
            $sharesOutstanding = $result['shares'];
            $splitEvent = $result['event'];
        } elseif (
            $newPrice < self::REVERSE_SPLIT_THRESHOLD
            && floor($sharesOutstanding / self::REVERSE_SPLIT_FACTOR) >= self::MIN_SHARES_AFTER_REVERSE_SPLIT
        ) {
            $result = $this->executeReverseSplit($stock, $newPrice, $simTime);
            $newPrice = $result['price'];
            $sharesOutstanding = $result['shares'];
            $splitEvent = $result['event'];
        }

        return [
            'price' => $newPrice,
            'shares' => max(1.0, $sharesOutstanding),
            'event' => $splitEvent
        ];
    }


    /**
     * Executes a recursive forward split (e.g., 4-for-1) to bring the price back below $400.
     *
     * Price and shares move by the same factor, so the company is worth exactly what it was. A split that would
     * take the share count past what an integer can hold is not done at all: clamping the count while still
     * dividing the price destroyed value.
     *
     * @param Stock $stock The stock entity.
     * @param float $newPrice The price that breached the upper threshold.
     * @param float $sharesOutstanding The current shares outstanding.
     * @param float $simTime Simulated time (years) of the split.
     * @return array{price: float, shares: float, event: array|null}
     */
    private function executeForwardSplit(Stock $stock, float $newPrice, float $sharesOutstanding, float $simTime): array
    {
        $splitFactor = 1;
        while (
            $newPrice >= self::FORWARD_SPLIT_THRESHOLD
            && $splitFactor <= self::MAX_SPLIT_MULTIPLIER
            && $sharesOutstanding * $splitFactor * self::FORWARD_SPLIT_FACTOR <= (float) PHP_INT_MAX
            && !is_infinite($newPrice)
        ) {
            $newPrice = $newPrice / self::FORWARD_SPLIT_FACTOR;
            $splitFactor *= self::FORWARD_SPLIT_FACTOR;
        }

        if ($splitFactor === 1) {
            return ['price' => $newPrice, 'shares' => $sharesOutstanding, 'event' => null];
        }

        $sharesOutstanding *= $splitFactor;

        $oldDiv = (float) $stock->getLastDividend();
        $oldFcf = (float) $stock->getFreeCashFlowPerShare();

        $stock->setSharesOutstanding((string) $sharesOutstanding);
        $stock->setPrice((string) $newPrice);
        $stock->setLastSplitAt($simTime);

        $stock->setLastDividend((string) ($oldDiv / $splitFactor));
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
     * The consolidation stops at the listing's share minimum even if the price is still under $2. Flooring
     * the count at one share while multiplying the price by the full factor minted value from nothing.
     *
     * @param Stock $stock The stock entity.
     * @param float $newPrice The price that breached the lower threshold.
     * @param float $simTime Simulated time (years) of the split.
     *
     * @return array{price: float, shares: float, event: array|null} Returns details about the executed split.
     * @throws \Exception
     */
    public function executeReverseSplit(Stock $stock, float $newPrice, float $simTime): array
    {
        $oldPrice = $newPrice;
        $oldShares = (float) $stock->getSharesOutstanding();
        $splitFactor = 1;
        while (
            $newPrice < self::REVERSE_SPLIT_THRESHOLD
            && $newPrice > 0.0001
            && $splitFactor <= self::MAX_SPLIT_MULTIPLIER
            && floor($oldShares / ($splitFactor * self::REVERSE_SPLIT_FACTOR)) >= self::MIN_SHARES_AFTER_REVERSE_SPLIT
        ) {
            $newPrice = $newPrice * self::REVERSE_SPLIT_FACTOR;
            $splitFactor *= (int) self::REVERSE_SPLIT_FACTOR;
        }

        if ($splitFactor === 1) {
            return ['price' => $newPrice, 'shares' => $oldShares, 'event' => null];
        }

        $sharesOutstanding = (string) (int) floor($oldShares / $splitFactor);

        $oldDiv = (float) $stock->getLastDividend();
        $oldFcf = (float) $stock->getFreeCashFlowPerShare();

        $stock->setSharesOutstanding($sharesOutstanding);

        $stock->setPrice((string) round($newPrice, 4));
        $stock->setLastSplitAt($simTime);

        $stock->setLastDividend((string) ($oldDiv * $splitFactor));
        $stock->setFreeCashFlowPerShare((string) ($oldFcf * $splitFactor));

        $desc = "{$stock->getName()} has executed a 1-for-{$splitFactor} reverse stock split.";
        $splitEvent = $this->marketEvent->publish($stock, 'REVERSE_SPLIT', $desc, 0.00);

        $this->corporateLedgerService->processStockSplit($stock, (float) $splitFactor, true, $oldPrice);

        $this->adjustRedisBuffer($stock->getTicker(), $splitFactor, 'multiply');

        return ['price' => $newPrice, 'shares' => (float) $sharesOutstanding, 'event' => $splitEvent];
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

            // Volume moves against price: the same consideration, restated into the new share count.
            if (isset($point['volume'])) {
                $point['volume'] = $operation === 'divide'
                    ? (int) round($point['volume'] * $factor)
                    : (int) round($point['volume'] / $factor);
            }
            $this->redis->lPush($cacheKey, json_encode($point));
        }
    }
}
