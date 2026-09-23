<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Comment;
use App\Entity\Contribution;
use App\Entity\ContributionPledge;
use App\Entity\Idea;
use App\Entity\Reservation;
use App\Entity\User;
use App\Repository\CommentRepository;
use App\Repository\ContributionPledgeRepository;
use App\Repository\ContributionRepository;
use App\Repository\ReactionRepository;
use App\Repository\ReservationRepository;
use App\Util\Money;

/**
 * The hidden half of an idea (spec §4): reservation, contribution,
 * reactions, comments. Only ever merged into the *friend* view
 * (IdeaNormalizer::normalizeForFriend) — the owner view never calls
 * this class, so nothing here can reach an owner (CLAUDE.md règle 1).
 *
 * Pledge amounts: present only for the pledge's author and the
 * contribution's initiator; for everyone else the key is absent
 * (CLAUDE.md règle 3, spec §4 "Conséquences techniques" 1).
 *
 * Callers pass ideas the viewer may already see; ideas the viewer owns
 * or that are private are skipped here as a second safety net.
 */
final class InteractionNormalizer
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ContributionRepository $contributions,
        private readonly ContributionPledgeRepository $pledges,
        private readonly ReactionRepository $reactions,
        private readonly CommentRepository $comments,
        private readonly string $storagePublicBaseUrl,
    ) {
    }

    /**
     * Batch version for lists (no N+1).
     *
     * @param Idea[] $ideas
     *
     * @return array<string, array<string, mixed>> keyed by idea id
     */
    public function summarize(array $ideas, User $viewer): array
    {
        $eligible = array_values(array_filter($ideas, static fn (Idea $i) => $i->getOwner() !== $viewer && $i->isPublished()));
        $ids = array_map(static fn (Idea $i) => $i->getId()->toRfc4122(), $eligible);
        if ([] === $ids) {
            return [];
        }

        $reservations = $this->reservations->findActiveByIdeas($ids);
        $contributions = $this->contributions->findCurrentByIdeas($ids);
        $pledges = $this->pledges->findByContributions(array_map(static fn (Contribution $c) => $c->getId()->toRfc4122(), array_values($contributions)));
        $reactions = $this->reactions->summarizeByIdeas($ids, $viewer);
        $commentCounts = $this->comments->countByIdeas($ids);

        $result = [];
        foreach ($eligible as $idea) {
            $id = $idea->getId()->toRfc4122();
            $reservation = $reservations[$id] ?? null;
            $contribution = $contributions[$id] ?? null;
            $openContribution = $contribution?->isOpen() ? $contribution : null;

            $result[$id] = [
                'reservation' => null !== $reservation ? $this->reservation($reservation, $viewer) : null,
                'contribution' => null !== $contribution
                    ? $this->contribution($contribution, $pledges[$contribution->getId()->toRfc4122()] ?? [], $viewer)
                    : null,
                'reactions' => [
                    'count' => $reactions[$id]['count'] ?? 0,
                    'likedByMe' => $reactions[$id]['mine'] ?? false,
                ],
                'commentCount' => $commentCounts[$id] ?? 0,
                'canReact' => $idea->getAuthor() !== $viewer,
                'canMarkGifted' => $idea->isSuggestion() && !$idea->isArchived() && (
                    $idea->getAuthor() === $viewer
                    || $reservation?->getUser() === $viewer
                    || $openContribution?->getInitiator() === $viewer
                ),
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function reservation(Reservation $reservation, User $viewer): array
    {
        return [
            'id' => $reservation->getId()->toRfc4122(),
            'user' => $this->user($reservation->getUser()),
            'isMine' => $reservation->getUser() === $viewer,
            'createdAt' => $reservation->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @param ContributionPledge[] $pledges
     *
     * @return array<string, mixed>
     */
    public function contribution(Contribution $contribution, array $pledges, User $viewer): array
    {
        $isInitiator = $contribution->getInitiator() === $viewer;
        $totalCents = array_sum(array_map(static fn (ContributionPledge $p) => Money::toCents($p->getAmount()), $pledges));
        $targetCents = null !== $contribution->getTargetAmount() ? Money::toCents($contribution->getTargetAmount()) : null;

        $myPledge = null;
        $participants = [];
        foreach ($pledges as $pledge) {
            $isMine = $pledge->getUser() === $viewer;
            $participant = [
                'user' => $this->user($pledge->getUser()),
                'isMe' => $isMine,
                'isInitiator' => $pledge->getUser() === $contribution->getInitiator(),
            ];
            // CLAUDE.md règle 3: the amount only for its author and the initiator.
            if ($isMine || $isInitiator) {
                $participant['amount'] = $pledge->getAmount();
            }
            if ($isMine) {
                $myPledge = $pledge->getAmount();
            }
            $participants[] = $participant;
        }

        return [
            'id' => $contribution->getId()->toRfc4122(),
            'ideaId' => $contribution->getIdea()->getId()->toRfc4122(),
            'status' => $contribution->getStatus()->value,
            'initiator' => $this->user($contribution->getInitiator()),
            'isInitiator' => $isInitiator,
            'targetAmount' => $contribution->getTargetAmount(),
            'currency' => $contribution->getCurrency(),
            'totalAmount' => Money::fromCents($totalCents),
            'remainingAmount' => null !== $targetCents ? Money::fromCents(max(0, $targetCents - $totalCents)) : null,
            'goalReached' => null !== $targetCents && $totalCents >= $targetCents,
            'participantCount' => \count($participants),
            'participants' => $participants,
            'myPledge' => $myPledge,
            'closedAt' => $contribution->getClosedAt()?->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function comment(Comment $comment, User $viewer): array
    {
        return [
            'id' => $comment->getId()->toRfc4122(),
            'ideaId' => $comment->getIdea()->getId()->toRfc4122(),
            'author' => $this->user($comment->getAuthor()),
            'isMine' => $comment->getAuthor() === $viewer,
            'body' => $comment->getBody(),
            'createdAt' => $comment->getCreatedAt()->format(\DATE_ATOM),
            'editedAt' => $comment->getEditedAt()?->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function user(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'displayName' => $user->getDisplayName(),
            'avatarUrl' => null !== $user->getAvatarPath() ? $this->storagePublicBaseUrl.'/'.$user->getAvatarPath() : null,
        ];
    }
}
