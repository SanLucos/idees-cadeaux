<?php

declare(strict_types=1);

namespace App\Controller\Interaction;

use App\Entity\Reaction;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\ReactionRepository;
use App\Security\IdeaAccess;
use App\Serializer\IdeaNormalizer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * "J'aime" (spec §5.9), as an idempotent toggle: PUT likes, DELETE
 * unlikes, replaying either changes nothing. Nobody reacts to an idea
 * they wrote; the owner can't even see the endpoint (404).
 */
final class ReactionController
{
    public function __construct(
        private readonly InteractionRequest $request,
        private readonly IdeaAccess $access,
        private readonly ReactionRepository $reactions,
        private readonly IdeaNormalizer $ideaNormalizer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/ideas/{id}/reaction', name: 'reactions_like', methods: ['PUT'], requirements: ['id' => Requirement::UUID])]
    public function like(string $id, #[CurrentUser] User $me): JsonResponse
    {
        $idea = $this->request->idea($id);
        $this->access->assertCanSeeInteractions($idea, $me);
        if ($idea->getAuthor() === $me) {
            throw new ApiProblemException('reaction.own_idea', 'You cannot react to your own idea.', 422);
        }

        if (null === $this->reactions->findOneFor($idea, $me)) {
            try {
                $this->em->persist(new Reaction($idea, $me));
                $this->em->flush();
            } catch (UniqueConstraintViolationException) {
                // A concurrent identical like: the outcome is the same.
            }
        }

        return new JsonResponse($this->ideaNormalizer->normalizeForFriend($idea, $me));
    }

    #[Route('/api/ideas/{id}/reaction', name: 'reactions_unlike', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    public function unlike(string $id, #[CurrentUser] User $me): JsonResponse
    {
        $idea = $this->request->idea($id);
        $this->access->assertCanSeeInteractions($idea, $me);

        $this->reactions->findOneFor($idea, $me)?->markDeleted();
        $this->em->flush();

        return new JsonResponse($this->ideaNormalizer->normalizeForFriend($idea, $me));
    }
}
