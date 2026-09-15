<?php

namespace App\Service\Market;

use App\Entity\Etf;
use App\Entity\EtfHistory;
use App\Service\Math\FinancialConstants;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Event\MarketEventPublisher;

/**
 * Turns an index into a tradable fund: strikes the level, prices a share of the portfolio off it, and
 * persists both.
 *
 * Two jobs, kept distinct because they are different measurements. The LEVEL is the index — its members'
 * adjusted capitalisation over a divisor, held continuous across splits and reconstitutions by restating
 * that divisor. The PRICE is what one share of the fund tracking it is worth, which differs from the level
 * by the fund's own books: the fee it has paid and the dividends it is holding for its members
 * (IndexFundAccountant).
 */
class EtfTracker
{
    /**
     * @param EntityManagerInterface $entityManager The Doctrine Entity Manager
     * @param MarketEventPublisher $marketEvent The publisher for market announcements
     * @param \Redis $redis The Redis connection instance
     * @param IndexFundAccountant $accountant Keeper of each fund's own books: its fee and its income
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketEventPublisher $marketEvent,
        private \Redis $redis,
        private IndexFundAccountant $accountant
    ) {
    }

    /**
     * Redis key holding an index's divisor. One key per index, because a divisor written in two places is
     * two answers to what the index is worth.
     */
    public static function divisorKey(string $ticker): string
    {
        return 'market_index_divisor:' . $ticker;
    }

    /** The divisor an index level is currently struck on, or null before one has been set. */
    public function currentDivisor(string $ticker): ?float
    {
        $divisor = $this->redis->get(self::divisorKey($ticker));

        return is_numeric($divisor) && (float) $divisor > 0.0 ? (float) $divisor : null;
    }

    /**
     * Restates an index's divisor.
     *
     * A divisor absorbs changes in the index's own composition so that its level keeps measuring prices
     * rather than the committee's decisions. Both things that change composition come through here: a split,
     * which changes the units, and a reconstitution, which changes who is counted.
     */
    public function restateDivisor(string $ticker, float $divisor): void
    {
        if ($divisor > 0.0) {
            $this->redis->set(self::divisorKey($ticker), (string) $divisor);
        }
    }

    /**
     * Strikes an index level from the capitalisation of its members, prices the FUND off it, and writes it.
     *
     * The two are not the same number and this is where they part company. The level is the index: member
     * capitalisation over a divisor, a price index, the thing the committee restates and the charts quote.
     * The fund's price is what a share of the portfolio tracking that index is worth, which is the level
     * scaled by how much basket the share still owns, plus the dividend cash the fund has collected and not
     * yet handed over:
     *
     *     price = level x basket per share + accrued income
     *
     * Publishing the level as the price, as this did, made every fund a claim on a price index: it lagged
     * the basket it claimed to hold by the market's whole dividend yield every year, and it charged nothing
     * to run, so a clever expensive fund and a cheap plain one tracked their indices equally perfectly.
     * See IndexFundAccountant for the books being carried here.
     *
     * @param float  $totalMarketCap The float-adjusted, factor-adjusted capitalisation of the index's members.
     * @param bool   $recordHistory  Whether to persist the new price to the history table.
     * @param string $ticker         The fund the level is written to; its divisor is keyed on the same ticker.
     * @param float  $dividendPoints Dividend cash the members paid this tick, on the same adjusted basis as
     *                               the capitalisation. Divided by the same divisor, it is the index
     *                               dividend, and it is what the fund actually collects.
     * @param float  $dt             Elapsed simulated time in years, over which the fee accrues.
     * @return array{ticker: string, price: float, name: string, is_etf: bool}
     */
    public function updateIndex(
        float $totalMarketCap,
        bool $recordHistory = false,
        string $ticker = 'LBI',
        ?Etf $etf = null,
        ?float $simTime = null,
        float $dividendPoints = 0.0,
        float $dt = 0.0
    ): array {

        if ($etf === null) {
            $etf = $this->entityManager->getRepository(Etf::class)->findOneByTicker($ticker);
        }

        $divisorKey = self::divisorKey($ticker);
        $divisor = $this->redis->get($divisorKey);

        if (!$divisor && $totalMarketCap > 0) {

            // Seeded from the LEVEL behind the fund's last price, not the price itself. The price carries
            // the fund's own fee drag and undistributed income; striking a divisor on it would fold the
            // fund's costs into the index and then measure the fund's tracking against them.
            if ($etf && $etf->getIndexLevel() > 0) {
                $divisor = $totalMarketCap / $etf->getIndexLevel();
            } else {
                $divisor = $totalMarketCap / FinancialConstants::INDEX_BASE_LEVEL;
            }
            
            $this->redis->set($divisorKey, (string) $divisor);
        }

        $level = ($divisor > 0) ? ($totalMarketCap / (float) $divisor) : FinancialConstants::INDEX_BASE_LEVEL;
        $price = $level;

        if ($etf) {
            // 1. The fund's own books, before anything is priced off them: the fee it owes for the time
            //    just elapsed, and the dividends its constituents just paid it.
            $this->accountant->accrue(
                $etf,
                $level,
                $divisor > 0 ? $dividendPoints / (float) $divisor : 0.0,
                $dt
            );

            $price = ($level * $etf->getBasketPerShare()) + $etf->getAccruedIncome();

            // 2. Process ETF Splits
            if ($price >= 400.0) {
                $splitFactor = 1;
                while ($price >= 400.0 && $splitFactor <= 1000000) {
                    $price /= 4.0;
                    $splitFactor *= 4;
                }
                
                $divisor *= $splitFactor;
                $this->redis->set($divisorKey, (string) $divisor);
                // Both are per-SHARE amounts and there are now more shares. Left alone, a 4-for-1 would
                // quadruple the cash the fund believes it is holding for its members, and quadruple what
                // it reports having charged them.
                $etf->setAccruedIncome($etf->getAccruedIncome() / $splitFactor);
                $etf->setCumulativeFeesPaid($etf->getCumulativeFeesPaid() / $splitFactor);
                $this->executeEtfSplit($etf, $splitFactor, 'forward', $price * $splitFactor);
                
            } elseif ($price < 25.0 && $price > 0) {
                $splitFactor = 1;
                $preSplitPrice = $price;
                while ($price < 25.0 && $splitFactor <= 1000000 && $price > 0.0) {
                    $price *= 4.0;
                    $splitFactor *= 4;
                }
                
                $divisor /= $splitFactor;
                $this->redis->set($divisorKey, (string) $divisor);
                $etf->setAccruedIncome($etf->getAccruedIncome() * $splitFactor);
                $etf->setCumulativeFeesPaid($etf->getCumulativeFeesPaid() * $splitFactor);
                $this->executeEtfSplit($etf, $splitFactor, 'reverse', $preSplitPrice);
            }

            // 3. Persist the updated price
            $etf->setPrice((string) $price);

            if ($recordHistory) {
                $history = new EtfHistory();
                $history->setEtf($etf);
                $history->setPrice((string) $price);

                // Null when the caller is not the ticker: an honest "written outside the simulation clock"
                // rather than a zero that would sort the row before the beginning of time.
                $history->setSimTime($simTime);

                $this->entityManager->persist($history);
            }
        }

        return [
            'ticker' => $ticker,
            'price' => round($price, 2),
            'name' => $etf ? $etf->getName() : 'Market Index',
            'is_etf' => true
        ];
    }

    /**
     * Executes the backend database adjustments for an ETF split.
     * Ensures users are compensated for fractional shares during reverse splits.
     *
     * @param Etf    $etf           The ETF entity.
     * @param float  $factor        The split multiplier or divisor.
     * @param string $direction     The direction of the split ('forward' or 'reverse').
     * @param float  $preSplitPrice The price of the ETF before the split occurred.
     */
    private function executeEtfSplit(Etf $etf, float $factor, string $direction, float $preSplitPrice): void
    {
        $conn = $this->entityManager->getConnection();
        $etfId = $etf->getId();
        $ticker = $etf->getTicker();
        
        // Note: You must ensure you have a 'user_etfs' table or similar structure!
        $userEtfsTableExists = $conn->createSchemaManager()->tablesExist(['user_etfs']);

        $conn->beginTransaction();
        try {
            if ($direction === 'forward') {
                if ($userEtfsTableExists) {
                    $conn->executeStatement(
                        'UPDATE user_etfs SET quantity = quantity * :factor, version = version + 1 WHERE etf_id = :etf_id',
                        ['factor' => $factor, 'etf_id' => $etfId]
                    );
                }

                $conn->executeStatement(
                    'UPDATE etf_history SET price = price / :factor WHERE etf_id = :etf_id',
                    ['factor' => $factor, 'etf_id' => $etfId]
                );

                $this->restateOrderBook($conn, $ticker, $factor, false, $preSplitPrice);
                
                $desc = "{$etf->getName()} has executed a {$factor}-for-1 forward split to maintain liquidity.";
                $this->marketEvent->publish($etf, 'SPLIT', $desc, 0.0);

                $this->adjustRedisBuffer($ticker, $factor, 'divide');

            } else {
                // Escrow first, on the pre-split book: the restatement below rescales the resting orders and
                // the remnant has to be paid out at the price it was committed at.
                $this->restateOrderBook($conn, $ticker, $factor, true, $preSplitPrice);

                if ($userEtfsTableExists) {
                    // Compensate users for fractional shares to prevent wealth destruction
                    $conn->executeStatement(
                        'UPDATE users u
                         INNER JOIN user_etfs ue ON u.id = ue.user_id
                         SET u.cash_balance = u.cash_balance + ((ue.quantity % :factor) * :pre_split_price)
                         WHERE ue.etf_id = :etf_id',
                        ['factor' => $factor, 'pre_split_price' => $preSplitPrice, 'etf_id' => $etfId]
                    );

                    $conn->executeStatement(
                        'UPDATE user_etfs SET quantity = FLOOR(quantity / :factor), version = version + 1 WHERE etf_id = :etf_id',
                        ['factor' => $factor, 'etf_id' => $etfId]
                    );

                    $conn->executeStatement(
                        'DELETE FROM user_etfs WHERE etf_id = :etf_id AND quantity = 0',
                        ['etf_id' => $etfId]
                    );
                }

                $conn->executeStatement(
                    'UPDATE etf_history SET price = price * :factor WHERE etf_id = :etf_id',
                    ['factor' => $factor, 'etf_id' => $etfId]
                );
                
                $desc = "{$etf->getName()} has executed a 1-for-{$factor} reverse split to maintain value requirements.";
                $this->marketEvent->publish($etf, 'REVSPLIT', $desc, 0.0);

                $this->adjustRedisBuffer($ticker, $factor, 'multiply');
            }
            
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Restates resting and executed orders on a split ETF, the way CorporateLedgerService does for a stock.
     *
     * An ETF split used to touch only user_etfs and etf_history, which left the order book quoting against a
     * book that had just halved or decupled: a resting BUY at $60 under a $100 ETF became an immediate fill
     * the moment a forward split took the price to $50, and the escrow behind every open order was measured
     * in units that no longer existed. Executed history is restated for the same reason a stock's is - the
     * cost basis every portfolio surface prints is derived from it.
     *
     * @param bool $isReverse Whether quantities divide and prices multiply, rather than the other way round.
     */
    private function restateOrderBook(
        \Doctrine\DBAL\Connection $conn,
        string $ticker,
        float $factor,
        bool $isReverse,
        float $preSplitPrice
    ): void {
        if ($isReverse) {
            // Escrowed value that will not survive the FLOOR below, refunded before it is lost: a BUY holds
            // cash at the limit it committed at, a SELL holds shares valued at the pre-split price. Those
            // are the only two sides that escrow anything, so they are named rather than left to an ELSE.
            // An ETF cannot currently be shorted — TradeExecutionService rejects a SHORT on anything but an
            // equity — so nothing else reaches this statement today, and that is exactly why it should not
            // depend on staying true: the equivalent ELSE in CorporateLedgerService, where shorting IS
            // allowed, was paying resting SHORT and COVER orders a refund for escrow they never posted.
            $conn->executeStatement(
                "UPDATE users u
                 INNER JOIN (
                     SELECT o.user_id,
                            SUM(CASE WHEN o.action = 'BUY'
                                     THEN COALESCE(o.limit_price, 0) * (o.quantity % :factor)
                                     WHEN o.action = 'SELL'
                                     THEN (o.quantity % :factor) * :pre_split_price
                                     ELSE 0
                                END) AS remnant_value
                     FROM trade_orders o
                     WHERE o.ticker = :ticker AND o.status = 'OPEN'
                     GROUP BY o.user_id
                 ) remnants ON remnants.user_id = u.id
                 SET u.cash_balance = u.cash_balance + remnants.remnant_value",
                ['factor' => $factor, 'ticker' => $ticker, 'pre_split_price' => $preSplitPrice]
            );

            $conn->executeStatement(
                "UPDATE trade_orders
                 SET quantity = FLOOR(quantity / :factor),
                     limit_price = ROUND(limit_price * :factor, 4)
                 WHERE ticker = :ticker AND status = 'OPEN'",
                ['factor' => $factor, 'ticker' => $ticker]
            );

            $conn->executeStatement(
                "UPDATE trade_orders SET status = 'CANCELLED' WHERE ticker = :ticker AND status = 'OPEN' AND quantity = 0",
                ['ticker' => $ticker]
            );

            $conn->executeStatement(
                "UPDATE trade_orders
                 SET quantity = GREATEST(FLOOR(quantity / :factor), 1),
                     filled_quantity = GREATEST(FLOOR(filled_quantity / :factor), 1),
                     execution_price = ROUND(execution_price * :factor, 4),
                     limit_price = ROUND(limit_price * :factor, 4)
                 WHERE ticker = :ticker AND status = 'FILLED'",
                ['factor' => $factor, 'ticker' => $ticker]
            );

            return;
        }

        $conn->executeStatement(
            "UPDATE trade_orders
             SET quantity = IF(quantity > 9223372036854775807 / :factor, 9223372036854775807, quantity * :factor),
                 limit_price = ROUND(limit_price / :factor, 4)
             WHERE ticker = :ticker AND status = 'OPEN'",
            ['factor' => $factor, 'ticker' => $ticker]
        );

        $conn->executeStatement(
            "UPDATE trade_orders
             SET quantity = IF(quantity > 9223372036854775807 / :factor, 9223372036854775807, quantity * :factor),
                 filled_quantity = IF(filled_quantity > 9223372036854775807 / :factor, 9223372036854775807, filled_quantity * :factor),
                 execution_price = ROUND(execution_price / :factor, 4),
                 limit_price = ROUND(limit_price / :factor, 4)
             WHERE ticker = :ticker AND status = 'FILLED'",
            ['factor' => $factor, 'ticker' => $ticker]
        );
    }

    /**
     * Mutates the live Redis chart buffer to prevent massive visual vertical spikes on the UI during a split.
     *
     * @param string $ticker    The ETF ticker symbol.
     * @param float  $factor    The split factor.
     * @param string $operation The operation to apply ('divide' or 'multiply').
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