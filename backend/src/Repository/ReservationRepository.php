<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    /**
     * @param string[] $ideaIds
     *
     * @return array<string, Reservation> keyed by idea id
     */
    public function findActiveByIdeas(array $ideaIds): array
    {
        if ([] === $ideaIds) {
            return [];
        }

        $byIdea = [];
        foreach ($this->createQueryBuilder('r')
            ->addSelect('u')->join('r.user', 'u')
            ->andWhere('r.idea IN (:ideas)')->setParameter('ideas', $ideaIds)
            ->getQuery()->getResult() as $reservation) {
            $byIdea[$reservation->getIdea()->getId()->toRfc4122()] = $reservation;
        }

        return $byIdea;
    }

    /**
     * `$user`'s reservations on ideas owned by `$owner` (friend removal, spec §5.3).
     *
     * @return Reservation[]
     */
    public function findByUserOnOwnersIdeas(User $user, User $owner): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.idea', 'i')
            ->andWhere('r.user = :user AND i.owner = :owner')
            ->setParameter('user', $user->getId(), 'uuid')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->getQuery()->getResult();
    }
}
