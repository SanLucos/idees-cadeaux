<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Enum\NotificationType;
use App\Entity\Friendship;
use App\Repository\FriendshipRepository;
use App\Repository\FriendSuggestionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one outcome a suggestion's author ever learns (spec §11 décision
 * 65): « A et B sont maintenant amis », once B accepted the request A
 * sent from it. Call it whenever a pending request becomes a friendship.
 * Nothing goes to an author no longer friends with both.
 */
final class FriendSuggestionOutcomes
{
    public function __construct(
        private readonly FriendSuggestionRepository $suggestions,
        private readonly FriendshipRepository $friendships,
        private readonly Notifier $notifier,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function friendshipAccepted(Friendship $friendship): void
    {
        if (!$friendship->isAccepted()) {
            return;
        }

        $authors = [];
        foreach ($this->suggestions->findSentWith($friendship) as $suggestion) {
            $suggestion->markAccepted();
            $author = $suggestion->getSuggestedBy();
            if ($this->friendships->areFriends($author, $suggestion->getRecipient()) && $this->friendships->areFriends($author, $suggestion->getSuggested())) {
                $authors[$author->getId()->toRfc4122()] = [$author, $suggestion];
            }
        }
        $this->em->flush();

        foreach ($authors as [$author, $suggestion]) {
            $this->notifier->notify(NotificationType::FriendSuggestionAccepted, [$author], [
                'actor' => Notifier::person($suggestion->getRecipient()),
                'friend' => Notifier::person($suggestion->getSuggested()),
            ]);
        }
    }
}
