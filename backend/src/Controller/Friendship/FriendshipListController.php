<?php

declare(strict_types=1);

namespace App\Controller\Friendship;

use App\Entity\User;
use App\Repository\FriendshipRepository;
use App\Serializer\FriendshipNormalizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class FriendshipListController
{
    public function __construct(
        private readonly FriendshipRepository $friendships,
        private readonly FriendshipNormalizer $normalizer,
    ) {
    }

    #[Route('/api/friendships', name: 'friendship_list_accepted', methods: ['GET'])]
    public function friends(#[CurrentUser] User $me): JsonResponse
    {
        return $this->respond($this->friendships->findAccepted($me), $me);
    }

    #[Route('/api/friendships/incoming', name: 'friendship_list_incoming', methods: ['GET'])]
    public function incoming(#[CurrentUser] User $me): JsonResponse
    {
        return $this->respond($this->friendships->findPendingIncoming($me), $me);
    }

    /**
     * Includes requests the addressee has silently declined — masked
     * as pending/expired by FriendshipNormalizer (CLAUDE.md règle 4).
     */
    #[Route('/api/friendships/outgoing', name: 'friendship_list_outgoing', methods: ['GET'])]
    public function outgoing(#[CurrentUser] User $me): JsonResponse
    {
        return $this->respond($this->friendships->findOutgoingVisible($me), $me);
    }

    /**
     * @param \App\Entity\Friendship[] $friendships
     */
    private function respond(array $friendships, User $me): JsonResponse
    {
        return new JsonResponse(array_map(fn ($f) => $this->normalizer->normalize($f, $me), $friendships));
    }
}
