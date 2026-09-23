<?php

declare(strict_types=1);

namespace App\Controller\Interaction;

use App\Entity\Comment;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\CommentRepository;
use App\Security\IdeaAccess;
use App\Serializer\InteractionNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Comments (spec §5.8): text only, 1000 characters, flat thread, edited
 * or deleted by their author. Never visible to the idea's owner.
 */
final class CommentController
{
    public function __construct(
        private readonly InteractionRequest $request,
        private readonly IdeaAccess $access,
        private readonly CommentRepository $comments,
        private readonly InteractionNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/ideas/{id}/comments', name: 'comments_list', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function list(string $id, #[CurrentUser] User $me): JsonResponse
    {
        $idea = $this->request->idea($id);
        $this->access->assertCanSeeInteractions($idea, $me);

        return new JsonResponse(array_map(fn (Comment $c) => $this->normalizer->comment($c, $me), $this->comments->findForIdea($idea)));
    }

    #[Route('/api/comments', name: 'comments_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $body = $this->request->body($request);
        $idea = $this->request->idea($body['ideaId'] ?? null);
        $this->access->assertCanInteract($idea, $me);

        $id = $this->request->clientId($body);
        if (null !== $id && null !== $existing = $this->request->findIncludingDeleted(Comment::class, $id)) {
            if ($existing->getAuthor() !== $me || $existing->getIdea() !== $idea || $existing->isDeleted()) {
                throw new ApiProblemException('request.conflict', 'This id is already used.', 409);
            }

            return new JsonResponse($this->normalizer->comment($existing, $me));
        }

        $comment = new Comment($idea, $me, self::parseBody($body['body'] ?? null), $id);
        $this->em->persist($comment);
        $this->em->flush();

        return new JsonResponse($this->normalizer->comment($comment, $me), 201);
    }

    #[Route('/api/comments/{id}', name: 'comments_update', methods: ['PATCH'])]
    public function update(string $id, Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $comment = $this->mine($id, $me);
        $comment->edit(self::parseBody($this->request->body($request)['body'] ?? null));
        $this->em->flush();

        return new JsonResponse($this->normalizer->comment($comment, $me));
    }

    #[Route('/api/comments/{id}', name: 'comments_delete', methods: ['DELETE'])]
    public function delete(string $id, #[CurrentUser] User $me): Response
    {
        $this->mine($id, $me)->markDeleted();
        $this->em->flush();

        return new Response(null, 204);
    }

    private function mine(string $id, User $me): Comment
    {
        $comment = $this->request->find(Comment::class, $id);
        $this->access->assertCanSeeInteractions($comment->getIdea(), $me);
        if ($comment->getAuthor() !== $me) {
            throw new ApiProblemException('comment.not_yours', 'Only its author can change a comment.', 403);
        }

        return $comment;
    }

    private static function parseBody(mixed $value): string
    {
        $body = \is_string($value) ? trim($value) : '';
        if ('' === $body || mb_strlen($body) > Comment::BODY_MAX_LENGTH) {
            throw new ApiProblemException('validation.comment_body_invalid', 'A comment is 1 to 1000 characters.', 422);
        }

        return $body;
    }
}
