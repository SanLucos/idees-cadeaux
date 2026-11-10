<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\FriendSuggestionStatus;
use App\Entity\Friendship;
use App\Entity\FriendSuggestion;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Soft-deleted (cancelled) suggestions are filtered out by the
 * `soft_deleteable` filter, like everything else.
 *
 * @extends ServiceEntityRepository<FriendSuggestion>
 */
class FriendSuggestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FriendSuggestion::class);
    }

    /**
     * Unexpired suggestions still awaiting A's answer.
     *
     * @return FriendSuggestion[]
     */
    public function findPendingFor(User $recipient, ?User $suggested = null): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.recipient = :recipient')
            ->andWhere('s.status = :pending')
            ->andWhere('s.expiresAt > :now')
            ->setParameter('recipient', $recipient->getId(), 'uuid')
            ->setParameter('pending', FriendSuggestionStatus::Pending)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('s.createdAt', 'ASC');
        if (null !== $suggested) {
            $qb->andWhere('s.suggested = :suggested')->setParameter('suggested', $suggested->getId(), 'uuid');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * What its author sees « en attente »: anything unexpired that hasn't
     * succeeded, whatever A did with it.
     *
     * @return FriendSuggestion[]
     */
    public function findOpenBy(User $author): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.suggestedBy = :author')
            ->andWhere('s.status != :accepted')
            ->andWhere('s.expiresAt > :now')
            ->setParameter('author', $author->getId(), 'uuid')
            ->setParameter('accepted', FriendSuggestionStatus::Accepted)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOpen(User $author, User $recipient, User $suggested): ?FriendSuggestion
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.suggestedBy = :author AND s.recipient = :recipient AND s.suggested = :suggested')
            ->andWhere('s.status != :accepted')
            ->andWhere('s.expiresAt > :now')
            ->setParameter('author', $author->getId(), 'uuid')
            ->setParameter('recipient', $recipient->getId(), 'uuid')
            ->setParameter('suggested', $suggested->getId(), 'uuid')
            ->setParameter('accepted', FriendSuggestionStatus::Accepted)
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return FriendSuggestion[]
     */
    public function findSentWith(Friendship $friendship): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.friendship = :friendship')
            ->andWhere('s.status = :sent')
            ->setParameter('friendship', $friendship->getId(), 'uuid')
            ->setParameter('sent', FriendSuggestionStatus::Sent)
            ->getQuery()
            ->getResult();
    }
}
