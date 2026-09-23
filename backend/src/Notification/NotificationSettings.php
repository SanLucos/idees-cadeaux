<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Enum\NotificationChannel;
use App\Entity\Enum\NotificationType;
use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Repository\NotificationPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Whether `$user` gets `$type` on `$channel` (spec §5.11): the type's
 * default channels, overridden per type by the user, and — for push and
 * email — only once that channel was consented to ("sans consentement,
 * aucune notification non essentielle").
 */
final class NotificationSettings
{
    public function __construct(
        private readonly NotificationPreferenceRepository $preferences,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function isEnabled(User $user, NotificationType $type, NotificationChannel $channel): bool
    {
        $rows = $this->rowsFor($user);

        if ($channel->needsConsent() && !(($rows[$channel->value][''] ?? null)?->isEnabled() ?? false)) {
            return false;
        }

        return ($rows[$channel->value][$type->value] ?? null)?->isEnabled() ?? \in_array($channel, $type->defaultChannels(), true);
    }

    /** The per-type switch alone (default or override), consent aside. */
    public function isTypeEnabled(User $user, NotificationType $type, NotificationChannel $channel): bool
    {
        return ($this->rowsFor($user)[$channel->value][$type->value] ?? null)?->isEnabled() ?? \in_array($channel, $type->defaultChannels(), true);
    }

    public function consent(User $user, NotificationChannel $channel): ?NotificationPreference
    {
        return $this->rowsFor($user)[$channel->value][''] ?? null;
    }

    public function setConsent(User $user, NotificationChannel $channel, bool $granted): void
    {
        $this->set($user, $channel, null, $granted);
    }

    public function set(User $user, NotificationChannel $channel, ?NotificationType $type, bool $enabled): void
    {
        $row = $this->rowsFor($user)[$channel->value][$type?->value ?? ''] ?? null;
        if (null === $row) {
            $this->em->persist(new NotificationPreference($user, $channel, $type, $enabled));
        } else {
            $row->setEnabled($enabled);
        }
        $this->em->flush();
    }

    /**
     * @return array<string, array<string, NotificationPreference|null>> [channel][type or ''] (consent)
     */
    private function rowsFor(User $user): array
    {
        $rows = [];
        foreach ($this->preferences->findBy(['user' => $user]) as $row) {
            $rows[$row->getChannel()->value][$row->getType()?->value ?? ''] = $row;
        }

        return $rows;
    }
}
