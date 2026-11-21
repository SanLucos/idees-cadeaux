<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\FriendshipOrigin;
use App\Entity\Enum\FriendshipStatus;
use App\Entity\Enum\IntroductionAnswer;
use App\Entity\Enum\NotificationType;
use App\Entity\Friendship;
use App\Entity\Introduction;
use App\Entity\User;
use App\Notification\Notifier;
use App\Repository\FriendshipRepository;
use App\Repository\IntroductionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A team introduction as its two people live it (spec §5.17, §11
 * décision 71): each answers « Accepter » or « Ignorer »; friends once
 * both accepted. Neither ever learns the other's answer: « Ignorer » is
 * silent, and one's acceptance shows nothing to the other.
 */
final class Introductions
{
    public function __construct(
        private readonly IntroductionRepository $introductions,
        private readonly FriendshipRepository $friendships,
        private readonly Notifier $notifier,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Still worth showing: open, the other person still an adult account
     * not being deleted, and the two not friends by now.
     */
    public function isLive(Introduction $introduction): bool
    {
        $a = $introduction->getUserA();
        $b = $introduction->getUserB();

        return $introduction->isOpen()
            && !$a->isSuspended() && !$b->isSuspended()
            && !$a->isManaged() && !$b->isManaged()
            && !$this->friendships->areFriends($a, $b);
    }

    /**
     * What $user still has to answer.
     *
     * @return Introduction[]
     */
    public function awaiting(User $user): array
    {
        return array_values(array_filter($this->introductions->findAwaiting($user), $this->isLive(...)));
    }

    /** One of mine to answer, or null (the caller answers 404). */
    public function findAwaitingFor(User $user, string $id): ?Introduction
    {
        foreach ($this->awaiting($user) as $introduction) {
            if ($introduction->getId()->toRfc4122() === $id) {
                return $introduction;
            }
        }

        return null;
    }

    /**
     * @return Friendship|null the friendship, when the other had accepted already
     */
    public function accept(Introduction $introduction, User $me): ?Friendship
    {
        $introduction->answer($me, IntroductionAnswer::Accepted);
        if (!$introduction->bothAccepted()) {
            $this->em->flush();

            return null;
        }

        $other = $introduction->otherThan($me);
        $active = $this->friendships->findActiveBetween($me, $other);
        if (null !== $active) {
            // A request between them meanwhile: it becomes the friendship.
            $active->accept();
            $friendship = $active;
        } else {
            $friendship = Friendship::createAccepted($introduction->getUserA(), $introduction->getUserB(), FriendshipOrigin::Introduction);
            $this->em->persist($friendship);
        }
        \assert(FriendshipStatus::Accepted === $friendship->getStatus());
        $introduction->markFriends($friendship);
        $this->em->flush();

        // Whoever accepted first hears of it; I know already.
        $this->notifier->notify(NotificationType::IntroductionAccepted, [$other], [
            'friend' => Notifier::person($me),
            'friendship' => ['id' => $friendship->getId()->toRfc4122()],
        ], $me);

        return $friendship;
    }

    public function ignore(Introduction $introduction, User $me): void
    {
        $introduction->answer($me, IntroductionAnswer::Ignored);
        $this->em->flush();
    }
}
