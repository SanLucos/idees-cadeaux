<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\Tools\Pagination\Paginator;
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

    /**
     * The in-app centre, newest first.
     *
     * @return Paginator<Notification>
     */
    public function pageFor(User $user, int $page, int $perPage): Paginator
    {
        $query = $this->createQueryBuilder('n')
            ->andWhere('n.user = :user AND n.inApp = true')
            ->setParameter('user', $user->getId(), 'uuid')
            ->orderBy('n.createdAt', 'DESC')->addOrderBy('n.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery();

        return new Paginator($query, fetchJoinCollection: false);
    }

    public function countUnread(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.user = :user AND n.inApp = true AND n.readAt IS NULL')
            ->setParameter('user', $user->getId(), 'uuid')
            ->getQuery()->getSingleScalarResult();
    }

    public function markAllRead(User $user): void
    {
        $this->createQueryBuilder('n')
            ->update()
            ->set('n.readAt', ':now')->set('n.updatedAt', ':now')
            ->andWhere('n.user = :user AND n.readAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user->getId(), 'uuid')
            ->getQuery()->execute();
    }
}
