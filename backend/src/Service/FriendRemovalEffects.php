<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Idea;
use App\Entity\User;
use App\Repository\FriendshipRepository;
use App\Repository\ContributionPledgeRepository;
use App\Repository\ContributionRepository;
use App\Repository\ReactionRepository;
use App\Repository\ReservationRepository;

/**
 * Spec §5.3 "retirer un ami", both directions, silently:
 * - actions cancelled: reservations, pledges (totals are computed, so
 *   they follow) and reactions each made on the other's ideas;
 * - a contribution one of them initiated on the other's ideas is
 *   closed, the other participants' pledges kept;
 * - suggestions and comments stay (they are content, not actions).
 *
 * On an idea for several people (décision 69), only when the removal
 * takes away all access: still a friend (or the manager) of another
 * recipient, one keeps their actions there.
 */
final class FriendRemovalEffects
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ContributionPledgeRepository $pledges,
        private readonly ReactionRepository $reactions,
        private readonly ContributionRepository $contributions,
        private readonly FriendshipRepository $friendships,
    ) {
    }

    /** Called once the friendship between `$a` and `$b` is ended (flushed or not). */
    public function apply(User $a, User $b): void
    {
        foreach ([[$a, $b], [$b, $a]] as [$actor, $owner]) {
            $losesAccess = fn (Idea $idea): bool => !$this->keepsAccess($idea, $actor, $owner);

            foreach ($this->reservations->findByUserOnOwnersIdeas($actor, $owner) as $reservation) {
                if ($losesAccess($reservation->getIdea())) {
                    $reservation->markDeleted();
                }
            }
            foreach ($this->pledges->findByUserOnOwnersIdeas($actor, $owner) as $pledge) {
                if ($losesAccess($pledge->getContribution()->getIdea())) {
                    $pledge->markDeleted();
                }
            }
            foreach ($this->reactions->findByUserOnOwnersIdeas($actor, $owner) as $reaction) {
                if ($losesAccess($reaction->getIdea())) {
                    $reaction->markDeleted();
                }
            }
            foreach ($this->contributions->findOpenByInitiatorOnOwnersIdeas($actor, $owner) as $contribution) {
                if ($losesAccess($contribution->getIdea())) {
                    $contribution->close();
                }
            }
        }
    }

    /** Whether `$actor` still sees the idea through a recipient other than `$removed`. */
    private function keepsAccess(Idea $idea, User $actor, User $removed): bool
    {
        foreach ($idea->getRecipients() as $recipient) {
            if ($recipient === $removed || $recipient === $actor) {
                continue;
            }
            if ($recipient->isManagedBy($actor) || $this->friendships->areFriends($actor, $recipient)) {
                return true;
            }
        }

        return false;
    }
}
