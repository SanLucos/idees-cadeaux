<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contribution;
use App\Entity\Enum\ContributionStatus;
use App\Entity\Idea;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contribution>
 */
class ContributionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contribution::class);
    }

    public function findOpenForIdea(Idea $idea): ?Contribution
    {
        return $this->findOneBy(['idea' => $idea, 'status' => ContributionStatus::Open]);
    }

    /**
     * The contribution to show for each idea: the open one, else the
     * most recently closed one (history, e.g. on an archived idea).
     *
     * @param string[] $ideaIds
     *
     * @return array<string, Contribution> keyed by idea id
     */
    public function findCurrentByIdeas(array $ideaIds): array
    {
        if ([] === $ideaIds) {
            return [];
        }

        $byIdea = [];
        /** @var Contribution $contribution */
        foreach ($this->createQueryBuilder('c')
            ->addSelect('u')->join('c.initiator', 'u')
            ->andWhere('c.idea IN (:ideas)')->setParameter('ideas', $ideaIds)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()->getResult() as $contribution) {
            $key = $contribution->getIdea()->getId()->toRfc4122();
            $current = $byIdea[$key] ?? null;
            if (null === $current || !$current->isOpen()) {
                $byIdea[$key] = $contribution;
            }
        }

        return $byIdea;
    }

    /**
     * Open contributions `$initiator` started on ideas owned by `$owner`.
     *
     * @return Contribution[]
     */
    public function findOpenByInitiatorOnOwnersIdeas(User $initiator, User $owner): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.idea', 'i')
            ->andWhere('c.initiator = :initiator AND i.owner = :owner AND c.status = :open')
            ->setParameter('initiator', $initiator->getId(), 'uuid')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->setParameter('open', ContributionStatus::Open)
            ->getQuery()->getResult();
    }

    /**
     * @return Contribution[]
     */
    public function findByIdea(Idea $idea): array
    {
        return $this->findBy(['idea' => $idea]);
    }
}
