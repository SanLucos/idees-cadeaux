<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\FriendshipStatus;
use App\Entity\Enum\NotificationType;
use App\Entity\Friendship;
use App\Entity\User;
use App\Notification\FriendshipEvents;
use App\Notification\FriendSuggestionOutcomes;
use App\Repository\FriendshipRepository;
use App\Security\ActingContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sending a friend request (spec §5.3), whether by email, from a
 * contacts match or from a friend's suggestion (§11 décision 65).
 * Every refusal is silent: the caller answers the same way whatever
 * happened here.
 */
final class FriendRequests
{
    public function __construct(
        private readonly FriendshipRepository $friendships,
        private readonly EntityManagerInterface $em,
        private readonly ActingContext $acting,
        private readonly FriendshipEvents $events,
        private readonly FriendSuggestionOutcomes $outcomes,
    ) {
    }

    /**
     * Whether a new request from $me would reach $target: nobody can ask
     * a managed profile (spec §5.15) or an account being deleted
     * (§5.13); one active request per pair; 30 days between two
     * requests in the same direction, unless the last one ended in a
     * removal.
     */
    public function canRequest(User $me, User $target): bool
    {
        if ($target === $me || $target->isManaged() || $target->isSuspended()) {
            return false;
        }
        if (null !== $this->friendships->findActiveBetween($me, $target)) {
            return false;
        }

        $latest = $this->friendships->findLatestForDirection($me, $target);
        if (null === $latest) {
            return true;
        }
        $exemptFromCooldown = FriendshipStatus::Accepted === $latest->getStatus() && $latest->isDeleted();

        return $exemptFromCooldown || $latest->getCreatedAt()->modify('+30 days') <= new \DateTimeImmutable();
    }

    /**
     * @return Friendship|null the request created, or the friendship it
     *                         resolved into; null when nothing happened
     */
    public function send(User $me, ?User $target, ?User $suggestedBy = null): ?Friendship
    {
        if (null === $target || $target === $me || $target->isManaged() || $target->isSuspended()) {
            return null;
        }

        $active = $this->friendships->findActiveBetween($me, $target);
        if (null !== $active) {
            if (FriendshipStatus::Pending === $active->getStatus() && $active->getRequester() === $target) {
                // They'd already asked us: this mutual interest becomes an
                // immediate friendship instead of a second, redundant request.
                $active->accept();
                $this->em->flush();
                $this->events->notify(NotificationType::FriendRequestAccepted, $active, $me);
                $this->outcomes->friendshipAccepted($active);

                return $active;
            }

            // Otherwise: already friends, or we already asked them — no-op.
            return null;
        }

        if (!$this->canRequest($me, $target)) {
            return null;
        }

        $request = new Friendship($me, $target);
        if ($me->isManaged()) {
            // "au nom de [enfant]" (spec §5.15).
            $request->setOnBehalfOfManager($this->acting->human());
        }
        $request->setSuggestedBy($suggestedBy);
        $this->em->persist($request);
        $this->em->flush();
        $this->events->notify(NotificationType::FriendRequestReceived, $request, $me);

        return $request;
    }
}
