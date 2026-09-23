<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Idea;
use App\Entity\User;
use App\Repository\CommentRepository;
use App\Repository\ContributionPledgeRepository;
use App\Repository\ContributionRepository;
use App\Repository\FriendshipRepository;
use App\Repository\ReservationRepository;
use App\Security\IdeaAccess;

/**
 * Who a spec §5.11 event concerns, before Notifier's own exclusions.
 *
 * interactionGroup(): the people involved around an idea — its
 * suggestion's author, commenters, reserver, contribution initiators
 * and participants — kept only if they can still see the idea's
 * interactions (IdeaAccess::canSeeInteractions). That filter alone
 * removes the owner (règle d'or) and ex-friends; callers still pass the
 * owner to Notifier's `$exclude` as a second lock.
 *
 * Call it *before* a change that would hide the idea (unpublish).
 */
final class NotificationRecipients
{
    public function __construct(
        private readonly IdeaAccess $access,
        private readonly FriendshipRepository $friendships,
        private readonly ReservationRepository $reservations,
        private readonly CommentRepository $comments,
        private readonly ContributionRepository $contributions,
        private readonly ContributionPledgeRepository $pledges,
    ) {
    }

    /**
     * @return User[]
     */
    public function interactionGroup(Idea $idea): array
    {
        $people = [];
        $add = static function (User $user) use (&$people): void {
            $people[$user->getId()->toRfc4122()] = $user;
        };

        if ($idea->isSuggestion()) {
            $add($idea->getAuthor());
        }
        foreach ($this->comments->findForIdea($idea) as $comment) {
            $add($comment->getAuthor());
        }
        $key = $idea->getId()->toRfc4122();
        if (null !== $reservation = $this->reservations->findActiveByIdeas([$key])[$key] ?? null) {
            $add($reservation->getUser());
        }
        $contributions = $this->contributions->findByIdea($idea);
        foreach ($contributions as $contribution) {
            $add($contribution->getInitiator());
        }
        $pledges = $this->pledges->findByContributions(array_map(static fn ($c) => $c->getId()->toRfc4122(), $contributions));
        foreach (array_merge([], ...array_values($pledges)) as $pledge) {
            $add($pledge->getUser());
        }

        return array_values(array_filter($people, fn (User $u) => $this->access->canSeeInteractions($idea, $u)));
    }

    /**
     * Friends of `$owner` who can see this (published) idea — for "new
     * idea / new suggestion" (spec §5.11). Never includes the owner.
     *
     * @return User[]
     */
    public function friendsSeeing(Idea $idea): array
    {
        return array_values(array_filter(
            $this->friendsOf($idea->getOwner()),
            fn (User $u) => $u !== $idea->getOwner() && $this->access->canView($idea, $u),
        ));
    }

    /**
     * @return User[]
     */
    public function friendsOf(User $user): array
    {
        return array_map(static fn ($f) => $f->otherParty($user), $this->friendships->findAccepted($user));
    }
}
