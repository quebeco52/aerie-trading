<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service responsible for executing raw SQL updates related to corporate actions 
 * (Dividends, Splits, Reverse Splits). Separates domain logic from persistence.
 */
class CorporateLedgerService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Distributes a cash dividend to every user holding the stock, and records what each one received.
     *
     * Runs inside the tick transaction opened by MarketTickerCommand, so the ledger write and the cash
     * credit are already atomic together; opening another one here would only nest.
     *
     * Two statements rather than one because the credit is derived FROM the ledger rows: rounding then
     * happens once, at write time, and the sum of dividend_payment.amount equals the cash actually paid
     * by construction. Running the holdings subquery twice and rounding independently lets the two drift
     * apart at the third decimal on every payment, with nothing to detect it.
     *
     * @param Stock              $stock            The paying company.
     * @param float              $dividendPerShare Cash rate per share for this ex-date.
     * @param \DateTimeInterface $paidAt           Ex-date. Passed in, not NOW(), because it is the join key
     *                                             between the two statements and must be identical in both.
     */
    public function processDividendPayment(Stock $stock, float $dividendPerShare, \DateTimeInterface $paidAt): void
    {
        $conn = $this->entityManager->getConnection();
        $paidAtStr = $paidAt->format('Y-m-d H:i:s');

        // Holdings are user_stocks plus the shares sitting in escrow behind an open SELL, and nothing else.
        // A SELL has already had its shares removed from user_stocks by the trade engine, so it has to be
        // added back or the seller is underpaid for shares they still own until the order fills. An open BUY
        // is deliberately excluded: it has escrowed CASH, not shares, and the user does not own them yet.
        // Paying on it would let anyone park a limit buy far below market and collect dividends indefinitely
        // on stock they never bought, with the escrow still refundable on cancel.
        $conn->executeStatement(
            "INSERT INTO dividend_payment
                 (user_id, asset_type, ticker, shares_held, dividend_per_share, amount, paid_at)
             SELECT holdings.user_id,
                    'STOCK',
                    :ticker,
                    holdings.total_shares,
                    :dividend,
                    ROUND(holdings.total_shares * :dividend, 2),
                    :paid_at
             FROM (
                 SELECT user_id, SUM(total_qty) AS total_shares
                 FROM (
                     SELECT user_id, quantity AS total_qty
                     FROM user_stocks
                     WHERE stock_id = :stock_id AND quantity > 0
                     UNION ALL
                     SELECT user_id, quantity AS total_qty
                     FROM trade_orders
                     WHERE ticker = :ticker AND status = 'OPEN' AND action = 'SELL'
                 ) combined_shares
                 GROUP BY user_id
             ) holdings
             WHERE ROUND(holdings.total_shares * :dividend, 2) > 0",
            [
                'ticker' => $stock->getTicker(),
                'dividend' => $dividendPerShare,
                'stock_id' => $stock->getId(),
                'paid_at' => $paidAtStr,
            ]
        );

        // Credit cash from the rows just written. The unique index on (user_id, ticker, paid_at) both makes
        // this join an index lookup and turns an accidental replay of the same ex-date into a constraint
        // violation rather than a silent double credit.
        $conn->executeStatement(
            "UPDATE users u
             INNER JOIN dividend_payment d
                     ON d.user_id = u.id
                    AND d.ticker  = :ticker
                    AND d.paid_at = :paid_at
             SET u.cash_balance = u.cash_balance + d.amount",
            ['ticker' => $stock->getTicker(), 'paid_at' => $paidAtStr]
        );

        $this->chargeShortDividends($stock, $dividendPerShare, $paidAtStr);
    }

    /**
     * Debits the dividend from anyone short the stock.
     *
     * A short seller borrowed the shares from someone who is still entitled to the distribution, so the
     * short makes them whole out of their own pocket. It is a real and sometimes decisive cost of holding
     * a short through an ex-date, and silently skipping it would make a high-yield name free to be short.
     *
     * Written as its own negative ledger row rather than folded into the payment statement above, so the
     * income feed can show it for what it is: cash that left the account because of a position, not a
     * distribution that failed to arrive.
     *
     * @param float  $dividendPerShare Cash rate per share for this ex-date.
     * @param string $paidAtStr        Ex-date, matching the credit leg exactly.
     */
    private function chargeShortDividends(Stock $stock, float $dividendPerShare, string $paidAtStr): void
    {
        $conn = $this->entityManager->getConnection();

        $conn->executeStatement(
            "INSERT INTO dividend_payment
                 (user_id, asset_type, ticker, shares_held, dividend_per_share, amount, paid_at)
             SELECT us.user_id,
                    'STOCK',
                    :ticker,
                    us.quantity,
                    :dividend,
                    ROUND(us.quantity * :dividend, 2),
                    :paid_at
             FROM user_stocks us
             WHERE us.stock_id = :stock_id
               AND us.quantity < 0
               AND ROUND(us.quantity * :dividend, 2) < 0",
            [
                'ticker' => $stock->getTicker(),
                'dividend' => $dividendPerShare,
                'stock_id' => $stock->getId(),
                'paid_at' => $paidAtStr,
            ]
        );

        // The rows just written carry a negative amount, so the same addition that credits a holder debits
        // a short. Restricted to negative rows so a replay cannot re-apply the credit leg.
        $conn->executeStatement(
            "UPDATE users u
             INNER JOIN dividend_payment d
                     ON d.user_id = u.id
                    AND d.ticker  = :ticker
                    AND d.paid_at = :paid_at
                    AND d.amount < 0
             SET u.cash_balance = u.cash_balance + d.amount",
            ['ticker' => $stock->getTicker(), 'paid_at' => $paidAtStr]
        );
    }

    /**
     * Adjusts ledgers and history for a stock split or reverse split.
     * 
     * @param Stock $stock The stock entity.
     * @param float $splitFactor The multiplier or divisor for the split.
     * @param bool $isReverse Whether it is a reverse split (where shares divide and price multiplies).
     * @param float|null $oldPrice Required for reverse splits to calculate cashout value.
     */
    public function processStockSplit(Stock $stock, float $splitFactor, bool $isReverse = false, ?float $oldPrice = null): void
    {
        $conn = $this->entityManager->getConnection();
        $conn->beginTransaction();

        try {
            if ($isReverse) {
                // 1. Fetch every position in this ticker before mutating, SHORT ONES INCLUDED. A short is a
                // holding with a negative quantity, and it has the same fractional remnant a long does: the
                // borrower owes 105 old shares, which is ten and a half new ones. Restricting this to
                // quantity > 0 left the short's fraction unsettled while the rewrite below still rescaled it.
                $holdings = $conn->fetchAllAssociative(
                    'SELECT id, user_id, quantity FROM user_stocks WHERE stock_id = :stock_id AND quantity <> 0',
                    ['stock_id' => $stock->getId()]
                );

                // 2. Settle the fractional remnant in cash, in whichever direction the position runs.
                //
                // The share count rounds TOWARD ZERO for both signs, which is what the TRUNCATE below does
                // and what FLOOR did not: floor rounds toward negative infinity, so a 105-share short at a
                // 1-for-10 became eleven shares short rather than ten, and the borrower was handed half a
                // share of extra exposure they never asked for and were never paid for.
                //
                // The remnant carries the position's sign, so a long is CREDITED for the fraction bought out
                // of them and a short is DEBITED for the fraction they have to buy in. Both settle at the
                // pre-split price, which is the same price the escrow leg below uses.
                foreach ($holdings as $holding) {
                    $qty = (float) $holding['quantity'];
                    $newQty = $qty < 0.0
                        ? -floor(abs($qty) / $splitFactor)
                        : floor($qty / $splitFactor);
                    $remnant = $qty - ($newQty * $splitFactor);

                    if ($remnant != 0.0 && $oldPrice !== null) {
                        $cashoutValue = round($remnant * $oldPrice, 4);

                        $conn->executeStatement(
                            'UPDATE users SET cash_balance = cash_balance + :cashout WHERE id = :user_id',
                            ['cashout' => $cashoutValue, 'user_id' => $holding['user_id']]
                        );
                    }
                }

                // 3. Cash out the fractional remnant sitting in ESCROW, on the same terms as the holdings
                // above and BEFORE the bulk rewrite below reaches it.
                //
                // An open order is not idle: a BUY has had limit x quantity debited from cash, and a SELL has
                // had its shares removed from user_stocks. The rewrite below rescales both legs by FLOOR, so
                // an order of 15 shares at $2 became 1 share at $20 and $10 of committed cash simply ceased
                // to exist; an order that floored to zero was cancelled outright by the sweep further down
                // and lost the whole escrow, because that sweep is raw SQL and refunds nothing. Refunding
                // limit x (quantity MOD factor) leaves the surviving order holding exactly what it still
                // needs, so escrow value is conserved across the split and across a later cancel.
                //
                // Only those two sides are refunded, because only those two escrow anything. A resting SHORT
                // locates its borrow and posts its margin at the fill, and a resting COVER is the closing leg
                // of a position that is already collateralized (TradeExecutionService): neither has committed
                // a dollar or a share to refund. Falling through to a share valuation on ELSE paid both of
                // them the remnant at the pre-split price, which is cash created from nothing and parkable —
                // leave a resting short on a name heading for a reverse split and collect. The dividend
                // statement at the top of this class already filters to SELL for the same reason.
                $conn->executeStatement(
                    "UPDATE users u
                     INNER JOIN (
                         SELECT o.user_id,
                                SUM(CASE WHEN o.action = 'BUY'
                                         THEN COALESCE(o.limit_price, 0) * (o.quantity % :factor)
                                         WHEN o.action = 'SELL'
                                         THEN (o.quantity % :factor) * :old_price
                                         ELSE 0
                                    END) AS remnant_value
                         FROM trade_orders o
                         WHERE o.ticker = :ticker AND o.status = 'OPEN'
                         GROUP BY o.user_id
                     ) remnants ON remnants.user_id = u.id
                     SET u.cash_balance = u.cash_balance + remnants.remnant_value",
                    ['factor' => $splitFactor, 'ticker' => $stock->getTicker(), 'old_price' => $oldPrice ?? 0.0]
                );

                // 4. Perform bulk SQL updates for quantities and prices
                $conn->executeStatement(
                    "UPDATE trade_orders SET limit_price = ROUND(limit_price * :factor, 4) WHERE ticker = :ticker AND status = 'OPEN'",
                    ['factor' => $splitFactor, 'ticker' => $stock->getTicker()]
                );

                // TRUNCATE, not FLOOR: it rounds toward zero for both signs, so a long keeps the behaviour it
                // always had and a short is no longer rounded AWAY from zero into exposure it was never sold.
                // The cash settlement of the fraction happened above, against exactly this arithmetic.
                $conn->executeStatement(
                    'UPDATE user_stocks SET quantity = TRUNCATE(quantity / :factor, 0), version = version + 1 WHERE stock_id = :stock_id',
                    ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
                );

                $conn->executeStatement(
                    'DELETE FROM user_stocks WHERE stock_id = :stock_id AND quantity = 0',
                    ['stock_id' => $stock->getId()]
                );

                $conn->executeStatement(
                    "UPDATE trade_orders SET quantity = FLOOR(quantity / :factor) WHERE ticker = :ticker AND status = 'OPEN'",
                    ['factor' => $splitFactor, 'ticker' => $stock->getTicker()]
                );

                $conn->executeStatement(
                    "UPDATE trade_orders SET status = 'CANCELLED' WHERE ticker = :ticker AND status = 'OPEN' AND quantity = 0",
                    ['ticker' => $stock->getTicker()]
                );

                // The whole bar is restated, not just the close. Leaving open, high and low in pre-split
                // units draws every historical candle with a wick spanning the split factor, and volume
                // moves the opposite way to price because the same money changed hands over fewer shares.
                // NULL columns stay NULL through the arithmetic, so rows written before bars existed are
                // left alone rather than restated into zeroes.
                $conn->executeStatement(
                    'UPDATE stock_history
                     SET price = LEAST(price * :factor, 900000000000.0),
                         open_price = LEAST(open_price * :factor, 900000000000.0),
                         high_price = LEAST(high_price * :factor, 900000000000.0),
                         low_price = LEAST(low_price * :factor, 900000000000.0),
                         volume = FLOOR(volume / :factor)
                     WHERE stock_id = :stock_id',
                    ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
                );

                $conn->executeStatement(
                    'UPDATE corporate_report SET shares = FLOOR(shares / :factor) WHERE stock_id = :stock_id',
                    ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
                );

                // Executed history is restated too, or the cost basis derived from it is left in pre-split
                // units against a post-split holding: after a 1-for-10 the same money reads as ten times the
                // shares at a tenth the price, and the position shows a phantom gain that never happened.
                // GREATEST(..., 1) rather than 0: an executed trade that floors away takes its whole
                // consideration out of the basis pool with it, and a position built from orders all smaller
                // than the factor lost every one of them at once — the cost basis then had no shares to
                // average over and the page printed no cost at all against a position the holder still held.
                $conn->executeStatement(
                    "UPDATE trade_orders
                     SET quantity = GREATEST(FLOOR(quantity / :factor), 1),
                         filled_quantity = GREATEST(FLOOR(filled_quantity / :factor), 1),
                         execution_price = ROUND(execution_price * :factor, 4),
                         limit_price = ROUND(limit_price * :factor, 4)
                     WHERE ticker = :ticker AND status = 'FILLED'",
                    ['factor' => $splitFactor, 'ticker' => $stock->getTicker()]
                );
            } else {
                $conn->executeStatement(
                    'UPDATE user_stocks SET quantity = IF(quantity > 9223372036854775807 / :factor, 9223372036854775807, quantity * :factor), version = version + 1 WHERE stock_id = :stock_id',
                    ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
                );

                $conn->executeStatement(
                    'UPDATE stock_history
                     SET price = GREATEST(price / :factor, 0.00000001),
                         open_price = GREATEST(open_price / :factor, 0.00000001),
                         high_price = GREATEST(high_price / :factor, 0.00000001),
                         low_price = GREATEST(low_price / :factor, 0.00000001),
                         volume = IF(volume > 9223372036854775807 / :factor, 9223372036854775807, volume * :factor)
                     WHERE stock_id = :stock_id',
                    ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
                );

                $conn->executeStatement(
                    'UPDATE corporate_report SET shares = IF(shares > 9223372036854775807 / :factor, 9223372036854775807, shares * :factor) WHERE stock_id = :stock_id',
                    ['factor' => $splitFactor, 'stock_id' => $stock->getId()]
                );

                $conn->executeStatement(
                    "UPDATE trade_orders SET quantity = IF(quantity > 9223372036854775807 / :factor, 9223372036854775807, quantity * :factor), limit_price = ROUND(limit_price / :factor, 4) WHERE ticker = :ticker AND status = 'OPEN'",
                    ['factor' => $splitFactor, 'ticker' => $stock->getTicker()]
                );

                // Executed history is restated too, or the cost basis derived from it is left in pre-split
                // units against a post-split holding: after a 4-for-1 a position bought for $4,000 reads as
                // having cost $16,000 and the page shows a 75% loss the holder never took.
                $conn->executeStatement(
                    "UPDATE trade_orders
                     SET quantity = IF(quantity > 9223372036854775807 / :factor, 9223372036854775807, quantity * :factor),
                         filled_quantity = IF(filled_quantity > 9223372036854775807 / :factor, 9223372036854775807, filled_quantity * :factor),
                         execution_price = ROUND(execution_price / :factor, 4),
                         limit_price = ROUND(limit_price / :factor, 4)
                     WHERE ticker = :ticker AND status = 'FILLED'",
                    ['factor' => $splitFactor, 'ticker' => $stock->getTicker()]
                );
            }

            // The feed is restated with the ledger. Event text carries per-share figures — the quarter's
            // EPS, the surprise against consensus, the dividend paid — and a share count for buybacks, all
            // written in the units of their day. Every price and share count above has just been moved to
            // the new basis, so left alone the feed reads "Q-Earnings: $11.75" one quarter and "$3.56" the
            // next across a 4-for-1 that changed nothing about the business. EVA and totals are dollar
            // amounts, not per-share, and are left as they are.
            $this->restateEventDescriptions($conn, $stock, $isReverse ? $splitFactor : 1.0 / $splitFactor);

            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Rewrites the per-share figures in this stock's event feed onto the post-split basis.
     *
     * The feed is text, so the restatement is done by pattern against exactly the phrasings the engines
     * write and EventPresenter parses: the EPS that opens "Q-Earnings: $11.75", the "Beat/Missed
     * expectations by $0.30" surprise, the "Paid $0.61/share div" rate, and the "Bought back N shares"
     * count, which moves the opposite way to price. Formatting is kept as written (two decimals, thousands
     * separators) so the presenter's regexes still match. Dollar totals (EVA, bond issues, total paid)
     * are unchanged by a split and are not touched; rows that carry none of these are not rewritten.
     *
     * @param float $priceRatio Factor a per-share dollar figure is multiplied by: 1/factor for a forward
     *                          split, factor for a reverse split. Share counts divide by it.
     */
    private function restateEventDescriptions(\Doctrine\DBAL\Connection $conn, Stock $stock, float $priceRatio): void
    {
        $rows = $conn->fetchAllAssociative(
            "SELECT id, description FROM stock_events
             WHERE stock_id = :stock_id
               AND (description LIKE 'Q-Earnings:%' OR description LIKE '%/share div%' OR description LIKE '%Bought back%')",
            ['stock_id' => $stock->getId()]
        );

        $money = static fn (string $amount): string => number_format(((float) str_replace(',', '', $amount)) * $priceRatio, 2);

        foreach ($rows as $row) {
            $original = (string) $row['description'];
            $restated = $original;

            // "Q-Earnings: $11.75" / "Q-Earnings: -$3.56": the sign sits outside the dollar sign.
            $restated = preg_replace_callback(
                '/^(Q-Earnings:\s*-?\$)([\d,]+\.\d{2})/',
                static fn (array $m): string => $m[1] . $money($m[2]),
                $restated
            ) ?? $restated;

            $restated = preg_replace_callback(
                '/((?:Beat|Missed) expectations by\s*\$)([\d,]+\.\d{2})/',
                static fn (array $m): string => $m[1] . $money($m[2]),
                $restated
            ) ?? $restated;

            $restated = preg_replace_callback(
                '/(Paid\s+\$)([\d,]+\.\d{2})(\/share\s+div)/',
                static fn (array $m): string => $m[1] . $money($m[2]) . $m[3],
                $restated
            ) ?? $restated;

            $restated = preg_replace_callback(
                '/(Bought\s+back\s+)([\d,]+)(\s+shares)/',
                static fn (array $m): string => $m[1] . number_format(round(((float) str_replace(',', '', $m[2])) / $priceRatio)) . $m[3],
                $restated
            ) ?? $restated;

            if ($restated !== $original) {
                $conn->executeStatement(
                    'UPDATE stock_events SET description = :description WHERE id = :id',
                    ['description' => $restated, 'id' => $row['id']]
                );
            }
        }
    }
}
