<?php

declare(strict_types=1);

namespace App\Controller\Friendship;

use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\FriendshipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Security\Attribute\ActingUser;
use Symfony\Component\Uid\Uuid;

/**
 * POST /api/friendships/{id}/cancel (spec §5.3): the requester
 * withdraws their own still-pending request.
 */
final class CancelFriendRequestController
{
    public function __construct(
        private readonly FriendshipRepository $friendships,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/friendships/{id}/cancel', name: 'friendship_cancel', methods: ['POST'])]
    public function __invoke(string $id, #[ActingUser] User $me): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            throw new ApiProblemException('resource.not_found', 'Friend request not found.', 404);
        }

        $friendship = $this->friendships->find($id);

        if (null === $friendship || $friendship->getRequester() !== $me || !$friendship->isPending()) {
            throw new ApiProblemException('resource.not_found', 'Friend request not found.', 404);
        }

        $friendship->cancel();
        $this->em->flush();

        return new JsonResponse(['status' => 'cancelled']);
    }
}
