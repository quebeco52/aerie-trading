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
        return $this->findBy(
            ['status' => Bond::STATUS_ACTIVE],
            ['tenorYears' => 'ASC', 'maturesAtTime' => 'ASC']
        );
    }
}
