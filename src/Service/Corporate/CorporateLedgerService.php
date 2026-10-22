<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Service\Market\Option\OptionChainService;
use App\Service\Market\Ticker\NestedTransaction;
use App\Service\Market\Trading\SplitOrderBook;
use App\Service\Math\Decimal;
use App\Service\Math\FinancialConstants;
use App\Service\Notification\PlayerNotifier;
use App\Service\Notification\PriceAlertService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service responsible for executing raw SQL updates related to corporate actions
 * (Dividends, Splits, Reverse Splits). Separates domain logic from persistence.
 */
class CorporateLedgerService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        /** Tells each holder their post-split position; null (unit tests) tells no one. */
        private ?PlayerNotifier $notifier = null,
    ) {}

    /**
     * Distributes a cash dividend to every account with a position in the stock, and records what each one
     * received or paid.
     *
     * Runs inside the tick transaction opened by MarketTickerCommand, so the ledger write and the cash
     * credit are already atomic together; opening another one here would only nest.
     *
     * Two statements rather than one because the credit is derived FROM the ledger rows: rounding then
     * happens once, at write time, and the sum of dividend_payment.amount equals the cash actually paid
     * by construction. Running the holdings subquery twice and rounding independently lets the two drift
     * apart at the third decimal on every payment, with nothing to detect it.
     *
     * A short seller borrowed the shares from someone still entitled to the distribution, so a short position
     * pays the dividend: its row carries a negative amount and the same addition debits it. Each account gets one
     * row, on its net position: shares held, plus shares escrowed behind open SELLs (removed from user_stocks
     * while they rest), less shares sold short. An account can rest a SELL and then go short the same stock, and
     * the unique (user, ticker, paid_at) key admits only one row for it.
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

        // Open BUY orders escrow cash, not shares, so they earn nothing.
        $conn->executeStatement(
            "INSERT INTO dividend_payment
                 (user_id, asset_type, ticker, shares_held, dividend_per_share, amount, paid_at)
             SELECT positions.user_id,
                    'STOCK',
                    :ticker,
                    positions.net_shares,
                    :dividend,
                    ROUND(positions.net_shares * :dividend, 2),
                    :paid_at
             FROM (
                 SELECT user_id, SUM(qty) AS net_shares
                 FROM (
                     SELECT user_id, quantity AS qty
                     FROM user_stocks
                     WHERE stock_id = :stock_id AND quantity <> 0
                     UNION ALL
                     SELECT user_id, quantity AS qty
                     FROM trade_orders
                     WHERE ticker = :ticker AND status = 'OPEN' AND action = 'SELL'
                 ) legs
                 GROUP BY user_id
             ) positions
             WHERE ROUND(positions.net_shares * :dividend, 2) <> 0",
            [
                'ticker' => $stock->getTicker(),
                'dividend' => $dividendPerShare,
                'stock_id' => $stock->getId(),
                'paid_at' => $paidAtStr,
            ],
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
            ['ticker' => $stock->getTicker(), 'paid_at' => $paidAtStr],
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

        // Split factors are whole and bound as integers (SplitOrderBook).
        $factor = (int) round($splitFactor);
        $factorType = SplitOrderBook::FACTOR_TYPES;
        $optionHolders = [];

        $conn->beginTransaction();

        try {
            if ($isReverse) {
                // 1. Fetch every position in this ticker before mutating, SHORT ONES INCLUDED. A short is a
                // holding with a negative quantity, and it has the same fractional remnant a long does: the
                // borrower owes 105 old shares, which is ten and a half new ones. Restricting this to
                // quantity > 0 left the short's fraction unsettled while the rewrite below still rescaled it.
                $holdings = $conn->fetchAllAssociative(
                    'SELECT id, user_id, quantity FROM user_stocks WHERE stock_id = :stock_id AND quantity <> 0',
                    ['stock_id' => $stock->getId()],
                );

                // Settle fractional remnant in cash at pre-split price, rounding toward zero to preserve exposure.
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
                            ['cashout' => $cashoutValue, 'user_id' => $holding['user_id']],
                        );
                    }
                }

                SplitOrderBook::restate($conn, $stock->getTicker(), $factor, true, $oldPrice ?? 0.0);

                // TRUNCATE, not FLOOR: it rounds toward zero for both signs, so a long keeps the behaviour it
                // always had and a short is no longer rounded AWAY from zero into exposure it was never sold.
                // The cash settlement of the fraction happened above, against exactly this arithmetic.
                $conn->executeStatement(
                    'UPDATE user_stocks SET quantity = TRUNCATE(quantity / :factor, 0), version = version + 1 WHERE stock_id = :stock_id',
                    ['factor' => $factor, 'stock_id' => $stock->getId()],
                    $factorType,
                );

                $conn->executeStatement(
                    'DELETE FROM user_stocks WHERE stock_id = :stock_id AND quantity = 0',
                    ['stock_id' => $stock->getId()],
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
                    ['factor' => $factor, 'stock_id' => $stock->getId()],
                    $factorType,
                );

                $conn->executeStatement(
                    'UPDATE corporate_report SET shares = FLOOR(shares / :factor) WHERE stock_id = :stock_id',
                    ['factor' => $factor, 'stock_id' => $stock->getId()],
                    $factorType,
                );
            } else {
                $conn->executeStatement(
                    'UPDATE user_stocks SET quantity = ' . SplitOrderBook::scaledUp('quantity') . ', version = version + 1 WHERE stock_id = :stock_id',
                    ['factor' => $factor, 'stock_id' => $stock->getId()],
                    $factorType,
                );

                $conn->executeStatement(
                    'UPDATE stock_history
                     SET price = GREATEST(price / :factor, 0.00000001),
                         open_price = GREATEST(open_price / :factor, 0.00000001),
                         high_price = GREATEST(high_price / :factor, 0.00000001),
                         low_price = GREATEST(low_price / :factor, 0.00000001),
                         volume = ' . SplitOrderBook::scaledUp('volume') . '
                     WHERE stock_id = :stock_id',
                    ['factor' => $factor, 'stock_id' => $stock->getId()],
                    $factorType,
                );

                $conn->executeStatement(
                    'UPDATE corporate_report SET shares = ' . SplitOrderBook::scaledUp('shares') . ' WHERE stock_id = :stock_id',
                    ['factor' => $factor, 'stock_id' => $stock->getId()],
                    $factorType,
                );

                SplitOrderBook::restate($conn, $stock->getTicker(), $factor, false, $oldPrice ?? 0.0);
            }

            $optionHolders = $this->restateOptionsForSplit($conn, $stock, $factor, $isReverse);

            // The name's own share counts move with it: the stock it still has to put through the market, and its short
            // interest, read back from the positions just restated, since a reverse split rounds each short toward
            // zero.
            $stock->setCorporateFlowBacklog($isReverse ? $stock->getCorporateFlowBacklog() / $factor : $stock->getCorporateFlowBacklog() * $factor);
            $stock->setShortInterestShares(Decimal::format((float) $conn->fetchOne(
                'SELECT COALESCE(-SUM(quantity), 0) FROM user_stocks WHERE stock_id = :stock_id AND quantity < 0',
                ['stock_id' => $stock->getId()],
            ), 2));

            PriceAlertService::restateForSplit($conn, $stock->getTicker(), $factor, $isReverse);

            // Restate historical event descriptions so per-share metrics (EPS, dividends, prices) reflect post-split
            // scale.
            $this->restateEventDescriptions($conn, $stock, $isReverse ? $splitFactor : 1.0 / $splitFactor);

            $conn->commit();
        } catch (\Exception $e) {
            NestedTransaction::rollBackAndRethrow($conn, $e);
        }

        if ($this->notifier !== null) {
            $ticker = $stock->getTicker();
            $this->notifier->queueForHolders(
                $conn->fetchAllAssociative(
                    'SELECT user_id, quantity FROM user_stocks WHERE stock_id = :stock_id AND quantity <> 0',
                    ['stock_id' => $stock->getId()],
                ),
                $ticker,
                $isReverse ? sprintf('%s consolidated its shares 1-for-%d', $ticker, $factor) : sprintf('%s split its shares %d-for-1', $ticker, $factor),
                static fn(float $quantity): string => sprintf(
                    '%s %s shares%s.',
                    $quantity < 0.0 ? 'Your short position is now' : 'You now hold',
                    number_format(abs($quantity)),
                    $isReverse ? '; any fraction left over was paid in cash' : '',
                ),
                PlayerNotifier::assetLink('STOCK', $ticker),
            );
            $this->notifier->queueForHolders(
                $optionHolders,
                $ticker,
                sprintf('Your %s options were adjusted for the %s', $ticker, $isReverse ? sprintf('1-for-%d consolidation', $factor) : sprintf('%d-for-1 split', $factor)),
                static fn(float $quantity): string => $isReverse
                    ? sprintf('Strikes were multiplied by %d and contracts divided by %d; any fraction of a contract was settled in cash.', $factor, $factor)
                    : sprintf('Strikes were divided by %d and contracts multiplied by %d, so each position is worth what it was.', $factor, $factor),
                PlayerNotifier::assetLink('STOCK', $ticker),
            );
        }
    }

    /**
     * Restates the listed options on a split so every holder keeps the same claim, as a clearing house adjusts them: a
     * forward split divides each strike and premium by the factor and multiplies the contracts, a reverse split the
     * other way, with the fraction of a contract it leaves settled in cash at the pre-split mark. Left alone, a
     * four-for-one put every call out of the money and handed every put holder three quarters of its strike.
     *
     * Contracts are renamed in strike order, the direction the strikes move, so no new symbol is taken while the
     * contract holding it is still waiting its turn.
     *
     * @return list<array{user_id: int|string, quantity: int|string}> One row per account holding options on the name,
     *                                                                before the restatement.
     */
    private function restateOptionsForSplit(\Doctrine\DBAL\Connection $conn, Stock $stock, int $factor, bool $isReverse): array
    {
        $contracts = $conn->fetchAllAssociative(
            'SELECT id, option_type, strike, expiry_serial, price FROM option_contracts
             WHERE stock_id = :stock_id AND status = :active ORDER BY strike ' . ($isReverse ? 'DESC' : 'ASC'),
            ['stock_id' => $stock->getId(), 'active' => OptionContract::STATUS_ACTIVE],
        );

        if ($contracts === []) {
            return [];
        }

        $ids = array_map(static fn(array $contract): int => (int) $contract['id'], $contracts);
        $idList = ['ids' => $ids];
        $idTypes = ['ids' => ArrayParameterType::INTEGER];

        /** @var list<array{user_id: int|string, quantity: int|string}> $holders */
        $holders = $conn->fetchAllAssociative(
            'SELECT user_id, SUM(ABS(quantity)) AS quantity FROM user_options
             WHERE option_contract_id IN (:ids) AND quantity <> 0 GROUP BY user_id',
            $idList,
            $idTypes,
        );

        if ($isReverse) {
            // The fraction of a new contract a position leaves is old contracts at the old mark, rounding toward zero
            // as the shares do, so a writer pays for the fraction it no longer owes and a holder is paid for its own.
            $positions = $conn->fetchAllAssociative(
                'SELECT uo.user_id, uo.quantity, oc.price FROM user_options uo
                 JOIN option_contracts oc ON oc.id = uo.option_contract_id
                 WHERE uo.option_contract_id IN (:ids) AND uo.quantity <> 0',
                $idList,
                $idTypes,
            );

            foreach ($positions as $position) {
                $quantity = (int) $position['quantity'];
                $remnant = $quantity - (intdiv($quantity, $factor) * $factor);

                if ($remnant !== 0) {
                    $conn->executeStatement(
                        'UPDATE users SET cash_balance = cash_balance + :cashout WHERE id = :user_id',
                        ['cashout' => round($remnant * (float) $position['price'] * FinancialConstants::OPTION_CONTRACT_MULTIPLIER, 4), 'user_id' => $position['user_id']],
                    );
                }
            }

            $conn->executeStatement(
                'UPDATE user_options SET quantity = TRUNCATE(quantity / :factor, 0), average_premium = average_premium * :factor, version = version + 1
                 WHERE option_contract_id IN (:ids)',
                $idList + ['factor' => $factor],
                $idTypes + ['factor' => ParameterType::INTEGER],
            );
            $conn->executeStatement('DELETE FROM user_options WHERE option_contract_id IN (:ids) AND quantity = 0', $idList, $idTypes);
        } else {
            $conn->executeStatement(
                'UPDATE user_options SET quantity = quantity * :factor, average_premium = average_premium / :factor, version = version + 1
                 WHERE option_contract_id IN (:ids)',
                $idList + ['factor' => $factor],
                $idTypes + ['factor' => ParameterType::INTEGER],
            );
        }

        // Per share, a premium and its vega and theta scale with the price; delta does not, and gamma, per dollar of
        // the underlying, moves the other way. The public's book scales with the contract count.
        $scale = $isReverse ? (float) $factor : 1.0 / $factor;

        foreach ($contracts as $contract) {
            $strike = (float) $contract['strike'] * $scale;

            $conn->executeStatement(
                'UPDATE option_contracts
                 SET ticker = :ticker, strike = :strike, price = price * :scale, vega = vega * :scale, theta = theta * :scale,
                     gamma = gamma / :scale, structural_open_interest = ROUND(structural_open_interest / :scale)
                 WHERE id = :id',
                [
                    'ticker' => OptionChainService::contractTicker($stock->getTicker(), (int) $contract['expiry_serial'], (string) $contract['option_type'], $strike),
                    'strike' => number_format($strike, 8, '.', ''),
                    'scale' => $scale,
                    'id' => (int) $contract['id'],
                ],
            );
        }

        // The players' open interest is the sum of their positions, read back so a reverse split's rounding is in it.
        $conn->executeStatement(
            'UPDATE option_contracts oc
             SET oc.open_interest = (SELECT COALESCE(SUM(uo.quantity), 0) FROM user_options uo WHERE uo.option_contract_id = oc.id)
             WHERE oc.id IN (:ids)',
            $idList,
            $idTypes,
        );

        return $holders;
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
            ['stock_id' => $stock->getId()],
        );

        $money = static fn(string $amount): string => number_format(((float) str_replace(',', '', $amount)) * $priceRatio, 2);

        foreach ($rows as $row) {
            $original = (string) $row['description'];
            $restated = $original;

            // "Q-Earnings: $11.75" / "Q-Earnings: -$3.56": the sign sits outside the dollar sign.
            $restated = preg_replace_callback(
                '/^(Q-Earnings:\s*-?\$)([\d,]+\.\d{2})/',
                static fn(array $m): string => $m[1] . $money($m[2]),
                $restated,
            ) ?? $restated;

            $restated = preg_replace_callback(
                '/((?:Beat|Missed) expectations by\s*\$)([\d,]+\.\d{2})/',
                static fn(array $m): string => $m[1] . $money($m[2]),
                $restated,
            ) ?? $restated;

            $restated = preg_replace_callback(
                '/(Paid\s+\$)([\d,]+\.\d{2})(\/share\s+div)/',
                static fn(array $m): string => $m[1] . $money($m[2]) . $m[3],
                $restated,
            ) ?? $restated;

            $restated = preg_replace_callback(
                '/(Bought\s+back\s+)([\d,]+)(\s+shares)/',
                static fn(array $m): string => $m[1] . number_format(round(((float) str_replace(',', '', $m[2])) / $priceRatio)) . $m[3],
                $restated,
            ) ?? $restated;

            if ($restated !== $original) {
                $conn->executeStatement(
                    'UPDATE stock_events SET description = :description WHERE id = :id',
                    ['description' => $restated, 'id' => $row['id']],
                );
            }
        }
    }
}
