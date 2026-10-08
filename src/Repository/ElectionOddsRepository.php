<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ElectionOdds;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ElectionOdds>
 */
class ElectionOddsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectionOdds::class);
    }

    /**
     * The forecasts made for a vote, oldest first. The vote is matched within a tolerance, since a vote is held on the
     * first tick past its day and the forecasts before it name the day itself.
     *
     * @return list<ElectionOdds>
     */
    public function findForVote(float $voteAt, float $tolerance): array
    {
        /** @var list<ElectionOdds> */
        return $this->createQueryBuilder('o')
            ->where('o.voteAt BETWEEN :low AND :high')
            ->setParameter('low', $voteAt - $tolerance)
            ->setParameter('high', $voteAt + $tolerance)
            ->orderBy('o.simTime', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
