<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Etf;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Etf>
 */
class EtfRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Etf::class);
    }

    /**
     * The fund trading under a ticker, or null when no such listing exists.
     */
    public function findOneByTicker(string $ticker): ?Etf
    {
        return $this->findOneBy(['ticker' => $ticker]);
    }

    /**
     * Funds whose ticker or name contains the query.
     *
     * @return list<Etf>
     */
    public function searchByTickerOrName(string $query, int $limit = 5): array
    {
        return $this->createQueryBuilder('e')
            ->where('LOWER(e.ticker) LIKE LOWER(:query) OR LOWER(e.name) LIKE LOWER(:query)')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The single best match for a query, preferring an exact ticker match.
     */
    public function findBestMatch(string $query): ?Etf
    {
        $exact = $this->findOneByTicker(strtoupper($query));
        if ($exact !== null) {
            return $exact;
        }

        return $this->createQueryBuilder('e')
            ->where('LOWER(e.ticker) LIKE LOWER(:query) OR LOWER(e.name) LIKE LOWER(:query)')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
