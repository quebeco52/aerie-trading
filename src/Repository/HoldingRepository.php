<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserBond;
use App\Entity\UserEtf;
use App\Entity\UserStock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads an account's positions across the three instruments it can hold.
 *
 * One class rather than three near-identical repositories, because every caller that wants one kind
 * of holding wants all three: a portfolio is not a stock portfolio and separately a bond portfolio.
 * The joins are the point — a dashboard that loads positions and then touches each asset issues one
 * query per row, which is what these fetch joins exist to prevent.
 */
class HoldingRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /**
     * An account's equity positions with their listings already loaded.
     *
     * @return list<UserStock>
     */
    public function findStockHoldings(User $user): array
    {
        return $this->entityManager
            ->createQuery('SELECT us, s FROM ' . UserStock::class . ' us JOIN us.stock s WHERE us.user = :user')
            ->setParameter('user', $user)
            ->getResult();
    }

    /**
     * An account's fund positions with their funds already loaded.
     *
     * @return list<UserEtf>
     */
    public function findEtfHoldings(User $user): array
    {
        return $this->entityManager
            ->createQuery('SELECT ue, e FROM ' . UserEtf::class . ' ue JOIN ue.etf e WHERE ue.user = :user')
            ->setParameter('user', $user)
            ->getResult();
    }

    /**
     * An account's bond positions with their issues already loaded.
     *
     * @return list<UserBond>
     */
    public function findBondHoldings(User $user): array
    {
        return $this->entityManager
            ->createQuery('SELECT ub, b FROM ' . UserBond::class . ' ub JOIN ub.bond b WHERE ub.user = :user')
            ->setParameter('user', $user)
            ->getResult();
    }

    /**
     * An account's position in one listing, or null when it holds none.
     */
    public function findStockHolding(User $user, object $stock): ?UserStock
    {
        return $this->entityManager->getRepository(UserStock::class)
            ->findOneBy(['user' => $user, 'stock' => $stock]);
    }

    /**
     * An account's position in one fund, or null when it holds none.
     */
    public function findEtfHolding(User $user, object $etf): ?UserEtf
    {
        return $this->entityManager->getRepository(UserEtf::class)
            ->findOneBy(['user' => $user, 'etf' => $etf]);
    }

    /**
     * An account's position in one issue, or null when it holds none.
     */
    public function findBondHolding(User $user, object $bond): ?UserBond
    {
        return $this->entityManager->getRepository(UserBond::class)
            ->findOneBy(['user' => $user, 'bond' => $bond]);
    }
}
