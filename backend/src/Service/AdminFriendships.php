<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\FriendshipOrigin;
use App\Entity\Enum\FriendshipStatus;
use App\Entity\Enum\NotificationType;
use App\Entity\Friendship;
use App\Entity\Introduction;
use App\Entity\User;
use App\Notification\Notifier;
use App\Repository\FriendshipRepository;
use App\Repository\IntroductionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the administrator does with friendships (spec §5.17, §11
 * décision 71): introduce two adults (« l'équipe » proposes, both
 * accept), make them friends silently, or end a friendship silently.
 * Never past a refusal or a removal by one of them.
 */
final class AdminFriendships
{
    /** Where a pair stands, as the administration shows it. */
    public const string FRIENDS = 'friends';
    public const string INTRODUCED = 'introduced';
    public const string REQUEST_PENDING = 'request_pending';
    public const string AVAILABLE = 'available';
    /** Never says why: a refusal, a removal, a child, an account being deleted. */
    public const string UNAVAILABLE = 'unavailable';

    public const string MODE_INTRODUCE = 'introduce';
    public const string MODE_FORCE = 'force';

    public function __construct(
        private readonly FriendshipRepository $friendships,
        private readonly IntroductionRepository $introductions,
        private readonly Introductions $userSide,
        private readonly FriendRemovalEffects $effects,
        private readonly Notifier $notifier,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function pairStatus(User $a, User $b): string
    {
        if ($a === $b || $a->isManaged() || $b->isManaged() || $a->isSuspended() || $b->isSuspended()) {
            return self::UNAVAILABLE;
        }
        $active = $this->friendships->findActiveBetween($a, $b);
        if (null !== $active && FriendshipStatus::Accepted === $active->getStatus()) {
            return self::FRIENDS;
        }
        if ($this->friendships->hasRefusalOrRemovalBetween($a, $b) || $this->introductions->wasIgnoredBetween($a, $b)) {
            return self::UNAVAILABLE;
        }
        $open = $this->introductions->findOpenBetween($a, $b);
        if (null !== $open && $this->userSide->isLive($open)) {
            return self::INTRODUCED;
        }

        return null !== $active ? self::REQUEST_PENDING : self::AVAILABLE;
    }

    /**
     * @return bool whether something was done
     */
    public function connect(User $admin, User $a, User $b, string $mode): bool
    {
        return self::MODE_FORCE === $mode ? $this->force($a, $b) : $this->introduce($admin, $a, $b);
    }

    public function introduce(User $admin, User $a, User $b): bool
    {
        if (!\in_array($this->pairStatus($a, $b), [self::AVAILABLE, self::REQUEST_PENDING], true)) {
            return false;
        }
        // A stale one (no longer live) would block « une seule en attente par paire ».
        $this->introductions->findOpenBetween($a, $b)?->close();

        $introduction = new Introduction($a, $b, $admin);
        $this->em->persist($introduction);
        $this->em->flush();

        // From « l'équipe »: no actor, never the administrator's name.
        foreach ([[$a, $b], [$b, $a]] as [$recipient, $other]) {
            $this->notifier->notify(NotificationType::IntroductionReceived, [$recipient], [
                'friend' => Notifier::person($other),
                'introduction' => ['id' => $introduction->getId()->toRfc4122()],
            ]);
        }

        return true;
    }

    /** Friends at once, with no notification and nothing telling them how. */
    public function force(User $a, User $b): bool
    {
        if (!\in_array($this->pairStatus($a, $b), [self::AVAILABLE, self::REQUEST_PENDING, self::INTRODUCED], true)) {
            return false;
        }

        $active = $this->friendships->findActiveBetween($a, $b);
        if (null !== $active) {
            $active->accept();
        } else {
            $this->em->persist(Friendship::createAccepted($a, $b, FriendshipOrigin::Admin));
        }
        $this->introductions->findOpenBetween($a, $b)?->close();
        $this->em->flush();

        return true;
    }

    /**
     * @return bool false when it isn't a friendship the administrator may end
     */
    public function remove(Friendship $friendship): bool
    {
        $a = $friendship->getRequester();
        $b = $friendship->getAddressee();
        if (!$friendship->isAccepted() || FriendshipOrigin::Family === $friendship->getOrigin() || $a->isManaged() || $b->isManaged()) {
            return false;
        }

        $friendship->removeByAdmin();
        $this->effects->apply($a, $b);
        $this->em->flush();

        return true;
    }

    public function cancel(Introduction $introduction): bool
    {
        if (!$introduction->isOpen()) {
            return false;
        }
        $introduction->close();
        $this->em->flush();

        return true;
    }
}
