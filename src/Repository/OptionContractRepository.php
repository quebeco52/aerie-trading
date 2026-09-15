<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OptionContract;
use App\Entity\Stock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OptionContract>
 */
class OptionContractRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OptionContract::class);
    }

    public function findOneByTicker(string $ticker): ?OptionContract
    {
        return $this->findOneBy(['ticker' => $ticker]);
    }

    /**
     * Every listed contract on one name, ordered as a chain reads: nearest expiry first, then up the ladder.
     *
     * @return array<int, OptionContract>
     */
    public function findChain(Stock $stock): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.stock = :stock')
            ->andWhere('o.status = :status')
            ->setParameter('stock', $stock)
            ->setParameter('status', OptionContract::STATUS_ACTIVE)
            ->orderBy('o.expiresAtTime', 'ASC')
            ->addOrderBy('o.strike', 'ASC')
            ->addOrderBy('o.optionType', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every listed contract across the market, for the repricing sweep.
     *
     * @return array<int, OptionContract>
     */
    public function findAllActive(): array
    {
        return $this->findBy(['status' => OptionContract::STATUS_ACTIVE]);
    }
}
