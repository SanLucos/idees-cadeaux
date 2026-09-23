<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Entity\Enum\UserType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Daily task (spec §5.15/§5.13): managed profiles whose 14-day grace
 * period is over are deleted for good. The database cascades take
 * everything attached (its ideas and what friends put on them, its
 * sizes, preferences, friendships, invitations). Lot 8 extends this to
 * adult accounts, with the stored images, the information email and
 * the sync tombstones spec §5.13 asks for.
 */
#[AsPeriodicTask(frequency: '1 day')]
final class DeleteScheduledProfilesTask
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM app_user WHERE type = :managed AND deletion_scheduled_at IS NOT NULL AND deletion_scheduled_at < :now',
            ['managed' => UserType::Managed->value, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
    }
}
