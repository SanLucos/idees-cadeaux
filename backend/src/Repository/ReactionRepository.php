<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\ReactionType;
use App\Entity\Idea;
use App\Entity\Reaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reaction>
 */
class ReactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reaction::class);
    }

    public function findOneFor(Idea $idea, User $user, ReactionType $type = ReactionType::Like): ?Reaction
    {
        return $this->findOneBy(['idea' => $idea, 'user' => $user, 'type' => $type]);
    }

    /**
     * @param string[] $ideaIds
     *
     * @return array<string, array{count: int, mine: bool}>
     */
    public function summarizeByIdeas(array $ideaIds, User $viewer): array
    {
        if ([] === $ideaIds) {
            return [];
        }

        $summary = [];
        foreach ($this->createQueryBuilder('r')
            ->select('IDENTITY(r.idea) AS ideaId, COUNT(r.id) AS total, SUM(CASE WHEN r.user = :viewer THEN 1 ELSE 0 END) AS mine')
            ->andWhere('r.idea IN (:ideas)')->setParameter('ideas', $ideaIds)
            ->setParameter('viewer', $viewer->getId(), 'uuid')
            ->groupBy('r.idea')
            ->getQuery()->getArrayResult() as $row) {
            $summary[(string) $row['ideaId']] = ['count' => (int) $row['total'], 'mine' => (int) $row['mine'] > 0];
        }

        return $summary;
    }

    /**
     * @return Reaction[]
     */
    public function findByUserOnOwnersIdeas(User $user, User $owner): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.idea', 'i')
            ->andWhere('r.user = :user AND i.owner = :owner')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->getQuery()->getResult();
    }
}
