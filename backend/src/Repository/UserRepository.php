<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * The child profiles `$manager` manages, alone or with a second
     * manager (spec §5.15, §11 décision 60), oldest first.
     *
     * @return list<User>
     */
    public function findChildrenOf(User $manager): array
    {
        /** @var list<User> */
        return $this->createQueryBuilder('u')
            ->andWhere('u.managedBy = :manager OR u.coManagedBy = :manager')
            ->setParameter('manager', $manager->getId(), 'uuid')
            ->orderBy('u.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
