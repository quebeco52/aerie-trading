<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Stock;
use App\Entity\StockHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockHistory>
 */
class StockHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockHistory::class);
    }

    /**
     * A listing's most recently persisted prices, newest first.
     *
     * Selects the price column alone: a change calculation reads one number per row, and hydrating
     * whole history entities to reach it would load the entire tick series into memory. Ordered by
     * simulated time: recorded_at is wall clock to the second, and a fast clock writes dozens of
     * rows a second, so ties left the oldest row of the window arbitrary.
     *
     * @return list<float>
     */
    public function findRecentPrices(Stock $stock, int $limit): array
    {
        /** @var list<array{price: string|float|null}> $rows */
        $rows = $this->createQueryBuilder('h')
            ->select('h.price')
            ->andWhere('h.stock = :stock')
            ->setParameter('stock', $stock)
            ->orderBy('h.simTime', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): float => (float) ($row['price'] ?? 0.0), $rows);
    }
}
