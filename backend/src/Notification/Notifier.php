<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Enum\NotificationChannel;
use App\Entity\Enum\NotificationType;
use App\Entity\Idea;
use App\Entity\Notification;
use App\Entity\User;
use App\Message\DeliverNotification;
use App\Repository\NotificationRepository;
use App\Security\IdeaAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The one way notifications are created (spec §5.11). Whatever the
 * caller passes, it enforces:
 *
 * - never the actor, nor anyone in `$exclude` (the idea's owner, always,
 *   for anything about their list — règle d'or);
 * - nothing to or about a suspended account (spec §5.13);
 * - a child's notification goes to its manager, marked « Pour X »
 *   (spec §5.15);
 * - one notification per event per person: a manager who is also a
 *   recipient themself gets it once, unmarked, and a manager of several
 *   recipient children once, for the first of them — family sharing
 *   makes a manager's friends their children's friends too (spec §11
 *   décision 63). `$dedupeKey` extends this across calls.
 *
 * In-app rows are created now; email and push follow asynchronously
 * (DeliverNotification), each only if enabled and consented.
 * Call it after flushing the change being notified.
 */
final class Notifier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationRepository $notifications,
        private readonly NotificationSettings $settings,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * @param iterable<User>       $recipients
     * @param array<string, mixed> $payload
     * @param User[]               $exclude
     *
     * @return Notification[] what was created
     */
    public function notify(NotificationType $type, iterable $recipients, array $payload, ?User $actor = null, array $exclude = [], ?string $dedupeKey = null): array
    {
        $excluded = [];
        foreach ([$actor, $actor ? IdeaAccess::humanBehind($actor) : null, ...$exclude] as $user) {
            if (null !== $user) {
                $excluded[$user->getId()->toRfc4122()] = true;
            }
        }

        $created = [];
        // A child's notifications go to each of its managers (spec §5.11, §11 décision 60),
        // but each person hears of an event once, preferably for themself (décision 63).
        $deliveries = [];
        foreach ($recipients as $recipient) {
            if (isset($excluded[$recipient->getId()->toRfc4122()]) || $recipient->isSuspended()) {
                continue;
            }
            foreach ($recipient->isManaged() ? $recipient->getManagers() : [$recipient] as $target) {
                $key = $target->getId()->toRfc4122();
                if (!isset($deliveries[$key]) || ($recipient === $target && null !== $deliveries[$key][1])) {
                    $deliveries[$key] = [$target, $recipient->isManaged() ? $recipient : null];
                }
            }
        }

        foreach ($deliveries as [$target, $subject]) {
            if (isset($excluded[$target->getId()->toRfc4122()]) || $target->isSuspended()) {
                continue;
            }
            if (null !== $dedupeKey && $this->notifications->existsForEvent($target, $dedupeKey)) {
                continue;
            }

            $enabled = array_filter(
                NotificationChannel::cases(),
                fn (NotificationChannel $c) => $this->settings->isEnabled($target, $type, $c),
            );
            if ([] === $enabled) {
                continue;
            }

            $notification = new Notification(
                $target,
                $type,
                $payload + (null !== $subject ? ['subject' => ['id' => $subject->getId()->toRfc4122(), 'displayName' => $subject->getDisplayName()]] : []),
                $subject,
                $dedupeKey,
                \in_array(NotificationChannel::InApp, $enabled, true),
            );
            $this->em->persist($notification);
            $created[] = $notification;
        }

        if ([] === $created) {
            return [];
        }

        $this->em->flush();
        foreach ($created as $notification) {
            $this->bus->dispatch(new DeliverNotification($notification->getId()->toRfc4122()));
        }

        return $created;
    }

    /**
     * Payload fragments shared by every idea-related notification: what
     * the recipient already sees in the app, nothing more.
     *
     * @return array<string, mixed>
     */
    public static function ideaPayload(Idea $idea, ?User $actor = null): array
    {
        return array_filter([
            // The one who acted: a child's name when its manager acted for it.
            'actor' => null !== $actor ? self::person($actor) : null,
            'idea' => ['id' => $idea->getId()->toRfc4122(), 'title' => $idea->getTitle()],
            'owner' => self::person($idea->getOwner()),
        ]);
    }

    /**
     * @return array{id: string, displayName: string|null}
     */
    public static function person(User $user): array
    {
        return ['id' => $user->getId()->toRfc4122(), 'displayName' => $user->getDisplayName()];
    }
}
