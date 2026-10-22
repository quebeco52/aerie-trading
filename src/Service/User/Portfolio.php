<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\PortfolioHistory;
use App\Entity\TradeOrder;
use App\Entity\User;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FixedIncome;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service responsible for recording historical portfolio net asset values (NAV).
 */
class Portfolio
{
    /**
     * Per-user value sitting in open limit orders, which net asset value has to include.
     *
     * Placing a limit order moves the assets out of the balances a naive NAV sums: a BUY debits the cash to
     * escrow and a SELL removes the shares from user_stocks (see TradeExecutionService). Without this join, placing an
     * order would cut net worth and cancelling it would restore it. A BUY is held at
     * the price the cash was committed at; a SELL is held at the asset's live price, exactly as the shares
     * would have been valued had they still been in the holdings table. A buy stop commits no cash until it
     * fires, so it is held at nothing.
     *
     * The price joins are keyed on asset_type, not on the ticker alone: a ticker present in both tables
     * would otherwise match twice and count its escrow twice over.
     *
     * Only BUY and SELL appear. A resting SHORT escrows nothing — the borrow is located at the fill — and
     * neither does a resting COVER, which is the closing leg of a position already collateralized. Valuing
     * either would add an asset the account does not have.
     */
    public const OPEN_ORDER_ESCROW_DETAIL_SQL = "
        SELECT o.user_id,
               SUM(CASE WHEN " . TradeOrder::ESCROWS_CASH_SQL . "
                        THEN COALESCE(o.limit_price, 0) * o.quantity
                        ELSE 0
                   END) AS escrow_cash,
               SUM(CASE WHEN " . TradeOrder::ESCROWS_UNITS_SQL . "
                        THEN o.quantity * COALESCE(s.price, e.price, b.price, 0)
                        ELSE 0
                   END) AS escrow_long
        FROM trade_orders o
        LEFT JOIN stocks s ON s.ticker = o.ticker AND o.asset_type = 'STOCK'
        LEFT JOIN etfs   e ON e.ticker = o.ticker AND o.asset_type = 'ETF'
        LEFT JOIN bonds  b ON b.ticker = o.ticker AND o.asset_type = 'BOND'
        WHERE o.status = 'OPEN'
        GROUP BY o.user_id
    ";

    /**
     * Units each account holds in one instrument as a payout counts them, one row (user_id, units) per account: the
     * holding, a short negative, plus the units escrowed behind its open SELLs, which leave the holding while they
     * rest but are still the seller's. An open BUY escrowed cash and holds nothing. Binds :ticker and the holding's key
     * (:stock_id, :etf_id or :bond_id).
     *
     * @param 'STOCK'|'ETF'|'BOND' $assetType
     */
    public static function unitsHeldSql(string $assetType): string
    {
        [$holdings, $key] = match ($assetType) {
            'STOCK' => ['user_stocks', 'stock_id'],
            'ETF' => ['user_etfs', 'etf_id'],
            'BOND' => ['user_bonds', 'bond_id'],
        };

        return "SELECT legs.user_id, SUM(legs.qty) AS units
            FROM (
                SELECT user_id, quantity AS qty FROM {$holdings} WHERE {$key} = :{$key} AND quantity <> 0
                UNION ALL
                SELECT o.user_id, o.quantity AS qty
                FROM trade_orders o
                WHERE o.ticker = :ticker AND o.asset_type = '{$assetType}' AND o.status = 'OPEN' AND " . TradeOrder::ESCROWS_UNITS_SQL . "
            ) legs
            GROUP BY legs.user_id";
    }

    /**
     * The same book as one number, for the surfaces that only need net worth.
     *
     * Built from the detail fragment rather than written out again: the margin sweep needs the two legs
     * apart and every NAV query needs them together, and two hand-written copies of the same CASE is how
     * one of them ends up counting a side the other does not.
     */
    public const OPEN_ORDER_ESCROW_SQL = "
        SELECT d.user_id, d.escrow_cash + d.escrow_long AS escrow_val
        FROM (" . self::OPEN_ORDER_ESCROW_DETAIL_SQL . ") d
    ";

    /**
     * Every account's net worth with its parts: cash less margin debt, plus stocks, funds, bonds and options at the
     * live price, plus value sitting in open orders. The one statement behind the leaderboard, the weekly snapshot,
     * the season record, the snapshot after each trade and the dashboard, so none of them can rank or show a
     * different definition. Each part is summed for its own account, so netWorthOf() filtering it to one account
     * reads only that account's rows.
     *
     * A short's quantity is negative, and so is a written option's, so one SUM marks both sides: a written contract
     * is a liability, and a book short more premium than it holds is worth less. A contract is a hundred shares.
     */
    public const NET_WORTH_SQL = "
        SELECT nw.user_id, nw.username, nw.cash_balance, nw.margin_debit,
               nw.stock_value, nw.etf_value, nw.bond_value, nw.option_value, nw.escrow_value,
               (nw.net_cash + nw.stock_value + nw.etf_value + nw.bond_value + nw.option_value + nw.escrow_value) AS total_value
        FROM (
            SELECT u.id AS user_id,
                   COALESCE(u.username, 'Anonymous Trader') AS username,
                   u.cash_balance,
                   u.margin_debit,
                   u.cash_balance - u.margin_debit AS net_cash,
                   COALESCE((SELECT SUM(us.quantity * s.price) FROM user_stocks us JOIN stocks s ON us.stock_id = s.id WHERE us.user_id = u.id), 0) AS stock_value,
                   COALESCE((SELECT SUM(ue.quantity * e.price) FROM user_etfs ue JOIN etfs e ON ue.etf_id = e.id WHERE ue.user_id = u.id), 0) AS etf_value,
                   COALESCE((SELECT SUM(ub.quantity * b.price) FROM user_bonds ub JOIN bonds b ON ub.bond_id = b.id WHERE ub.user_id = u.id), 0) AS bond_value,
                   COALESCE((SELECT SUM(uo.quantity * oc.price * " . FinancialConstants::OPTION_CONTRACT_MULTIPLIER . ") FROM user_options uo JOIN option_contracts oc ON uo.option_contract_id = oc.id WHERE uo.user_id = u.id), 0) AS option_value,
                   COALESCE((SELECT escrow.escrow_val FROM (" . self::OPEN_ORDER_ESCROW_SQL . ") escrow WHERE escrow.user_id = u.id), 0) AS escrow_value
            FROM users u
        ) nw
    ";

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Accrues interest on every user's idle cash at the prevailing brokerage sweep rate.
     *
     * Uncommitted brokerage cash is swept into overnight money market instruments, so it earns the policy
     * rate less the intermediary's spread. Without this, holding cash was free: an inverted curve paying 5%
     * on the sidelines looked identical to a zero-rate boom, and the opportunity cost that drives real
     * allocation decisions did not exist for the player.
     *
     * Compounded continuously to match the rest of the engine's time stepping.
     *
     * @param float $annualRate The annualized sweep rate (policy rate net of the cash yield spread).
     * @param float $dt         Elapsed simulated time in years since the last accrual.
     */
    public function accrueCashInterest(float $annualRate, float $dt): void
    {
        if ($annualRate <= 0.0 || $dt <= 0.0) {
            return;
        }

        $periodRate = exp($annualRate * $dt) - 1.0;
        if ($periodRate <= 0.0) {
            return;
        }

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE users SET cash_balance = ROUND(cash_balance * (1 + :rate), 2) WHERE cash_balance > 0',
            ['rate' => $periodRate],
        );
    }

    /** The annual rate idle brokerage cash earns: the smoothed policy rate less the sweep spread, floored at zero. */
    public static function cashSweepRate(float $policyRateEma): float
    {
        return FixedIncome::moneyMarketYield($policyRateEma);
    }

    /**
     * Records a historical snapshot for EVERY user in the system simultaneously.
     * Uses optimized raw SQL to prevent memory leaks during the Engine loop.
     */
    public function recordBulkSnapshots(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO portfolio_history (user_id, total_value, recorded_at)
             SELECT nw.user_id, nw.total_value, :now FROM (' . self::NET_WORTH_SQL . ') nw',
            ['now' => (new \DateTime())->format('Y-m-d H:i:s')],
        );
    }

    /** @return array<int, float> Net worth by account id. */
    public function netWorths(): array
    {
        $values = [];
        foreach ($this->entityManager->getConnection()->fetchAllAssociative(self::NET_WORTH_SQL) as $row) {
            $values[(int) $row['user_id']] = (float) $row['total_value'];
        }

        return $values;
    }

    /**
     * One account's net worth with its parts, on the statement every ranking uses.
     *
     * @return array{cash_balance: float, margin_debit: float, stock_value: float, etf_value: float, bond_value: float, option_value: float, escrow_value: float, total_value: float}
     */
    public function netWorthOf(int $userId): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT one.* FROM (' . self::NET_WORTH_SQL . ') one WHERE one.user_id = :user_id',
            ['user_id' => $userId],
        ) ?: [];

        $parts = [];
        foreach (['cash_balance', 'margin_debit', 'stock_value', 'etf_value', 'bond_value', 'option_value', 'escrow_value', 'total_value'] as $part) {
            $parts[$part] = (float) ($row[$part] ?? 0.0);
        }

        return $parts;
    }

    /**
     * What one account has working in open orders, on the definition net worth counts it at: the cash a resting buy
     * has committed, and the shares a resting sell has taken out of the holdings, at their live price.
     *
     * @return array{cash: float, shares: float}
     */
    public function openOrderEscrow(int $userId): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT d.escrow_cash, d.escrow_long FROM (' . self::OPEN_ORDER_ESCROW_DETAIL_SQL . ') d WHERE d.user_id = :user_id',
            ['user_id' => $userId],
        ) ?: [];

        return ['cash' => (float) ($row['escrow_cash'] ?? 0.0), 'shares' => (float) ($row['escrow_long'] ?? 0.0)];
    }

    /**
     * Records an immediate snapshot of one account, after a trade or a fresh start, on the same statement the weekly
     * snapshot writes.
     */
    public function recordUserSnapshot(User $user): void
    {
        $history = new PortfolioHistory();
        $history->setUser($user);
        $history->setTotalValue((string) $this->netWorthOf((int) $user->getId())['total_value']);
        $history->setRecordedAt(new \DateTime());

        $this->entityManager->persist($history);
    }
}
