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
 * Every "who may see / change this idea" rule (spec §4, §5.4, §11
 * décision 69), in one place so the list queries
 * (App\Repository\IdeaRepository), the item endpoints, /sync, the
 * notifications and the tests all agree.
 *
 * An idea has one or more recipients, each of them its owner. The view
 * `$viewer` gets of it (viewOf()), with `human` the adult behind the
 * request (the manager when acting as a managed profile):
 * 1. `human` is a recipient → **owner** view of a personal idea
 *    (published, or their own draft); nothing of a suggestion, ever
 *    (CLAUDE.md règle 1) — whatever else they are to the other
 *    recipients (friend, manager);
 * 2. a recipient is a profile `human` manages → **manager** view (spec
 *    §5.15, the one exception to the règle d'or): published ideas, the
 *    child's drafts and `human`'s own; never a friend's draft (règle 2);
 * 3. `$viewer` wrote it → **friend** view: their draft, or their
 *    published suggestion while they're still friends with (or manager
 *    of) a recipient (spec §5.3);
 * 4. published, and `$viewer` is a friend of at least one recipient →
 *    **friend** view.
 * Anyone else: not found — 404, never 403.
 *
 * Changing a personal idea's fields, deleting it or marking it
 * "reçue" is any recipient's right; publishing, unpublishing and
 * choosing the recipients is its author's alone. A suggestion is its
 * author's. A visible-but-not-yours idea is a plain 403: its existence
 * is no secret to someone who can already read it.
 *
 * Interactions (reservation, contribution and pledges, comments,
 * reactions — spec §5.7–5.10) exist for whoever has the friend or
 * manager view, on a published idea, and never for a recipient: for
 * them every interaction endpoint is a 404, exactly like a non-existent
 * one.
 */
final class IdeaAccess
{
    public function __construct(
        private readonly FriendshipRepository $friendships,
        private readonly ReservationRepository $reservations,
        private readonly ContributionRepository $contributions,
    ) {
    }

    /**
     * Whether `$viewer` reads `$owner`'s list as its manager: the manager
     * themselves, or the managed profile when acting as it (a managed
     * profile never has a session of its own).
     */
    public static function readsAsManager(User $owner, User $viewer): bool
    {
        return $owner->isManaged() && ($viewer === $owner || $owner->isManagedBy($viewer));
    }

    /** The adult behind a request: the manager when acting as a managed profile. */
    public static function humanBehind(User $viewer): User
    {
        return $viewer->isManaged() ? ($viewer->getActingManager() ?? $viewer) : $viewer;
    }

    /**
     * Whether `$viewer` stands on the owner side of the idea: the adult
     * behind the request is one of its recipients.
     */
    public static function isOwnerSide(Idea $idea, User $viewer): bool
    {
        return $idea->hasRecipient(self::humanBehind($viewer));
    }

    /**
     * The recipient `$viewer` reads the idea for as its manager, if any.
     */
    public static function managedRecipient(Idea $idea, User $viewer): ?User
    {
        $human = self::humanBehind($viewer);
        foreach ($idea->getRecipients() as $recipient) {
            if ($recipient->isManaged() && $recipient->isManagedBy($human)) {
                return $recipient;
            }
        }

        return null;
    }

    /**
     * 'owner', 'manager', 'friend' or null (the idea doesn't exist for
     * `$viewer`). See the class comment.
     */
    public function viewOf(Idea $idea, User $viewer): ?string
    {
        $human = self::humanBehind($viewer);
        $author = $idea->getAuthor();
        $isOwnDraft = $author === $viewer || $author === $human || ($author->isManaged() && $author->isManagedBy($human));

        if ($idea->hasRecipient($human)) {
            return !$idea->isSuggestion() && ($idea->isPublished() || $isOwnDraft) ? 'owner' : null;
        }

        if (null !== self::managedRecipient($idea, $viewer)) {
            return $idea->isPublished() || $isOwnDraft ? 'manager' : null;
        }

        if ($author === $viewer) {
            return !$idea->isPublished() || $this->isFriendOfARecipient($idea, $viewer) ? 'friend' : null;
        }

        return $idea->isPublished() && $this->isFriendOfARecipient($idea, $viewer) ? 'friend' : null;
    }

    public function canView(Idea $idea, User $viewer): bool
    {
        return null !== $this->viewOf($idea, $viewer);
    }

    private function isFriendOfARecipient(Idea $idea, User $viewer): bool
    {
        foreach ($idea->getRecipients() as $recipient) {
            if ($recipient !== $viewer && $this->friendships->areFriends($viewer, $recipient)) {
                return true;
            }
        }

        return false;
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

    /**
     * Fields, image, deletion: any recipient of a personal idea (a
     * managed one while acting as it), the author of a suggestion.
     */
    public function canEdit(Idea $idea, User $viewer): bool
    {
        if (!$this->canView($idea, $viewer)) {
            return false;
        }

        return $idea->isSuggestion() ? $idea->getAuthor() === $viewer : $idea->hasRecipient($viewer);
    }

    public function assertCanEdit(Idea $idea, User $viewer): void
    {
        $this->assertCanView($idea, $viewer);

        if (!$this->canEdit($idea, $viewer)) {
            throw new ApiProblemException('idea.forbidden', 'Only the author can change this idea.', 403);
        }
    }

    /** Publishing, unpublishing, choosing the recipients: the author's alone (décision 69). */
    public function assertIsAuthor(Idea $idea, User $viewer): void
    {
        $this->assertCanView($idea, $viewer);

        if ($idea->getAuthor() !== $viewer) {
            throw new ApiProblemException('idea.forbidden', 'Only the author can change this idea.', 403);
        }
    }

    public function canSeeInteractions(Idea $idea, User $viewer): bool
    {
        return $idea->isPublished() && !$idea->hasRecipient($viewer) && \in_array($this->viewOf($idea, $viewer), ['friend', 'manager'], true);
    }

    /**
     * @throws HiddenResourceException for a recipient and anyone who can't see the idea
     */
    public function assertCanSeeInteractions(Idea $idea, User $viewer): void
    {
        if ($idea->hasRecipient($viewer) || !\in_array($this->viewOf($idea, $viewer), ['friend', 'manager'], true)) {
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
        if (!$idea->isSuggestion() || $idea->isArchived() || $idea->hasRecipient($viewer) || !\in_array($this->viewOf($idea, $viewer), ['friend', 'manager'], true)) {
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
     * Spec §5.4 "Archivage": `received` by a recipient on a personal
     * idea (for all of them, décision 69); `gifted` on a suggestion, see
     * canMarkGifted().
     */
    public function assertCanArchive(Idea $idea, User $viewer, IdeaArchiveKind $kind): void
    {
        $this->assertCanView($idea, $viewer);

        $allowed = match ($kind) {
            IdeaArchiveKind::Received => !$idea->isSuggestion() && $idea->hasRecipient($viewer),
            IdeaArchiveKind::Gifted => $idea->isArchived() ? $idea->isSuggestion() : $this->canMarkGifted($idea, $viewer),
        };

        if (!$allowed) {
            throw new ApiProblemException('idea.archive_forbidden', 'You cannot archive this idea this way.', 403);
        }
    }

    /**
     * Who may write a suggestion for `$owner` (spec §5.4, §5.15): their
     * friends, and their manager in their own name.
     */
    public function canSuggestTo(User $owner, User $author): bool
    {
        return $owner->isManagedBy($author) || $this->friendships->areFriends($author, $owner);
    }

    /**
     * Who `$author` may name as one of an idea's recipients (décision
     * 69): themselves, their friends, the profiles they manage — and,
     * acting as a managed profile, its manager, the manager's other
     * children and friends too.
     */
    public function canAddRecipient(User $recipient, User $author): bool
    {
        $human = self::humanBehind($author);

        return $recipient === $author
            || $recipient === $human
            || $this->canSuggestTo($recipient, $author)
            || ($human !== $author && $this->canSuggestTo($recipient, $human));
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
     * Whether `$viewer` looks at `$owner`'s list as its manager, its
     * owner (vue propriétaire: own ideas only, never a suggestion) or a
     * friend. Null: none of these — the list doesn't exist for them.
     */
    public function listViewFor(User $owner, User $viewer): ?string
    {
        if (self::readsAsManager($owner, $viewer)) {
            return 'manager';
        }
        if ($owner === $viewer) {
            return 'owner';
        }

        return $this->friendships->areFriends($viewer, $owner) ? 'friend' : null;
    }
}
