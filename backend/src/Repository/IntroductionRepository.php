<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\IntroductionAnswer;
use App\Entity\Introduction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Team introductions (spec §5.17, §11 décision 71). Soft-deleted rows
 * (cancelled or closed) are left out by the soft_deleteable filter.
 *
 * @extends ServiceEntityRepository<Introduction>
 */
class IntroductionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Introduction::class);
    }

    /** The open one between two people, either way round. */
    public function findOpenBetween(User $a, User $b): ?Introduction
    {
        return $this->open($this->between($a, $b))->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** Did either of them ever ignore an introduction to the other? */
    public function wasIgnoredBetween(User $a, User $b): bool
    {
        return (int) $this->between($a, $b)
            ->select('COUNT(i.id)')
            ->andWhere('i.answerA = :ignored OR i.answerB = :ignored')
            ->setParameter('ignored', IntroductionAnswer::Ignored)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Open introductions involving $user, answered by them or not, oldest first.
     *
     * @return Introduction[]
     */
    public function findOpenInvolving(User $user): array
    {
        return $this->open($this->createQueryBuilder('i')
            ->andWhere('i.userA = :user OR i.userB = :user')
            ->setParameter('user', $user->getId(), 'uuid'))
            ->orderBy('i.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * What $user still has to answer, oldest first.
     *
     * @return Introduction[]
     */
    public function findAwaiting(User $user): array
    {
        return array_values(array_filter(
            $this->findOpenInvolving($user),
            static fn (Introduction $i) => null === $i->answerOf($user),
        ));
    }

    private function between(User $a, User $b): QueryBuilder
    {
        return $this->createQueryBuilder('i')
            ->andWhere('(i.userA = :a AND i.userB = :b) OR (i.userA = :b AND i.userB = :a)')
            ->setParameter('a', $a->getId(), 'uuid')
            ->setParameter('b', $b->getId(), 'uuid');
    }

    private function open(QueryBuilder $qb): QueryBuilder
    {
        return $qb
            ->andWhere('i.friendship IS NULL')
            ->andWhere('i.expiresAt > :now')
            ->andWhere('(i.answerA IS NULL OR i.answerA <> :ignored) AND (i.answerB IS NULL OR i.answerB <> :ignored)')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('ignored', IntroductionAnswer::Ignored);
    }
}
