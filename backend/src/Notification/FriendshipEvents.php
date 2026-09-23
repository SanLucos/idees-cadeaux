<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Enum\NotificationType;
use App\Entity\Friendship;
use App\Entity\User;

/**
 * Spec §5.11: a request received (to its addressee), a request accepted
 * (to whoever sent it). Never a decline (silent, règle 4), never a
 * removal (silent, spec §5.3).
 */
final class FriendshipEvents
{
    public function __construct(private readonly Notifier $notifier)
    {
    }

    public function notify(NotificationType $type, Friendship $friendship, User $actor): void
    {
        $recipient = $friendship->otherParty($actor);

        $person = Notifier::person($actor);
        if ($actor->isManaged()) {
            // « profil géré par X » (spec §5.15).
            $person['managedBy'] = $actor->getManagedBy()?->getDisplayName();
        }

        $this->notifier->notify($type, [$recipient], [
            'actor' => $person,
            'friendship' => ['id' => $friendship->getId()->toRfc4122()],
        ], $actor);
    }
}
