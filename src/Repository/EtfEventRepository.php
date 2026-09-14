<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Etf;
use App\Entity\EtfEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EtfEvent>
 */
class EtfEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EtfEvent::class);
    }

    /**
     * A fund's most recent announcements, newest first.
     *
     * @return list<EtfEvent>
     */
    public function findRecentFor(Etf $etf, int $limit): array
    {
        return $this->findBy(['etf' => $etf], ['recordedAt' => 'DESC'], $limit);
    }
}
