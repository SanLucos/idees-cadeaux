<?php

declare(strict_types=1);

namespace App\Controller\Friendship;

use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\FriendshipRepository;
use App\Serializer\FriendshipNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

final class AcceptFriendRequestController
{
    public function __construct(
        private readonly FriendshipRepository $friendships,
        private readonly EntityManagerInterface $em,
        private readonly FriendshipNormalizer $normalizer,
    ) {
    }

    #[Route('/api/friendships/{id}/accept', name: 'friendship_accept', methods: ['POST'])]
    public function __invoke(string $id, #[CurrentUser] User $me): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            throw new ApiProblemException('resource.not_found', 'Friend request not found.', 404);
        }

        $friendship = $this->friendships->find($id);

        // Only the addressee may respond, and only while pending: anyone
        // else (including the requester) gets the same 404 as a request
        // that doesn't exist — no need to reveal which.
        if (null === $friendship || $friendship->getAddressee() !== $me || !$friendship->isPending()) {
            throw new ApiProblemException('resource.not_found', 'Friend request not found.', 404);
        }

        $friendship->accept();
        $this->em->flush();

        return new JsonResponse($this->normalizer->normalize($friendship, $me));
    }
}
