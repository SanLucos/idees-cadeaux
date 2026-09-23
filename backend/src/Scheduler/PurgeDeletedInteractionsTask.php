<?php

declare(strict_types=1);

namespace App\Scheduler;

use Doctrine\DBAL\Connection;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Daily task (spec §11 décision 22): interactions soft-deleted more
 * than 30 days ago — unpublished ideas' reservations, comments, likes,
 * contributions and pledges, cancelled actions, friend-removal effects —
 * are erased for good. The 30-day window leaves time for tombstones to
 * reach devices (spec §8) and matches the backup rotation (§5.13).
 * Lot 6's /sync must send a full resync to a cursor older than that.
 */
#[AsPeriodicTask(frequency: '1 day')]
final class PurgeDeletedInteractionsTask
{
    public const string RETENTION = '30 days';

    /** Children before parents: pledges reference contributions. */
    private const array TABLES = ['contribution_pledge', 'contribution', 'reservation', 'comment', 'reaction'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(): int
    {
        $cutoff = (new \DateTimeImmutable('-'.self::RETENTION))->format('Y-m-d H:i:s');

        $purged = 0;
        foreach (self::TABLES as $table) {
            $purged += (int) $this->connection->executeStatement(
                "DELETE FROM {$table} WHERE deleted_at IS NOT NULL AND deleted_at < :cutoff",
                ['cutoff' => $cutoff],
            );
        }

        return $purged;
    }
}
