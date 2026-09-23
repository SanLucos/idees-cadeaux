<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Contribution;
use App\Entity\ContributionPledge;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContributionPledge>
 */
class ContributionPledgeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContributionPledge::class);
    }

    public function findOneFor(Contribution $contribution, User $user): ?ContributionPledge
    {
        return $this->findOneBy(['contribution' => $contribution, 'user' => $user]);
    }

    /**
     * @param string[] $contributionIds
     *
     * @return array<string, ContributionPledge[]> keyed by contribution id, oldest first
     */
    public function findByContributions(array $contributionIds): array
    {
        if ([] === $contributionIds) {
            return [];
        }

        $grouped = [];
        foreach ($this->createQueryBuilder('p')
            ->addSelect('u')->join('p.user', 'u')
            ->andWhere('p.contribution IN (:contributions)')->setParameter('contributions', $contributionIds)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()->getResult() as $pledge) {
            $grouped[$pledge->getContribution()->getId()->toRfc4122()][] = $pledge;
        }

        return $grouped;
    }

    /**
     * @return ContributionPledge[]
     */
    public function findByUserOnOwnersIdeas(User $user, User $owner): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.contribution', 'c')
            ->join('c.idea', 'i')
            ->andWhere('p.user = :user AND i.owner = :owner')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->getQuery()->getResult();
    }
}
