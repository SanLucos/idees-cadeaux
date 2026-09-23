<?php

declare(strict_types=1);

namespace App\Controller\Friendship;

use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\FriendshipRepository;
use App\Service\FriendRemovalEffects;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Security\Attribute\ActingUser;
use Symfony\Component\Uid\Uuid;

/**
 * DELETE /api/friendships/{id} (spec §5.3 "retirer un ami"): bilateral,
 * silent (no notification), a new request is possible afterwards
 * without the usual 30-day cooldown (FriendshipRepository::
 * findLatestForDirection's caller checks this).
 *
 * Cascading effects on what each created on the other's ideas are
 * App\Service\FriendRemovalEffects' job (spec §5.3).
 */
final class RemoveFriendController
{
    public function __construct(
        private readonly FriendshipRepository $friendships,
        private readonly EntityManagerInterface $em,
        private readonly FriendRemovalEffects $effects,
    ) {
    }

    #[Route('/api/friendships/{id}', name: 'friendship_remove', methods: ['DELETE'])]
    public function __invoke(string $id, #[ActingUser] User $me): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            throw new ApiProblemException('resource.not_found', 'Friendship not found.', 404);
        }

        $friendship = $this->friendships->find($id);

        if (null === $friendship || !$friendship->involves($me) || !$friendship->isAccepted()) {
            throw new ApiProblemException('resource.not_found', 'Friendship not found.', 404);
        }

        $friendship->remove($me);
        $this->effects->apply($me, $friendship->otherParty($me));
        $this->em->flush();

        return new JsonResponse(['status' => 'removed']);
    }
}
