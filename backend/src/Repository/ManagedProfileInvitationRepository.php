<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ManagedProfileInvitation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ManagedProfileInvitation>
 */
class ManagedProfileInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ManagedProfileInvitation::class);
    }

    public function findForProfile(User $profile): ?ManagedProfileInvitation
    {
        return $this->findOneBy(['profile' => $profile], ['createdAt' => 'DESC']);
    }

    /**
     * @return ManagedProfileInvitation[]
     */
    public function findByEmail(string $email): array
    {
        return $this->findBy(['email' => $email], ['createdAt' => 'DESC']);
    }

    public function deleteForProfile(User $profile): void
    {
        $this->createQueryBuilder('i')
            ->delete()
            ->where('i.profile = :profile')
            ->setParameter('profile', $profile->getId(), 'uuid')
            ->getQuery()
            ->execute();
    }
}
