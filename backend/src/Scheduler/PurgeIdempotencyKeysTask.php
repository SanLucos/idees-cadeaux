<?php

declare(strict_types=1);

namespace App\Scheduler;

use Doctrine\DBAL\Connection;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/** Idempotency records only need to outlive an outbox retry window. */
#[AsPeriodicTask(frequency: '1 day')]
final class PurgeIdempotencyKeysTask
{
    public const string RETENTION = '48 hours';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(): int
    {
        return (int) $this->connection->executeStatement(
            "DELETE FROM idempotency_record WHERE created_at < NOW() - INTERVAL '".self::RETENTION."'",
        );
    }
}
