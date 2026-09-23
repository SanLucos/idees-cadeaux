<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Enum\IdeaArchiveKind;
use App\Entity\Idea;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Exception\HiddenResourceException;
use App\Repository\FriendshipRepository;

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
 */
final class IdeaAccess
{
    public function __construct(private readonly FriendshipRepository $friendships)
    {
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

    /**
     * Spec §5.4 "Archivage": `received` by the owner on their own idea;
     * `gifted` by a suggestion's author (lot 4 adds its reserver and
     * the contribution's initiator).
     */
    public function assertCanArchive(Idea $idea, User $viewer, IdeaArchiveKind $kind): void
    {
        $this->assertCanView($idea, $viewer);

        $allowed = match ($kind) {
            IdeaArchiveKind::Received => !$idea->isSuggestion() && $idea->getOwner() === $viewer,
            IdeaArchiveKind::Gifted => $idea->isSuggestion() && $idea->getAuthor() === $viewer,
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
