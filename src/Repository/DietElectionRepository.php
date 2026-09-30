<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DietElection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DietElection>
 */
class DietElectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DietElection::class);
    }

    /**
     * Every vote on record, oldest first.
     *
     * @return list<DietElection>
     */
    public function findChronological(): array
    {
        return $this->findBy([], ['simTime' => 'ASC']);
    }

    /**
     * The most recent vote on record, or null before the first.
     */
    public function findLatest(): ?DietElection
    {
        return $this->findOneBy([], ['simTime' => 'DESC']);
    }
}
