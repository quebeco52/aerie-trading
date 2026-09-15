<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Bond;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Bond>
 */
class BondRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bond::class);
    }

    /**
     * The issue trading under a ticker, or null when no such issue exists.
     */
    public function findOneByTicker(string $ticker): ?Bond
    {
        return $this->findOneBy(['ticker' => $ticker]);
    }

    /**
     * Every issue still outstanding, ordered along the curve from the short end out.
     *
     * Sorted by tenor and then by maturity so the ladder reads as a term structure; sorted by ticker
     * it would interleave a fresh 30y with a 2y that has almost run off.
     *
     * @return list<Bond>
     */
    public function findActiveAlongTheCurve(): array
    {
        // SOVEREIGN only. The ladder this feeds is a term structure of one borrower, and a corporate issue
        // dropped into its tenor buckets would sit beside a government bond of the same maturity as though
        // the two were the same instrument priced differently — which is the one thing the page exists to
        // say they are not.
        return $this->createQueryBuilder('b')
            ->andWhere('b.status = :status')
            ->andWhere('b.issuer IS NULL')
            ->setParameter('status', Bond::STATUS_ACTIVE)
            ->orderBy('b.tenorYears', 'ASC')
            ->addOrderBy('b.maturesAtTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every outstanding corporate issue, with its issuer loaded.
     *
     * Ordered by issuer and then out along that issuer's own ladder, because a corporate market is read one
     * borrower at a time: what a reader compares is this company's three-year against its ten-year, not one
     * company's three-year against another's.
     *
     * @return list<Bond>
     */
    public function findActiveCorporate(): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('s')
            ->join('b.issuer', 's')
            ->andWhere('b.status = :status')
            ->setParameter('status', Bond::STATUS_ACTIVE)
            ->orderBy('s.ticker', 'ASC')
            ->addOrderBy('b.maturesAtTime', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
