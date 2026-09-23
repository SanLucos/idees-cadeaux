<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Enum\IdeaArchiveKind;
use App\Entity\Idea;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Exception\HiddenResourceException;
use App\Repository\ContributionRepository;
use App\Repository\FriendshipRepository;
use App\Repository\ReservationRepository;

/**
 * Every "who may see / change this idea" rule (spec §4, §5.4), in one
 * place so the list queries (App\Repository\IdeaRepository), the item
 * endpoints and the tests all agree.
 *
 * Seeing an idea:
 * - its **author** always does, except a published suggestion once
 *   they're no longer the owner's friend (spec §5.3: "leur auteur n'a
 *   plus accès à la liste") — their private drafts stay theirs;
 * - its **owner** only when they also wrote it: a suggestion is never
 *   visible to its owner (CLAUDE.md règle 1);
 * - an accepted **friend** of the owner, once it's published.
 * Anyone else: not found — 404, never 403.
 *
 * Changing it is the author's alone. A visible-but-not-yours idea
 * (a friend's personal idea) is a plain 403: its existence is no secret
 * to someone who can already read it.
 *
 * Interactions (reservation, contribution and pledges, comments,
 * reactions — spec §5.7–5.10) exist for whoever sees the idea except
 * its owner, and only on a published idea. For the owner every
 * interaction endpoint is a 404, exactly like a non-existent one.
 */
final class IdeaAccess
{
    public function __construct(
        private readonly FriendshipRepository $friendships,
        private readonly ReservationRepository $reservations,
        private readonly ContributionRepository $contributions,
    ) {
    }

    public function canView(Idea $idea, User $viewer): bool
    {
        $owner = $idea->getOwner();

        if ($idea->getAuthor() === $viewer) {
            return $owner === $viewer || !$idea->isPublished() || $this->friendships->areFriends($viewer, $owner);
        }

        if ($owner === $viewer) {
            return false;
        }

        return $idea->isPublished() && $this->friendships->areFriends($viewer, $owner);
    }

    /**
     * @throws HiddenResourceException when the viewer may not even know it exists
     */
    public function assertCanView(Idea $idea, User $viewer): void
    {
        if (!$this->canView($idea, $viewer)) {
            throw new HiddenResourceException();
        }
    }

    public function assertCanEdit(Idea $idea, User $viewer): void
    {
        $this->assertCanView($idea, $viewer);

        if ($idea->getAuthor() !== $viewer) {
            throw new ApiProblemException('idea.forbidden', 'Only the author can change this idea.', 403);
        }
    }

    public function canSeeInteractions(Idea $idea, User $viewer): bool
    {
        return $idea->getOwner() !== $viewer && $idea->isPublished() && $this->canView($idea, $viewer);
    }

    /**
     * @throws HiddenResourceException for the owner and anyone who can't see the idea
     */
    public function assertCanSeeInteractions(Idea $idea, User $viewer): void
    {
        if ($idea->getOwner() === $viewer || !$this->canView($idea, $viewer)) {
            throw new HiddenResourceException();
        }
        if (!$idea->isPublished()) {
            // Only the draft's author gets here: nothing to hide from them.
            throw new ApiProblemException('idea.not_published', 'A private idea has no interactions.', 422);
        }
    }

    /** Spec §5.4: an archived idea takes no new reservation, comment or pledge. */
    public function assertCanInteract(Idea $idea, User $viewer): void
    {
        $this->assertCanSeeInteractions($idea, $viewer);
        if ($idea->isArchived()) {
            throw new ApiProblemException('idea.archived', 'This idea is archived.', 422);
        }
    }

    /**
     * Spec §5.4: a suggestion is marked "offert" by its author, its
     * reserver, or — when it's in an open contribution — the initiator.
     */
    public function canMarkGifted(Idea $idea, User $viewer): bool
    {
        if (!$idea->isSuggestion() || $idea->isArchived() || !$this->canView($idea, $viewer) || $idea->getOwner() === $viewer) {
            return false;
        }
        if ($idea->getAuthor() === $viewer) {
            return true;
        }
        if (!$idea->isPublished()) {
            return false;
        }

        $id = $idea->getId()->toRfc4122();
        $reservation = $this->reservations->findActiveByIdeas([$id])[$id] ?? null;
        if ($reservation?->getUser() === $viewer) {
            return true;
        }

        return $this->contributions->findOpenForIdea($idea)?->getInitiator() === $viewer;
    }

    /**
     * Spec §5.4 "Archivage": `received` by the owner on their own idea;
     * `gifted` on a suggestion, see canMarkGifted().
     */
    public function assertCanArchive(Idea $idea, User $viewer, IdeaArchiveKind $kind): void
    {
        $this->assertCanView($idea, $viewer);

        $allowed = match ($kind) {
            IdeaArchiveKind::Received => !$idea->isSuggestion() && $idea->getOwner() === $viewer,
            IdeaArchiveKind::Gifted => $idea->isArchived() ? $idea->isSuggestion() : $this->canMarkGifted($idea, $viewer),
        };

        if (!$allowed) {
            throw new ApiProblemException('idea.archive_forbidden', 'You cannot archive this idea this way.', 403);
        }
    }

    /** Spec §5.4: "seul celui qui a archivé peut annuler l'archivage". */
    public function assertCanUnarchive(Idea $idea, User $viewer): void
    {
        $this->assertCanView($idea, $viewer);

        if ($idea->getArchivedBy() !== $viewer) {
            throw new ApiProblemException('idea.unarchive_forbidden', 'Only whoever archived this idea can restore it.', 403);
        }
    }

    /**
     * Whether `$viewer` looks at `$owner`'s list as its owner (vue
     * propriétaire: own ideas only, never a suggestion) or as a friend.
     * Null: neither — the list doesn't exist for them.
     */
    public function listViewFor(User $owner, User $viewer): ?string
    {
        if ($owner === $viewer) {
            return 'owner';
        }

        return $this->friendships->areFriends($viewer, $owner) ? 'friend' : null;
    }
}
