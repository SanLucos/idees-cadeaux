<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Introduction;
use App\Entity\User;
use App\Exception\HiddenResourceException;
use App\Service\Introductions;
use App\Service\MediaUrls;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Team introductions I received (spec §5.17, §11 décision 71). Not on
 * behalf of a child (ActingAsGuardListener). Anything not mine to
 * answer is a 404; nothing here tells the other person's answer.
 */
final class IntroductionController
{
    public function __construct(
        private readonly Introductions $introductions,
        private readonly MediaUrls $media,
    ) {
    }

    #[Route('/api/introductions', name: 'introduction_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $me): JsonResponse
    {
        return new JsonResponse(array_map(fn (Introduction $i) => [
            'id' => $i->getId()->toRfc4122(),
            'createdAt' => $i->getCreatedAt()->format(\DATE_ATOM),
            // Pseudo and avatar only, as in a friend request (spec §4).
            'user' => [
                'id' => $i->otherThan($me)->getId()->toRfc4122(),
                'displayName' => $i->otherThan($me)->getDisplayName(),
                'avatarUrl' => $this->media->url($i->otherThan($me)->getAvatarPath()),
            ],
        ], $this->introductions->awaiting($me)));
    }

    /** `friends`: the other had accepted already; `accepted`: waiting for them, as far as I can tell. */
    #[Route('/api/introductions/{id}/accept', name: 'introduction_accept', methods: ['POST'])]
    public function accept(string $id, #[CurrentUser] User $me): JsonResponse
    {
        $friendship = $this->introductions->accept($this->mine($id, $me), $me);

        return new JsonResponse(['status' => null !== $friendship ? 'friends' : 'accepted']);
    }

    #[Route('/api/introductions/{id}/ignore', name: 'introduction_ignore', methods: ['POST'])]
    public function ignore(string $id, #[CurrentUser] User $me): JsonResponse
    {
        $this->introductions->ignore($this->mine($id, $me), $me);

        return new JsonResponse(null, 204);
    }

    private function mine(string $id, User $me): Introduction
    {
        return $this->introductions->findAwaitingFor($me, $id) ?? throw new HiddenResourceException();
    }
}
