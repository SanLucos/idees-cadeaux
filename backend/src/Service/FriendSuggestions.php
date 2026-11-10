<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\NotificationType;
use App\Entity\FriendSuggestion;
use App\Entity\User;
use App\Notification\FriendSuggestionOutcomes;
use App\Notification\Notifier;
use App\Repository\FriendshipRepository;
use App\Repository\FriendSuggestionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Présenter à des amis » (spec §5.3, §11 décision 65). Its author
 * only ever sees « en attente », then possibly the success
 * notification (FriendSuggestionOutcomes): refusal, « Ignorer »,
 * expiry and a suggestion without object (A and B already friends, a
 * request between them, a cooldown, an account being deleted) all look
 * alike. Adults only, never on behalf of a child.
 */
final class FriendSuggestions
{
    public function __construct(
        private readonly FriendSuggestionRepository $suggestions,
        private readonly FriendshipRepository $friendships,
        private readonly FriendRequests $requests,
        private readonly FriendSuggestionOutcomes $outcomes,
        private readonly Notifier $notifier,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** An adult friend of mine, not being deleted: the only people a suggestion may name. */
    public function isSuggestable(User $me, User $user): bool
    {
        return $user !== $me && !$user->isManaged() && !$user->isSuspended() && $this->friendships->areFriends($me, $user);
    }

    /**
     * Suggest $suggested to each of $recipients. Already suggested by me
     * and still open: left as is, silently.
     *
     * @param User[] $recipients all suggestable, none being $suggested
     */
    public function suggest(User $me, User $suggested, array $recipients): void
    {
        $toNotify = [];
        foreach ($recipients as $recipient) {
            if (null !== $this->suggestions->findOpen($me, $recipient, $suggested)) {
                continue;
            }
            // One notification per person suggested, whoever suggests them.
            $alreadyShown = [] !== $this->visibleTo($recipient, $suggested);
            $this->em->persist(new FriendSuggestion($me, $recipient, $suggested));
            if (!$alreadyShown && $this->requests->canRequest($recipient, $suggested)) {
                $toNotify[] = $recipient;
            }
        }
        $this->em->flush();

        foreach ($toNotify as $recipient) {
            $this->notifier->notify(NotificationType::FriendSuggestionReceived, [$recipient], [
                'actor' => Notifier::person($me),
                'friend' => Notifier::person($suggested),
            ], $me);
        }
    }

    /**
     * What A sees: pending suggestions whose author is still friends
     * with both, about someone A could send a request to now. All of
     * them when $suggested is null.
     *
     * @return FriendSuggestion[]
     */
    public function visibleTo(User $recipient, ?User $suggested = null): array
    {
        $requestable = [];

        return array_values(array_filter(
            $this->suggestions->findPendingFor($recipient, $suggested),
            function (FriendSuggestion $s) use ($recipient, &$requestable): bool {
                $author = $s->getSuggestedBy();
                $b = $s->getSuggested();
                $key = $b->getId()->toRfc4122();
                $requestable[$key] ??= !$b->isManaged() && $this->requests->canRequest($recipient, $b);

                return $requestable[$key]
                    && !$author->isSuspended()
                    && $this->friendships->areFriends($author, $recipient)
                    && $this->friendships->areFriends($author, $b);
            },
        ));
    }

    /**
     * @return FriendSuggestion[] everything $recipient sees about the same person as $suggestion
     */
    public function sameSuggested(FriendSuggestion $suggestion): array
    {
        return $this->visibleTo($suggestion->getRecipient(), $suggestion->getSuggested());
    }

    /**
     * « Envoyer une demande »: an ordinary request from A to B, marked
     * « suggéré par » the first of those who suggested B.
     *
     * @param FriendSuggestion[] $group what A sees about B, oldest first
     */
    public function sendRequest(array $group): void
    {
        $first = $group[0];
        $friendship = $this->requests->send($first->getRecipient(), $first->getSuggested(), $first->getSuggestedBy());

        foreach ($group as $suggestion) {
            null !== $friendship ? $suggestion->markSent($friendship) : $suggestion->dismiss();
        }
        $this->em->flush();

        if (null !== $friendship) {
            // B had asked A meanwhile: already friends.
            $this->outcomes->friendshipAccepted($friendship);
        }
    }

    /**
     * @param FriendSuggestion[] $group
     */
    public function dismiss(array $group): void
    {
        foreach ($group as $suggestion) {
            $suggestion->dismiss();
        }
        $this->em->flush();
    }

    /**
     * Its author's open suggestions, while both people are still their friends.
     *
     * @return FriendSuggestion[]
     */
    public function openBy(User $author): array
    {
        return array_values(array_filter(
            $this->suggestions->findOpenBy($author),
            fn (FriendSuggestion $s) => $this->isSuggestable($author, $s->getRecipient()) && $this->isSuggestable($author, $s->getSuggested()),
        ));
    }
}
