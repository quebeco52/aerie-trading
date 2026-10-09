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
    use NewestPerStockTrait;

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
     * The company's stories the news desk ran as headlines, newest first.
     *
     * @return list<StockEvent>
     */
    public function findHeadlinesFor(Stock $stock, int $limit): array
    {
        return $this->findBy(['stock' => $stock, 'headline' => true], ['recordedAt' => 'DESC'], $limit);
    }

    /**
     * The newest events across every company, newest first, optionally only those of the given types, with each
     * event's company loaded in the same query.
     *
     * @param  list<string>|null $types
     * @return list<StockEvent>
     */
    public function findLatest(int $limit, ?array $types = null): array
    {
        $query = $this->createQueryBuilder('e')
            ->addSelect('s')
            ->join('e.stock', 's')
            ->orderBy('e.recordedAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit);

        if ($types !== null) {
            if ($types === []) {
                return [];
            }
            $query->andWhere('e.eventType IN (:types)')->setParameter('types', $types);
        }

        return $query->getQuery()->getResult();
    }

    /**
     * Each company's newest $perStock events, grouped by company and newest first within each.
     *
     * One query for the whole set rather than one per company, and the limit is per company, so
     * a tenant with a long history cannot crowd its neighbours out of the result.
     *
     * @param  list<Stock>       $stocks
     * @return list<StockEvent>
     */
    public function findForStocksNewestFirst(array $stocks, int $perStock): array
    {
        if ($stocks === []) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->andWhere('e.id IN (:ids)')
            ->setParameter('ids', $this->newestIdsPerStock($stocks, $perStock))
            ->orderBy('e.stock', 'ASC')
            ->addOrderBy('e.recordedAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
