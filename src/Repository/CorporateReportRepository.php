<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CorporateReport;
use App\Entity\Stock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CorporateReport>
 */
class CorporateReportRepository extends ServiceEntityRepository
{
    use NewestPerStockTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CorporateReport::class);
    }

    /**
     * A company's most recently filed report, which is the prior quarter to the one being struck.
     */
    public function findLatestFor(Stock $stock): ?CorporateReport
    {
        return $this->findOneBy(['stock' => $stock], ['recordedAt' => 'DESC']);
    }

    /**
     * Each company's newest $perStock filings, grouped by company and newest first within each.
     *
     * One query for the whole set rather than one per company, because the caller is rendering a
     * street of tenants and a query per tile is a query per row of the page.
     *
     * @param  list<Stock>           $stocks
     * @return list<CorporateReport>
     */
    public function findForStocksNewestFirst(array $stocks, int $perStock): array
    {
        if ($stocks === []) {
            return [];
        }

        return $this->createQueryBuilder('r')
            ->andWhere('r.id IN (:ids)')
            ->setParameter('ids', $this->newestIdsPerStock($stocks, $perStock))
            ->orderBy('r.stock', 'ASC')
            ->addOrderBy('r.recordedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
