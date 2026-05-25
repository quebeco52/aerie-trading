<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\UserStock;
use App\Entity\PortfolioHistory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service responsible for recording historical portfolio net asset values (NAV).
 */
class Portfolio
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

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
                   (u.cash_balance + COALESCE(stock_totals.stock_val, 0) + COALESCE(etf_totals.etf_val, 0)), 
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

        $sql = "
            SELECT (
                COALESCE((SELECT SUM(us.quantity * s.price) FROM user_stocks us JOIN stocks s ON us.stock_id = s.id WHERE us.user_id = :user_id), 0) +
                COALESCE((SELECT SUM(ue.quantity * e.price) FROM user_etfs ue JOIN etfs e ON ue.etf_id = e.id WHERE ue.user_id = :user_id), 0)
            ) as total_val
        ";

        $stockValue = (float) $conn->fetchOne($sql, ['user_id' => $user->getId()]);
        $portfolioValue = (float) $user->getCashBalance() + $stockValue;

        $history = new PortfolioHistory();
        $history->setUser($user);
        $history->setTotalValue((string) $portfolioValue);
        $history->setRecordedAt(new \DateTime());
        
        $this->entityManager->persist($history);
    }
}