<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';
    case Push = 'push';

    /**
     * Spec §5.11 / §11 décision 28: push and email need a timestamped
     * consent; the in-app centre is always on (still tunable per type).
     */
    public function needsConsent(): bool
    {
        return self::InApp !== $this;
    }
}
