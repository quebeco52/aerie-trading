<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function countUnread(User $user): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user AND read_at IS NULL',
            ['user' => $user->getId()]
        );
    }

    /** @return list<Notification> Newest first. */
    public function page(User $user, int $page, int $perPage): array
    {
        /** @var list<Notification> */
        return $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countFor(User $user): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user',
            ['user' => $user->getId()]
        );
    }

    public function markAllRead(User $user): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE notifications SET read_at = :now WHERE user_id = :user AND read_at IS NULL',
            ['now' => (new \DateTime())->format('Y-m-d H:i:s'), 'user' => $user->getId()]
        );
    }
}
