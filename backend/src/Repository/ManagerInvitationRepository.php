<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ManagerInvitation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ManagerInvitation>
 */
class ManagerInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ManagerInvitation::class);
    }

    /** The child's invitation still open, if any (one at a time). */
    public function findOpenForChild(User $child): ?ManagerInvitation
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.child = :child AND i.expiresAt > :now')
            ->setParameter('child', $child->getId(), 'uuid')
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<ManagerInvitation> the invitations `$invitee` may still answer, newest first
     */
    public function findOpenForInvitee(User $invitee): array
    {
        /** @var list<ManagerInvitation> */
        return $this->createQueryBuilder('i')
            ->andWhere('i.invitee = :invitee AND i.expiresAt > :now')
            ->setParameter('invitee', $invitee->getId(), 'uuid')
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Expired ones, and those of a child that left: same as never sent. */
    public function deleteForChild(User $child): void
    {
        $this->createQueryBuilder('i')->delete()
            ->andWhere('i.child = :child')
            ->setParameter('child', $child->getId(), 'uuid')
            ->getQuery()
            ->execute();
    }
}
