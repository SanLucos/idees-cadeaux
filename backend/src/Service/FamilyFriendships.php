<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\FriendshipOrigin;
use App\Entity\Friendship;
use App\Entity\User;
use App\Repository\FriendshipRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Partager avec mes amis » (spec §5.15, §11 décision 59): while a
 * child profile has the option, each accepted friend of one of its
 * managers is its friend too, with no request. Those friendships
 * (origin `family`) are what every other rule already reads — lists,
 * /sync, notifications, birthday reminders —, created and removed here
 * whenever the option, the managers or their friendships change
 * (App\Doctrine\FamilyFriendshipsListener).
 *
 * Never created: with an adult who removed the child (they keep it
 * hidden), nor while another friendship or request is active between
 * them. Never for a manager, nor with another child profile.
 */
final class FamilyFriendships
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FriendshipRepository $friendships,
        private readonly UserRepository $users,
        private readonly FriendRemovalEffects $effects,
    ) {
    }

    /** Brings `$child`'s family friendships in line; the caller flushes. */
    public function refresh(User $child): void
    {
        /** @var array<string, array{User, User}> $wanted friend id => [friend, the manager they are friends with] */
        $wanted = [];
        if ($child->isSharedWithManagerFriends() && null === $child->getDeletionScheduledAt()) {
            foreach ($child->getManagers() as $manager) {
                foreach ($this->friendships->findAccepted($manager) as $friendship) {
                    $friend = $friendship->otherParty($manager);
                    if (!$friend->isManaged() && !$child->isManagedBy($friend)) {
                        $wanted[$friend->getId()->toRfc4122()] ??= [$friend, $manager];
                    }
                }
            }
        }

        foreach ($this->friendships->findFamilyOf($child) as $friendship) {
            $friend = $friendship->otherParty($child);
            if (isset($wanted[$friend->getId()->toRfc4122()])) {
                unset($wanted[$friend->getId()->toRfc4122()]);
                continue;
            }
            // Ended by the child's side, not by the friend: shared again later, it comes back.
            $friendship->remove($child);
            $this->effects->apply($child, $friend);
        }

        foreach ($wanted as [$friend, $manager]) {
            if (null !== $this->friendships->findActiveBetween($child, $friend) || $this->friendships->wasRemovedBy($friend, $child)) {
                continue;
            }
            $friendship = Friendship::createAccepted($child, $friend, FriendshipOrigin::Family);
            $friendship->setOnBehalfOfManager($manager);
            $this->em->persist($friendship);
        }
    }

    /** After `$adult` gained or lost a friend: the children they manage. */
    public function refreshChildrenOf(User $adult): void
    {
        foreach ($this->users->findChildrenOf($adult) as $child) {
            $this->refresh($child);
        }
    }
}
