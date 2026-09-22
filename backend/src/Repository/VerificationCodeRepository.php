<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\VerificationCodePurpose;
use App\Entity\User;
use App\Entity\VerificationCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VerificationCode>
 */
class VerificationCodeRepository extends ServiceEntityRepository
{
    public function __construct(private readonly EntityManagerInterface $em, ManagerRegistry $registry)
    {
        parent::__construct($registry, VerificationCode::class);
    }

    /**
     * @return VerificationCode[]
     */
    public function findActiveForUser(User $user, VerificationCodePurpose $purpose): array
    {
        return $this->findBy(['user' => $user, 'purpose' => $purpose]);
    }

    /**
     * Only one active code per (user, purpose): issuing a new one
     * invalidates whatever was pending before.
     */
    public function invalidateActiveFor(User $user, VerificationCodePurpose $purpose): void
    {
        foreach ($this->findActiveForUser($user, $purpose) as $code) {
            $this->em->remove($code);
        }
    }
}
