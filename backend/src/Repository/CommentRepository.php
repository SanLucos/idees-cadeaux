<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Idea;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Comment>
 */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /**
     * @return Comment[] oldest first (flat thread, spec §5.8)
     */
    public function findForIdea(Idea $idea): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('a')->join('c.author', 'a')
            ->andWhere('c.idea = :idea')->setParameter('idea', $idea->getId(), 'uuid')
            ->orderBy('c.createdAt', 'ASC')->addOrderBy('c.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * @param string[] $ideaIds
     *
     * @return array<string, int>
     */
    public function countByIdeas(array $ideaIds): array
    {
        if ([] === $ideaIds) {
            return [];
        }

        $counts = [];
        foreach ($this->createQueryBuilder('c')
            ->select('IDENTITY(c.idea) AS ideaId, COUNT(c.id) AS total')
            ->andWhere('c.idea IN (:ideas)')->setParameter('ideas', $ideaIds)
            ->groupBy('c.idea')
            ->getQuery()->getArrayResult() as $row) {
            $counts[(string) $row['ideaId']] = (int) $row['total'];
        }

        return $counts;
    }
}
