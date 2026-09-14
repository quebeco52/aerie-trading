<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Stock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Stock>
 */
class StockRepository extends ServiceEntityRepository
{
    // --- Search ---

    /** Matches returned by the type-ahead; more than a short list is scrolled past rather than read. */
    private const SEARCH_SUGGESTION_LIMIT = 5;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Stock::class);
    }

    /**
     * The listed company trading under a ticker, or null when no such listing exists.
     */
    public function findOneByTicker(string $ticker): ?Stock
    {
        return $this->findOneBy(['ticker' => $ticker]);
    }

    /**
     * The other listings in a company's sector, for comparative analysis.
     *
     * The company itself is excluded here rather than by every caller, because a peer table that
     * lists the company against itself reads as a duplicate listing.
     *
     * @return list<Stock>
     */
    public function findPeersOf(Stock $stock): array
    {
        $sector = $stock->getSector();
        if ($sector === '') {
            return [];
        }

        $qb = $this->createQueryBuilder('s')
            ->where('s.sector = :sector')
            ->setParameter('sector', $sector);

        if ($stock->getId() !== null) {
            $qb->andWhere('s.id != :self')->setParameter('self', $stock->getId());
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Listings whose ticker or name contains the typed text, for the search type-ahead.
     *
     * @return list<Stock>
     */
    public function searchByTickerOrName(string $query, ?int $limit = null): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.ticker LIKE :query OR s.name LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults($limit ?? self::SEARCH_SUGGESTION_LIMIT)
            ->getQuery()
            ->getResult();
    }

    /**
     * The single best match for a typed query, preferring an exact ticker over a partial match.
     *
     * Used when the search box is submitted without picking a suggestion, so the reader lands on the
     * company they spelled out rather than on whichever partial match happened to sort first.
     */
    public function findBestMatch(string $query): ?Stock
    {
        $exact = $this->findOneByTicker(strtoupper($query));
        if ($exact !== null) {
            return $exact;
        }

        return $this->createQueryBuilder('s')
            ->where('s.ticker LIKE :query OR s.name LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
