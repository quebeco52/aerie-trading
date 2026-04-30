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
            SELECT u.id, (u.cash_balance + COALESCE(SUM(us.quantity * s.price), 0)), :now
            FROM users u
            LEFT JOIN user_stocks us ON u.id = us.user_id
            LEFT JOIN stocks s ON us.stock_id = s.id
            GROUP BY u.id
        ";

        $conn->executeStatement($snapshotSql, [
            'now' => (new \DateTime())->format('Y-m-d H:i:s')
        ]);
    }

    /**
     * Calculates and records an immediate snapshot for a single user.
     * Best used immediately after a user executes a trade.
     */
    public function recordUserSnapshot(User $user): void
    {
        $conn = $this->entityManager->getConnection();

        $sql = "
            SELECT COALESCE(SUM(us.quantity * s.price), 0)
            FROM user_stocks us
            INNER JOIN stocks s ON us.stock_id = s.id
            WHERE us.user_id = :user_id
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