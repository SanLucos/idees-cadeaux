<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Contribution;
use App\Entity\Enum\NotificationType;
use App\Entity\Idea;
use App\Entity\User;

/**
 * Spec §5.11's idea-related rows, in one place. Every call passes the
 * idea's owner to Notifier's exclusions — on top of
 * NotificationRecipients filtering them out already — so a slip in one
 * layer still can't notify an owner about their own list (règle d'or).
 */
final class IdeaEvents
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly NotificationRecipients $recipients,
    ) {
    }

    /**
     * On publication (not on the draft's creation): a personal idea to
     * the owner's friends, a suggestion to the owner's friends except
     * the owner and its author. Once per idea, even if republished.
     */
    public function published(Idea $idea, User $actor): void
    {
        if (!$idea->isPublished()) {
            return;
        }

        $type = $idea->isSuggestion() ? NotificationType::SuggestionPublished : NotificationType::IdeaPublished;
        $this->notifier->notify(
            $type,
            $this->recipients->friendsSeeing($idea),
            Notifier::ideaPayload($idea, $actor),
            $actor,
            [$idea->getOwner(), $idea->getAuthor()],
            $type->value.':'.$idea->getId()->toRfc4122(),
        );
    }

    /**
     * Who to tell that an idea went private: computed *before* the
     * change, while they can still see it.
     *
     * @return User[]
     */
    public function beforeUnpublish(Idea $idea): array
    {
        return $idea->isPublished() ? $this->recipients->interactionGroup($idea) : [];
    }

    /**
     * @param User[] $recipients from beforeUnpublish()
     */
    public function unpublished(Idea $idea, array $recipients, User $actor): void
    {
        $this->notifier->notify(NotificationType::IdeaUnpublished, $recipients, Notifier::ideaPayload($idea, $actor), $actor, [$idea->getOwner()]);
    }

    /**
     * Reservation, comment, contribution, pledge, "offert": the idea's
     * interaction group minus the actor and the owner. Pass no actor
     * for events nobody performed (goal reached).
     *
     * @param array<string, mixed> $extra
     */
    public function interaction(NotificationType $type, Idea $idea, ?User $actor, array $extra = [], ?string $dedupeKey = null): void
    {
        $this->notifier->notify(
            $type,
            $this->recipients->interactionGroup($idea),
            Notifier::ideaPayload($idea, $actor) + $extra,
            $actor,
            [$idea->getOwner()],
            $dedupeKey,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function contributionPayload(Contribution $contribution): array
    {
        // Never an amount here (CLAUDE.md règle 3): just where to look.
        return ['contribution' => ['id' => $contribution->getId()->toRfc4122()]];
    }
}
