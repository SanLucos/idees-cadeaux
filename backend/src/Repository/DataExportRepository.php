<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DataExport;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DataExport>
 */
class DataExportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataExport::class);
    }

    /** An export of this subject still being built: asking again doesn't start another. */
    public function findPendingFor(User $subject): ?DataExport
    {
        return $this->findOneBy(['subject' => $subject, 'status' => DataExport::PENDING]);
    }

    /**
     * @return DataExport[] ready ones past their 48 h, and failed or stuck ones over a day old
     */
    public function findExpired(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('(e.expiresAt IS NOT NULL AND e.expiresAt < :now) OR (e.expiresAt IS NULL AND e.createdAt < :dayAgo)')
            ->setParameter('now', $now)
            ->setParameter('dayAgo', $now->modify('-1 day'))
            ->getQuery()
            ->getResult();
    }
}
