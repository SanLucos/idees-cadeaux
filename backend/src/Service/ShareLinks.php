<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\FriendshipOrigin;
use App\Entity\Enum\NotificationType;
use App\Entity\Friendship;
use App\Entity\ShareLink;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Notification\FriendshipEvents;
use App\Notification\Notifier;
use App\Repository\FriendshipRepository;
use App\Repository\ShareLinkRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Partage du profil par lien" (spec §5.16): the owner's side (create,
 * regenerate, disable) and the joining rules.
 *
 * Every refusal a visitor could hit — unknown, regenerated, disabled or
 * suspended link, owner being deleted, visitor removed by the owner —
 * is the same `share_link.invalid` 404: nothing tells them apart.
 */
final class ShareLinks
{
    public const string RELATION_SELF = 'self';
    public const string RELATION_MANAGER = 'manager';
    public const string RELATION_FRIEND = 'friend';
    public const string RELATION_NONE = 'none';

    public function __construct(
        private readonly ShareLinkRepository $links,
        private readonly FriendshipRepository $friendships,
        private readonly EntityManagerInterface $em,
        private readonly FriendshipEvents $friendshipEvents,
        private readonly Notifier $notifier,
    ) {
    }

    public static function invalid(): ApiProblemException
    {
        return new ApiProblemException('share_link.invalid', 'This link is invalid or has expired.', 404);
    }

    /** The owner's link, created unless one already exists (idempotent). */
    public function create(User $owner, ?User $createdBy): ShareLink
    {
        return $this->links->findForOwner($owner) ?? $this->persistNew($owner, $createdBy);
    }

    /** The old token stops working; friends added through it stay friends. */
    public function regenerate(ShareLink $current, ?User $createdBy): ShareLink
    {
        return $this->em->wrapInTransaction(function () use ($current, $createdBy): ShareLink {
            // Flushed first: the "one live link per owner" index would
            // otherwise see both rows (Doctrine inserts before it updates).
            $current->markDeleted();
            $this->em->flush();

            return $this->persistNew($current->getOwner(), $createdBy);
        });
    }

    public function disable(User $owner): void
    {
        $this->links->findForOwner($owner)?->markDeleted();
        $this->em->flush();
    }

    /** A link a visitor may use: live, not suspended, owner not being deleted. */
    public function resolve(string $token): ShareLink
    {
        $link = $this->links->findByToken($token);
        if (null === $link || $link->isSuspended() || $link->getOwner()->isSuspended()) {
            throw self::invalid();
        }

        return $link;
    }

    /**
     * What opening the link means for a signed-in adult: their own
     * link (or their child's), already friends, or a confirmation to
     * show. Someone the owner removed gets the generic refusal.
     */
    public function relation(ShareLink $link, User $visitor): string
    {
        $owner = $link->getOwner();
        if ($owner === $visitor) {
            return self::RELATION_SELF;
        }
        if ($owner->isManagedBy($visitor)) {
            return self::RELATION_MANAGER;
        }
        if ($this->friendships->areFriends($visitor, $owner)) {
            return self::RELATION_FRIEND;
        }
        if ($this->friendships->wasRemovedBy($owner, $visitor)) {
            throw self::invalid();
        }

        return self::RELATION_NONE;
    }

    /**
     * Confirmed "Devenir ami": an accepted friendship, both ways, right
     * away. A pending request between the two is resolved instead; a
     * declined or expired one doesn't matter — the link prevails.
     *
     * @return array{relation: string, friendship: ?Friendship}
     */
    public function join(ShareLink $link, User $visitor): array
    {
        $relation = $this->relation($link, $visitor);
        if (self::RELATION_NONE !== $relation) {
            return ['relation' => $relation, 'friendship' => null];
        }

        $owner = $link->getOwner();
        if ($this->friendships->countJoinedViaLinkSince($owner, new \DateTimeImmutable('-24 hours')) >= ShareLink::MAX_JOINS_PER_DAY) {
            $link->suspend();
            $this->em->flush();
            $this->notifier->notify(NotificationType::ShareLinkSuspended, [$owner], [], dedupeKey: 'share_link_suspended:'.$link->getId()->toRfc4122());

            throw self::invalid();
        }

        $friendship = $this->friendships->findActiveBetween($visitor, $owner);
        if (null !== $friendship) {
            $friendship->accept();
        } else {
            $friendship = Friendship::createAccepted($visitor, $owner, FriendshipOrigin::Link);
            $this->em->persist($friendship);
        }
        $link->recordJoin();
        $this->em->flush();

        $this->friendshipEvents->notify(NotificationType::FriendJoinedViaLink, $friendship, $visitor);

        return ['relation' => self::RELATION_FRIEND, 'friendship' => $friendship];
    }

    private function persistNew(User $owner, ?User $createdBy): ShareLink
    {
        $link = new ShareLink($owner, $createdBy);
        $this->em->persist($link);
        $this->em->flush();

        return $link;
    }
}
