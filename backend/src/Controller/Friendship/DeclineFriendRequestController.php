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
 * POST /api/friendships/{id}/decline (spec §5.3): silent by design —
 * no notification, and the requester's view of this request never
 * shows "declined" (CLAUDE.md règle 4, App\Serializer\FriendshipNormalizer).
 */
final class DeclineFriendRequestController
{
    public function __construct(
        private readonly FriendshipRepository $friendships,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/friendships/{id}/decline', name: 'friendship_decline', methods: ['POST'])]
    public function __invoke(string $id, #[ActingUser] User $me): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            throw new ApiProblemException('resource.not_found', 'Friend request not found.', 404);
        }

        $friendship = $this->friendships->find($id);

        if (null === $friendship || $friendship->getAddressee() !== $me || !$friendship->isPending()) {
            throw new ApiProblemException('resource.not_found', 'Friend request not found.', 404);
        }

        $friendship->decline();
        $this->em->flush();

        return new JsonResponse(['status' => 'declined']);
    }
}
