<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Stock;
use App\Entity\StockEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockEvent>
 */
class StockEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockEvent::class);
    }

    /**
     * A company's most recent filings and announcements, newest first.
     *
     * @return list<StockEvent>
     */
    public function findRecentFor(Stock $stock, int $limit): array
    {
        return $this->findBy(['stock' => $stock], ['recordedAt' => 'DESC'], $limit);
    }

    /**
     * Events for a set of companies, grouped by company and newest first within each.
     *
     * One query for the whole set rather than one per company. Doctrine has no portable way to
     * express a per-company limit in a single query, so the overall cap is the caller's row budget
     * and the grouping is done in PHP; the (stock_id, recorded_at) index keeps this an index scan
     * as history accumulates.
     *
     * @param  list<Stock>       $stocks
     * @return list<StockEvent>
     */
    public function findForStocksNewestFirst(array $stocks, int $limit): array
    {
        if ($stocks === []) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->andWhere('e.stock IN (:stocks)')
            ->setParameter('stocks', $stocks)
            ->orderBy('e.stock', 'ASC')
            ->addOrderBy('e.recordedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
