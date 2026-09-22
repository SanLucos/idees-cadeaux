<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProfilePreference;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProfilePreference>
 */
class ProfilePreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProfilePreference::class);
    }
}
