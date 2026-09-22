<?php

declare(strict_types=1);

namespace App\Controller\Friendship;

use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\FriendshipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * DELETE /api/friendships/{id} (spec §5.3 "retirer un ami"): bilateral,
 * silent (no notification), a new request is possible afterwards
 * without the usual 30-day cooldown (FriendshipRepository::
 * findLatestForDirection's caller checks this).
 *
 * Cascading effects on the ex-friends' shared content — cancelling
 * reservations/pledges/reactions, closing a contribution the removed
 * party initiated — apply to entities that don't exist until lot 4;
 * this only handles what's possible today (ending the relationship
 * itself, which already cuts off VisibleToOwnerOrFriendsExtension's
 * mutual visibility). Lot 4 must extend this action, not replace it.
 */
final class RemoveFriendController
{
    public function __construct(
        private readonly FriendshipRepository $friendships,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/friendships/{id}', name: 'friendship_remove', methods: ['DELETE'])]
    public function __invoke(string $id, #[CurrentUser] User $me): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            throw new ApiProblemException('resource.not_found', 'Friendship not found.', 404);
        }

        $friendship = $this->friendships->find($id);

        if (null === $friendship || !$friendship->involves($me) || !$friendship->isAccepted()) {
            throw new ApiProblemException('resource.not_found', 'Friendship not found.', 404);
        }

        $friendship->remove($me);
        $this->em->flush();

        return new JsonResponse(['status' => 'removed']);
    }
}
