<?php

declare(strict_types=1);

namespace App\Message;

/** Email and push for one in-app notification, sent asynchronously. */
final class DeliverNotification
{
    public function __construct(public readonly string $notificationId)
    {
    }
}
