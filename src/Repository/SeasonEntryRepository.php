<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Season;
use App\Entity\SeasonEntry;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeasonEntry>
 */
class SeasonEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeasonEntry::class);
    }

    /** @return list<SeasonEntry> */
    public function forSeason(Season $season): array
    {
        /** @var list<SeasonEntry> */
        return $this->createQueryBuilder('e')
            ->addSelect('u')
            ->join('e.user', 'u')
            ->where('e.season = :season')
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }

    public function findOne(Season $season, User $user): ?SeasonEntry
    {
        return $this->findOneBy(['season' => $season, 'user' => $user]);
    }

    /** @return list<SeasonEntry> Closed seasons' podium, ranks 1 to $places, newest season first. */
    public function podiums(int $places, int $seasons): array
    {
        /** @var list<SeasonEntry> */
        return $this->createQueryBuilder('e')
            ->addSelect('s', 'u')
            ->join('e.season', 's')
            ->join('e.user', 'u')
            ->where('s.status = :closed')
            ->andWhere('e.finalRank IS NOT NULL AND e.finalRank <= :places')
            ->setParameter('closed', Season::STATUS_CLOSED)
            ->setParameter('places', $places)
            ->orderBy('s.number', 'DESC')
            ->addOrderBy('e.finalRank', 'ASC')
            ->setMaxResults($places * $seasons)
            ->getQuery()
            ->getResult();
    }

    /** @return list<SeasonEntry> The account's finished seasons, newest first. */
    public function history(User $user, int $limit): array
    {
        /** @var list<SeasonEntry> */
        return $this->createQueryBuilder('e')
            ->addSelect('s')
            ->join('e.season', 's')
            ->where('e.user = :user')
            ->andWhere('s.status = :closed')
            ->setParameter('user', $user)
            ->setParameter('closed', Season::STATUS_CLOSED)
            ->orderBy('s.number', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
