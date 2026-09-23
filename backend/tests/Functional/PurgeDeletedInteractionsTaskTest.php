<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Comment;
use App\Entity\Contribution;
use App\Entity\ContributionPledge;
use App\Entity\Enum\UserType;
use App\Entity\Idea;
use App\Entity\Reaction;
use App\Entity\Reservation;
use App\Entity\User;
use App\Scheduler\PurgeDeletedInteractionsTask;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PurgeDeletedInteractionsTaskTest extends KernelTestCase
{
    public function testErasesOnlyInteractionsSoftDeletedMoreThanThirtyDaysAgo(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(UserType::Regular, 'Owner');
        $owner->setEmail('purge-owner@example.com');
        $friend = new User(UserType::Regular, 'Friend');
        $friend->setEmail('purge-friend@example.com');
        $idea = new Idea($owner, $owner, 'Idea');
        $contribution = new Contribution($idea, $friend, '10.00', 'EUR');

        $old = [
            new Reservation($idea, $friend),
            new Comment($idea, $friend, 'old'),
            new Reaction($idea, $friend),
            $contribution,
            new ContributionPledge($contribution, $friend, '5.00'),
        ];
        $recent = new Comment($idea, $friend, 'recently deleted');
        $alive = new Comment($idea, $friend, 'alive');

        foreach ([$owner, $friend, $idea, ...$old, $recent, $alive] as $entity) {
            $em->persist($entity);
        }
        foreach ($old as $entity) {
            $entity->markDeleted(new \DateTimeImmutable('-31 days'));
        }
        $recent->markDeleted(new \DateTimeImmutable('-29 days'));
        $em->flush();

        $purged = (self::getContainer()->get(PurgeDeletedInteractionsTask::class))();

        self::assertSame(5, $purged);
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(
            ['alive', 'recently deleted'],
            $connection->fetchFirstColumn('SELECT body FROM comment ORDER BY body'),
            'soft-delete filter aside: the row is physically gone, the others stay',
        );
        foreach (['reservation', 'reaction', 'contribution', 'contribution_pledge'] as $table) {
            self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM {$table}"), $table);
        }
    }
}
