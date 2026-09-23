<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contribution;
use App\Entity\Idea;
use App\Repository\CommentRepository;
use App\Repository\ContributionPledgeRepository;
use App\Repository\ContributionRepository;
use App\Repository\ReactionRepository;
use App\Repository\ReservationRepository;

/**
 * Spec §5.4 "repasser en privé": reservation, comments, reactions,
 * contribution and pledges are deleted for good. Soft-deleted here so
 * the deletion propagates to devices as tombstones (spec §8); nothing
 * can bring them back. Notifying whoever had interacted is lot 5.
 * The caller flushes.
 */
final class IdeaInteractionPurger
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly CommentRepository $comments,
        private readonly ReactionRepository $reactions,
        private readonly ContributionRepository $contributions,
        private readonly ContributionPledgeRepository $pledges,
    ) {
    }

    public function purge(Idea $idea): void
    {
        $id = $idea->getId()->toRfc4122();
        $contributions = $this->contributions->findByIdea($idea);

        $doomed = [
            ...array_values($this->reservations->findActiveByIdeas([$id])),
            ...$this->comments->findForIdea($idea),
            ...$this->reactions->findBy(['idea' => $idea]),
            ...$contributions,
            ...array_merge([], ...array_values($this->pledges->findByContributions(
                array_map(static fn (Contribution $c) => $c->getId()->toRfc4122(), $contributions),
            ))),
        ];

        foreach ($doomed as $entity) {
            $entity->markDeleted();
        }
    }
}
