<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Service\AccountDeletion;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Daily task (spec §5.13/§5.15): accounts and child profiles whose
 * 14-day grace period is over are erased for good — see
 * App\Service\AccountDeletion::erase().
 */
#[AsPeriodicTask(frequency: '1 day')]
final class DeleteScheduledProfilesTask
{
    public function __construct(private readonly AccountDeletion $deletion)
    {
    }

    public function __invoke(): int
    {
        return $this->deletion->eraseDue();
    }
}
