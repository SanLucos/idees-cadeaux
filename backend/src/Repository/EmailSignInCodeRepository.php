<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailSignInCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailSignInCode>
 */
class EmailSignInCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailSignInCode::class);
    }

    /**
     * @return EmailSignInCode[]
     */
    public function findForEmail(string $email): array
    {
        return $this->findBy(['email' => $email]);
    }
}
