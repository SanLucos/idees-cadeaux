<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\FriendshipOrigin;
use App\Entity\Enum\FriendshipStatus;
use App\Entity\Friendship;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Friendship>
 */
class FriendshipRepository extends ServiceEntityRepository
{
    public function __construct(private readonly EntityManagerInterface $em, ManagerRegistry $registry)
    {
        parent::__construct($registry, Friendship::class);
    }

    /**
     * The one active (pending or accepted, not soft-deleted) row
     * between two users, in either direction — spec §5.3 "une seule
     * demande active par paire".
     */
    public function findActiveBetween(User $a, User $b): ?Friendship
    {
        return $this->createQueryBuilder('f')
            ->andWhere('(f.requester = :a AND f.addressee = :b) OR (f.requester = :b AND f.addressee = :a)')
            ->andWhere('f.status IN (:activeStatuses)')
            ->setParameter('a', $a->getId(), 'uuid')
            ->setParameter('b', $b->getId(), 'uuid')
            ->setParameter('activeStatuses', [FriendshipStatus::Pending, FriendshipStatus::Accepted])
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The most recent row for this exact (requester, addressee)
     * direction, regardless of status or soft-deletion — needed for
     * the 30-day cooldown, which counts a cancelled request too (spec
     * §5.3: "compté depuis la création de la précédente, annulation
     * comprise").
     */
    public function findLatestForDirection(User $requester, User $addressee): ?Friendship
    {
        $filters = $this->em->getFilters();
        $wasEnabled = $filters->isEnabled('soft_deleteable');
        if ($wasEnabled) {
            $filters->disable('soft_deleteable');
        }

        try {
            return $this->createQueryBuilder('f')
                ->andWhere('f.requester = :requester AND f.addressee = :addressee')
                ->setParameter('requester', $requester->getId(), 'uuid')
                ->setParameter('addressee', $addressee->getId(), 'uuid')
                ->orderBy('f.createdAt', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        } finally {
            if ($wasEnabled) {
                $filters->enable('soft_deleteable');
            }
        }
    }

    /**
     * Did `$remover` ever remove a friendship with `$other`? Spec §5.16:
     * someone removed by a link's owner can't come back through it.
     */
    public function wasRemovedBy(User $remover, User $other): bool
    {
        return $this->withDeleted(fn () => (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('(f.requester = :a AND f.addressee = :b) OR (f.requester = :b AND f.addressee = :a)')
            ->andWhere('f.removedBy = :a AND f.deletedAt IS NOT NULL')
            ->setParameter('a', $remover->getId(), 'uuid')
            ->setParameter('b', $other->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult() > 0);
    }

    /**
     * Friendships created through `$owner`'s share links since `$since`,
     * across regenerations and removals (spec §5.16 "plafond anti-abus").
     */
    public function countJoinedViaLinkSince(User $owner, \DateTimeImmutable $since): int
    {
        return $this->withDeleted(fn () => (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.addressee = :owner AND f.origin = :link AND f.respondedAt >= :since')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->setParameter('link', FriendshipOrigin::Link)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult());
    }

    /**
     * @template T
     *
     * @param callable(): T $query
     *
     * @return T
     */
    private function withDeleted(callable $query): mixed
    {
        $filters = $this->em->getFilters();
        $wasEnabled = $filters->isEnabled('soft_deleteable');
        if ($wasEnabled) {
            $filters->disable('soft_deleteable');
        }

        try {
            return $query();
        } finally {
            if ($wasEnabled) {
                $filters->enable('soft_deleteable');
            }
        }
    }

    /**
     * @return string[] UUID strings of this user's accepted friends
     */
    public function findAcceptedFriendIds(User $user): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.requester) AS requesterId, IDENTITY(f.addressee) AS addresseeId')
            ->andWhere('f.status = :accepted')
            ->andWhere('f.requester = :user OR f.addressee = :user')
            ->setParameter('accepted', FriendshipStatus::Accepted)
            ->setParameter('user', $user->getId(), 'uuid')
            ->getQuery()
            ->getArrayResult();

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (string) ($row['requesterId'] === $user->getId()->toRfc4122() ? $row['addresseeId'] : $row['requesterId']);
        }

        return $ids;
    }

    public function areFriends(User $a, User $b): bool
    {
        return \in_array($b->getId()->toRfc4122(), $this->findAcceptedFriendIds($a), true);
    }

    /**
     * @return Friendship[]
     */
    public function findPendingIncoming(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.addressee = :user')
            ->andWhere('f.status = :pending')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('pending', FriendshipStatus::Pending)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Outgoing requests, declined ones included: the requester's view
     * masks `declined` as `pending`/`expired` (CLAUDE.md règle 4) via
     * App\Serializer\FriendshipNormalizer, not by hiding the row.
     *
     * @return Friendship[]
     */
    public function findOutgoingVisible(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.requester = :user')
            ->andWhere('f.status IN (:visible)')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('visible', [FriendshipStatus::Pending, FriendshipStatus::Declined, FriendshipStatus::Expired])
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Friendship[]
     */
    public function findAccepted(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.requester = :user OR f.addressee = :user')
            ->andWhere('f.status = :accepted')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('accepted', FriendshipStatus::Accepted)
            ->orderBy('f.respondedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
