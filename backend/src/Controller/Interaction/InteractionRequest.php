<?php

declare(strict_types=1);

namespace App\Controller\Interaction;

use App\Entity\Idea;
use App\Exception\ApiProblemException;
use App\Exception\HiddenResourceException;
use App\Repository\IdeaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Request plumbing shared by the lot 4 controllers: JSON body, the
 * client-generated id that makes creates idempotent (spec §8), and
 * lookups that turn anything unknown into the same 404.
 */
final class InteractionRequest
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IdeaRepository $ideas,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function body(Request $request): array
    {
        if ('' === $request->getContent()) {
            return [];
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new ApiProblemException('validation.invalid_body', 'Malformed JSON body.', 400);
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function clientId(array $body): ?Uuid
    {
        if (!isset($body['id'])) {
            return null;
        }
        if (!\is_string($body['id']) || !Uuid::isValid($body['id'])) {
            throw new ApiProblemException('validation.invalid_id', 'The id must be a UUID.', 422);
        }

        return Uuid::fromString($body['id']);
    }

    public function idea(mixed $ideaId): Idea
    {
        $idea = \is_string($ideaId) && Uuid::isValid($ideaId) ? $this->ideas->find($ideaId) : null;

        return $idea ?? throw new HiddenResourceException();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function find(string $class, string $id): object
    {
        $entity = Uuid::isValid($id) ? $this->em->find($class, $id) : null;

        return $entity ?? throw new HiddenResourceException();
    }

    /**
     * For idempotent replays: a soft-deleted row still owns its id.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function findIncludingDeleted(string $class, Uuid $id): ?object
    {
        $filters = $this->em->getFilters();
        $wasEnabled = $filters->isEnabled('soft_deleteable');
        if ($wasEnabled) {
            $filters->disable('soft_deleteable');
        }

        try {
            return $this->em->find($class, $id);
        } finally {
            if ($wasEnabled) {
                $filters->enable('soft_deleteable');
            }
        }
    }
}
