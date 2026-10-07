<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Season>
 */
class SeasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Season::class);
    }

    public function findOpen(): ?Season
    {
        return $this->findOneBy(['status' => Season::STATUS_OPEN], ['number' => 'DESC']);
    }

    public function findLatest(): ?Season
    {
        return $this->findOneBy([], ['number' => 'DESC']);
    }

    /** @return list<Season> Newest first. */
    public function findClosed(int $limit): array
    {
        /** @var list<Season> */
        return $this->findBy(['status' => Season::STATUS_CLOSED], ['number' => 'DESC'], $limit);
    }
}
