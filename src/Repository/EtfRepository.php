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
}
