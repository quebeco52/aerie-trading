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
     * whole history entities to reach it would load the entire tick series into memory.
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
            ->orderBy('h.recordedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): float => (float) ($row['price'] ?? 0.0), $rows);
    }
}
