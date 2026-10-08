<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RateDecision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RateDecision>
 */
class RateDecisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RateDecision::class);
    }

    /**
     * The meetings since a moment, oldest first.
     *
     * @return list<RateDecision>
     */
    public function findSince(float $simTime): array
    {
        /** @var list<RateDecision> */
        return $this->createQueryBuilder('d')
            ->where('d.simTime >= :since')
            ->setParameter('since', $simTime)
            ->orderBy('d.simTime', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
