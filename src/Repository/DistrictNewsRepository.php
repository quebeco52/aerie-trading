<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DistrictNews;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DistrictNews>
 */
class DistrictNewsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DistrictNews::class);
    }

    /**
     * The newest district stories, newest first.
     *
     * @return list<DistrictNews>
     */
    public function findLatest(int $limit): array
    {
        return $this->findBy([], ['recordedAt' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
