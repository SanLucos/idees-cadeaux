<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
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
 */
final class FriendRemovalEffects
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ContributionPledgeRepository $pledges,
        private readonly ReactionRepository $reactions,
        private readonly ContributionRepository $contributions,
    ) {
    }

    public function apply(User $a, User $b): void
    {
        foreach ([[$a, $b], [$b, $a]] as [$actor, $owner]) {
            foreach ($this->reservations->findByUserOnOwnersIdeas($actor, $owner) as $reservation) {
                $reservation->markDeleted();
            }
            foreach ($this->pledges->findByUserOnOwnersIdeas($actor, $owner) as $pledge) {
                $pledge->markDeleted();
            }
            foreach ($this->reactions->findByUserOnOwnersIdeas($actor, $owner) as $reaction) {
                $reaction->markDeleted();
            }
            foreach ($this->contributions->findOpenByInitiatorOnOwnersIdeas($actor, $owner) as $contribution) {
                $contribution->close();
            }
        }
    }
}
