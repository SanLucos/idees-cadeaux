<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\Enum\FriendshipStatus;
use App\Entity\Friendship;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Daily task (spec §5.3): a friend request nobody answered within 30
 * days becomes `expired`. Runs on the `scheduler_default` transport,
 * consumed by the `worker` container alongside `async`.
 */
#[AsPeriodicTask(frequency: '1 day')]
final class ExpireFriendRequestsTask
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(): void
    {
        $stale = $this->em->getRepository(Friendship::class)->createQueryBuilder('f')
            ->andWhere('f.status = :pending')
            ->andWhere('f.expiresAt < :now')
            ->setParameter('pending', FriendshipStatus::Pending)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();

        foreach ($stale as $friendship) {
            $friendship->expire();
        }

        $this->em->flush();
    }
}
