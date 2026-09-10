<?php

namespace App\Service\User;

use App\Entity\User;
use App\Entity\UserStock;
use App\Entity\PortfolioHistory;
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
     * escrow and a SELL removes the shares from user_stocks (see TradeExecutionService). Without this join
     * placing an order destroyed net worth on the chart and on the leaderboard, and cancelling it created
     * net worth back — so every ranking was really a ranking of who had no orders working. A BUY is held at
     * the price the cash was committed at; a SELL is held at the asset's live price, exactly as the shares
     * would have been valued had they still been in the holdings table.
     *
     * The price joins are keyed on asset_type, not on the ticker alone: a ticker present in both tables
     * would otherwise match twice and count its escrow twice over.
     *
     * Only BUY and SELL appear. A resting SHORT escrows nothing — the borrow is located at the fill — and
     * neither does a resting COVER, which is the closing leg of a position already collateralized. Valuing
     * either of them added an asset the account does not have, so working an offer inflated net worth.
     */
    public const OPEN_ORDER_ESCROW_DETAIL_SQL = "
        SELECT o.user_id,
               SUM(CASE WHEN o.action = 'BUY'
                        THEN COALESCE(o.limit_price, 0) * o.quantity
                        ELSE 0
                   END) AS escrow_cash,
               SUM(CASE WHEN o.action = 'SELL'
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

    public function __construct(
        private EntityManagerInterface $entityManager
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
            ['rate' => $periodRate]
        );
    }

    /**
     * Records a historical snapshot for EVERY user in the system simultaneously.
     * Uses optimized raw SQL to prevent memory leaks during the Engine loop.
     */
    public function recordBulkSnapshots(): void
    {
        $conn = $this->entityManager->getConnection();
        
        $snapshotSql = "
            INSERT INTO portfolio_history (user_id, total_value, recorded_at)
            SELECT u.id,
                   (u.cash_balance - u.margin_debit + COALESCE(stock_totals.stock_val, 0) + COALESCE(etf_totals.etf_val, 0) + COALESCE(bond_totals.bond_val, 0) + COALESCE(escrow.escrow_val, 0)),
                   :now
            FROM users u
            LEFT JOIN (
                SELECT us.user_id, SUM(us.quantity * s.price) as stock_val
                FROM user_stocks us
                JOIN stocks s ON us.stock_id = s.id
                GROUP BY us.user_id
            ) stock_totals ON stock_totals.user_id = u.id
            LEFT JOIN (
                SELECT ue.user_id, SUM(ue.quantity * e.price) as etf_val
                FROM user_etfs ue
                JOIN etfs e ON ue.etf_id = e.id
                GROUP BY ue.user_id
            ) etf_totals ON etf_totals.user_id = u.id
            LEFT JOIN (
                SELECT ub.user_id, SUM(ub.quantity * b.price) as bond_val
                FROM user_bonds ub
                JOIN bonds b ON ub.bond_id = b.id
                GROUP BY ub.user_id
            ) bond_totals ON bond_totals.user_id = u.id
            LEFT JOIN (" . self::OPEN_ORDER_ESCROW_SQL . ") escrow ON escrow.user_id = u.id
        ";

        $conn->executeStatement($snapshotSql, [
            'now' => (new \DateTime())->format('Y-m-d H:i:s')
        ]);
    }

    /**
     * Calculates and records an immediate snapshot for a single user.
     * Best used immediately after a user executes a trade.
     *
     * @param User $user The user entity whose portfolio snapshot should be recorded.
     */
    public function recordUserSnapshot(User $user): void
    {
        $conn = $this->entityManager->getConnection();

        // Same three components as the bulk sweep, but filtered to one user rather than grouped over all
        // of them: this runs on every trade, so the escrow leg is an indexed aggregate rather than a
        // derived table built for the whole roster and then thrown away.
        $sql = "
            SELECT (
                COALESCE((SELECT SUM(us.quantity * s.price) FROM user_stocks us JOIN stocks s ON us.stock_id = s.id WHERE us.user_id = :user_id), 0) +
                COALESCE((SELECT SUM(ue.quantity * e.price) FROM user_etfs ue JOIN etfs e ON ue.etf_id = e.id WHERE ue.user_id = :user_id), 0) +
                COALESCE((SELECT SUM(ub.quantity * b.price) FROM user_bonds ub JOIN bonds b ON ub.bond_id = b.id WHERE ub.user_id = :user_id), 0) +
                COALESCE((
                    SELECT SUM(CASE WHEN o.action = 'BUY'
                                    THEN COALESCE(o.limit_price, 0) * o.quantity
                                    ELSE o.quantity * COALESCE(s2.price, e2.price, b2.price, 0)
                               END)
                    FROM trade_orders o
                    LEFT JOIN stocks s2 ON s2.ticker = o.ticker AND o.asset_type = 'STOCK'
                    LEFT JOIN etfs   e2 ON e2.ticker = o.ticker AND o.asset_type = 'ETF'
                    LEFT JOIN bonds  b2 ON b2.ticker = o.ticker AND o.asset_type = 'BOND'
                    WHERE o.user_id = :user_id AND o.status = 'OPEN'
                ), 0)
            ) as total_val
        ";

        $stockValue = (float) $conn->fetchOne($sql, ['user_id' => $user->getId()]);

        // Borrowed cash is spent but still owed, so it comes straight back out. A short needs no term of
        // its own: its quantity is negative, so quantity times price already marks the obligation, and its
        // proceeds are already sitting in the cash balance.
        $portfolioValue = (float) $user->getCashBalance() - (float) $user->getMarginDebit() + $stockValue;

        $history = new PortfolioHistory();
        $history->setUser($user);
        $history->setTotalValue((string) $portfolioValue);
        $history->setRecordedAt(new \DateTime());
        
        $this->entityManager->persist($history);
    }
}