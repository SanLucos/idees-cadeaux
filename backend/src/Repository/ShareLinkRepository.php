<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ShareLink;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShareLink>
 */
class ShareLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShareLink::class);
    }

    /** The owner's current link, suspended or not (soft-deleted ones are filtered out). */
    public function findForOwner(User $owner): ?ShareLink
    {
        return $this->findOneBy(['owner' => $owner]);
    }

    /**
     * The live link behind a token, or null — whatever the reason
     * (malformed, unknown, regenerated, disabled). Looked up by hash,
     * confirmed in constant time (spec §5.16 "Sécurité").
     */
    public function findByToken(string $token): ?ShareLink
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]{22}$/', $token)) {
            return null;
        }

        $link = $this->findOneBy(['tokenHash' => ShareLink::hash($token)]);

        return null !== $link && $link->matches($token) ? $link : null;
    }
}
