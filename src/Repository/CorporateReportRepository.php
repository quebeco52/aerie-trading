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
     * Every filing for a set of companies, grouped by company and newest first within each.
     *
     * One query for the whole set rather than one per company, because the caller is rendering a
     * street of tenants and a query per tile is a query per row of the page.
     *
     * @param  list<Stock>           $stocks
     * @return list<CorporateReport>
     */
    public function findForStocksNewestFirst(array $stocks): array
    {
        if ($stocks === []) {
            return [];
        }

        return $this->createQueryBuilder('r')
            ->andWhere('r.stock IN (:stocks)')
            ->setParameter('stocks', $stocks)
            ->orderBy('r.stock', 'ASC')
            ->addOrderBy('r.recordedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
