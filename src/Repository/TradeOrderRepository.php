<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TradeOrder;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TradeOrder>
 */
class TradeOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradeOrder::class);
    }

    /**
     * Every execution on an account, oldest first.
     *
     * Weighted-average cost is path dependent — a sell consumes the basis laid down by the buys
     * before it — so the ordering is part of the query rather than left to the caller. The same
     * query written out at four call sites is what let the stock page and the dashboard report a
     * different cost, and a different P&L, for one position.
     *
     * @return list<TradeOrder>
     */
    public function findFilledForUser(User $user): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.user = :user')
            ->andWhere('o.status = :status')
            ->orderBy('o.createdAt', 'ASC')
            ->setParameter('user', $user)
            ->setParameter('status', TradeOrder::STATUS_FILLED)
            ->getQuery()
            ->getResult();
    }

    /**
     * Every execution on one position, oldest first.
     *
     * @return list<TradeOrder>
     */
    public function findFilledForUserAndTicker(User $user, string $ticker): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.user = :user')
            ->andWhere('o.ticker = :ticker')
            ->andWhere('o.status = :status')
            ->orderBy('o.createdAt', 'ASC')
            ->setParameter('user', $user)
            ->setParameter('ticker', $ticker)
            ->setParameter('status', TradeOrder::STATUS_FILLED)
            ->getQuery()
            ->getResult();
    }

    /**
     * Orders still resting on the book for an account, newest first.
     *
     * @return list<TradeOrder>
     */
    public function findOpenForUser(User $user): array
    {
        return $this->findBy(
            ['user' => $user, 'status' => TradeOrder::STATUS_OPEN],
            ['createdAt' => 'DESC']
        );
    }

    /**
     * Orders still resting on the book for one of an account's positions, newest first.
     *
     * @return list<TradeOrder>
     */
    public function findOpenForUserAndTicker(User $user, string $ticker): array
    {
        return $this->findBy(
            ['user' => $user, 'ticker' => $ticker, 'status' => TradeOrder::STATUS_OPEN],
            ['createdAt' => 'DESC']
        );
    }

    /**
     * Every order resting on one ticker's book, across all accounts.
     *
     * @return list<TradeOrder>
     */
    public function findOpenByTicker(string $ticker): array
    {
        return $this->findBy(['ticker' => $ticker, 'status' => TradeOrder::STATUS_OPEN]);
    }

    /**
     * One of an account's resting orders, by id.
     *
     * Scoped to the owner in the query so a cancellation cannot reach another trader's order by id.
     */
    public function findOpenForUserById(User $user, int $orderId): ?TradeOrder
    {
        return $this->findOneBy([
            'id' => $orderId,
            'user' => $user,
            'status' => TradeOrder::STATUS_OPEN,
        ]);
    }

    /**
     * An account's settled order history for one ticker — fills and cancellations, newest first.
     *
     * @return list<TradeOrder>
     */
    public function findSettledForUserAndTicker(User $user, string $ticker, int $limit): array
    {
        return $this->findBy(
            [
                'user' => $user,
                'ticker' => $ticker,
                'status' => [TradeOrder::STATUS_FILLED, TradeOrder::STATUS_CANCELLED],
            ],
            ['createdAt' => 'DESC'],
            $limit
        );
    }

    /**
     * An account's settled order history across every ticker, newest first.
     *
     * @return list<TradeOrder>
     */
    public function findSettledForUser(User $user, int $limit): array
    {
        return $this->findBy(
            [
                'user' => $user,
                'status' => [TradeOrder::STATUS_FILLED, TradeOrder::STATUS_CANCELLED],
            ],
            ['createdAt' => 'DESC'],
            $limit
        );
    }
}
