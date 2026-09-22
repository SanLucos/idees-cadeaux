<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\FriendshipStatus;
use App\Entity\Enum\UserType;
use App\Entity\Friendship;
use App\Entity\User;
use App\Scheduler\ExpireFriendRequestsTask;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExpireFriendRequestsTaskTest extends KernelTestCase
{
    public function testExpiresOnlyRequestsPastTheirThirtyDayDeadline(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $requester = new User(UserType::Regular, 'Requester');
        $requester->setEmail('expiry-requester@example.com');
        $fresh = new User(UserType::Regular, 'FreshAddressee');
        $fresh->setEmail('expiry-fresh@example.com');
        $stale = new User(UserType::Regular, 'StaleAddressee');
        $stale->setEmail('expiry-stale@example.com');
        $em->persist($requester);
        $em->persist($fresh);
        $em->persist($stale);

        $freshRequest = new Friendship($requester, $fresh);
        $staleRequest = new Friendship($requester, $stale);
        self::backdateExpiry($staleRequest, new \DateTimeImmutable('-1 day'));
        $em->persist($freshRequest);
        $em->persist($staleRequest);
        $em->flush();

        (self::getContainer()->get(ExpireFriendRequestsTask::class))();

        $em->refresh($freshRequest);
        $em->refresh($staleRequest);
        self::assertSame(FriendshipStatus::Pending, $freshRequest->getStatus());
        self::assertSame(FriendshipStatus::Expired, $staleRequest->getStatus());
    }

    private static function backdateExpiry(Friendship $friendship, \DateTimeImmutable $expiresAt): void
    {
        $property = new \ReflectionProperty(Friendship::class, 'expiresAt');
        $property->setAccessible(true);
        $property->setValue($friendship, $expiresAt);
    }
}
