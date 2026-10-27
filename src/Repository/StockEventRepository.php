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
     * A company's events of the given types stamped inside a span of simulated time, oldest first; a null start reaches
     * back to the first. Rows from before events carried their simulation time have none and are left out.
     *
     * @param  list<string>     $types
     * @return list<StockEvent>
     */
    public function findBetween(Stock $stock, ?float $from, float $to, array $types): array
    {
        if ($types === []) {
            return [];
        }

        $query = $this->createQueryBuilder('e')
            ->andWhere('e.stock = :stock')
            ->andWhere('e.simTime IS NOT NULL AND e.simTime <= :to')
            ->andWhere('e.eventType IN (:types)')
            ->setParameter('stock', $stock)
            ->setParameter('to', $to)
            ->setParameter('types', $types)
            ->orderBy('e.simTime', 'ASC')
            ->addOrderBy('e.id', 'ASC');
        if ($from !== null) {
            $query->andWhere('e.simTime >= :from')->setParameter('from', $from);
        }

        return $query->getQuery()->getResult();
    }

    /**
     * The newest events of the given types across a set of companies, newest first, each event's company loaded in the
     * same query.
     *
     * @param  list<Stock>      $stocks
     * @param  list<string>     $types
     * @return list<StockEvent>
     */
    public function findLatestAmong(array $stocks, array $types, int $limit): array
    {
        if ($stocks === [] || $types === []) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->addSelect('s')
            ->join('e.stock', 's')
            ->andWhere('e.stock IN (:stocks)')
            ->andWhere('e.eventType IN (:types)')
            ->setParameter('stocks', $stocks)
            ->setParameter('types', $types)
            ->orderBy('e.recordedAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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
