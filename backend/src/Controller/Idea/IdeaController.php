<?php

declare(strict_types=1);

namespace App\Controller\Idea;

use App\Entity\Enum\IdeaArchiveKind;
use App\Entity\Enum\IdeaVisibility;
use App\Entity\Enum\NotificationType;
use App\Entity\Idea;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Exception\HiddenResourceException;
use App\Repository\IdeaRepository;
use App\Repository\UserRepository;
use App\Security\IdeaAccess;
use App\Serializer\IdeaNormalizer;
use App\Notification\IdeaEvents;
use App\Service\IdeaFieldsApplier;
use App\Repository\ContributionRepository;
use App\Service\IdeaImageUploadService;
use App\Service\IdeaInteractionPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use App\Security\Attribute\ActingUser;
use Symfony\Component\Uid\Uuid;

/**
 * Idea CRUD and state changes (spec §5.4, §7). Every read and write
 * goes through App\Security\IdeaAccess first: an idea the caller may
 * not see is a 404 whatever the verb (CLAUDE.md règle 1).
 */
final class IdeaController
{
    public function __construct(
        private readonly IdeaRepository $ideas,
        private readonly UserRepository $users,
        private readonly IdeaAccess $access,
        private readonly IdeaNormalizer $normalizer,
        private readonly IdeaFieldsApplier $fields,
        private readonly IdeaImageUploadService $images,
        private readonly EntityManagerInterface $em,
        private readonly IdeaInteractionPurger $purger,
        private readonly ContributionRepository $contributions,
        private readonly IdeaEvents $events,
    ) {
    }

    /** More than enough for a family (décision 69), and a bound on the checks. */
    private const int MAX_RECIPIENTS = 20;

    /**
     * Creates an idea for me, or a suggestion for a friend (`ownerId`),
     * or an idea for several people at once (`recipientIds`, décision 69).
     * Idempotent on the client-generated `id` (spec §8): replaying the
     * same create returns the existing idea instead of a duplicate.
     */
    #[Route('/api/ideas', name: 'ideas_create', methods: ['POST'])]
    public function create(Request $request, #[ActingUser] User $me): JsonResponse
    {
        $body = self::decode($request);

        $id = null;
        if (isset($body['id'])) {
            if (!\is_string($body['id']) || !Uuid::isValid($body['id'])) {
                throw new ApiProblemException('validation.invalid_id', 'The id must be a UUID.', 422);
            }
            $id = Uuid::fromString($body['id']);
            if (null !== $existing = $this->findIncludingDeleted($id)) {
                if ($existing->getAuthor() !== $me || $existing->isDeleted()) {
                    throw new ApiProblemException('request.conflict', 'This id is already used.', 409);
                }

                return new JsonResponse($this->view($existing, $me), 200);
            }
        }

        $recipients = $this->parseRecipients($body, $me) ?? [$me];

        $visibility = IdeaVisibility::tryFrom((string) ($body['visibility'] ?? IdeaVisibility::Published->value));
        if (null === $visibility) {
            throw new ApiProblemException('validation.idea_visibility_invalid', 'Visibility must be "private" or "published".', 422);
        }

        $idea = new Idea($recipients, $me, IdeaFieldsApplier::parseTitle($body['title'] ?? null), $visibility, $id);
        $this->fields->apply($idea, $body);

        $this->em->persist($idea);
        $this->em->flush();
        $this->events->published($idea, $me);

        return new JsonResponse($this->view($idea, $me), 201);
    }

    #[Route('/api/ideas/{id}', name: 'ideas_show', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function show(string $id, #[ActingUser] User $me): JsonResponse
    {
        $idea = $this->find($id);
        $this->access->assertCanView($idea, $me);

        return new JsonResponse($this->view($idea, $me));
    }

    #[Route('/api/ideas/{id}', name: 'ideas_update', methods: ['PATCH'], requirements: ['id' => Requirement::UUID])]
    public function update(string $id, Request $request, #[ActingUser] User $me): JsonResponse
    {
        $idea = $this->find($id);
        $this->access->assertCanEdit($idea, $me);
        $body = self::decode($request);

        $recipients = $this->parseRecipients($body, $me);
        if (null !== $recipients && !self::sameUsers($recipients, $idea->getRecipients())) {
            // Décision 69: the author's alone, and only while nobody else can see the idea.
            $this->access->assertIsAuthor($idea, $me);
            if ($idea->isPublished()) {
                throw new ApiProblemException('idea.recipients_locked', 'Recipients can only change while the idea is private.', 422);
            }
            $idea->setRecipients($recipients);
        }

        $this->fields->apply($idea, $body);
        $this->em->flush();

        return new JsonResponse($this->view($idea, $me));
    }

    #[Route('/api/ideas/{id}', name: 'ideas_delete', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    public function delete(string $id, #[ActingUser] User $me): Response
    {
        $idea = $this->find($id);
        $this->access->assertCanEdit($idea, $me);

        // Soft delete: the tombstone is what /sync propagates (spec §8).
        $idea->markDeleted();
        $this->em->flush();

        return new Response(null, 204);
    }

    /**
     * Spec §5.4: publishing needs an active friendship with each
     * recipient — a draft written before a removal stays readable by
     * its author but can't be published any more.
     */
    #[Route('/api/ideas/{id}/publish', name: 'ideas_publish', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function publish(string $id, #[ActingUser] User $me): JsonResponse
    {
        $idea = $this->find($id);
        $this->access->assertIsAuthor($idea, $me);

        foreach ($idea->getRecipients() as $recipient) {
            if (!$this->access->canAddRecipient($recipient, $me)) {
                throw new ApiProblemException('idea.friendship_required', 'You are no longer friends with this person.', 422);
            }
        }

        $idea->publish();
        $this->em->flush();
        $this->events->published($idea, $me);

        return new JsonResponse($this->view($idea, $me));
    }

    /**
     * Spec §5.4 "repasser en privé", allowed at any time: the idea's
     * reservation, comments, reactions and contribution are deleted for
     * good. Notifying whoever had interacted arrives with lot 5.
     */
    #[Route('/api/ideas/{id}/unpublish', name: 'ideas_unpublish', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function unpublish(string $id, #[ActingUser] User $me): JsonResponse
    {
        $idea = $this->find($id);
        $this->access->assertIsAuthor($idea, $me);

        $notified = $this->events->beforeUnpublish($idea);
        $idea->unpublish();
        $this->purger->purge($idea);
        $this->em->flush();
        $this->events->unpublished($idea, $notified, $me);

        return new JsonResponse($this->view($idea, $me));
    }

    #[Route('/api/ideas/{id}/archive', name: 'ideas_archive', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function archive(string $id, Request $request, #[ActingUser] User $me): JsonResponse
    {
        $idea = $this->find($id);
        $kind = IdeaArchiveKind::tryFrom((string) (self::decode($request)['kind'] ?? ''));
        if (null === $kind) {
            // Visibility first: an invisible idea stays a 404 even with a bad body.
            $this->access->assertCanView($idea, $me);
            throw new ApiProblemException('validation.archive_kind_invalid', 'kind must be "received" or "gifted".', 422);
        }

        $this->access->assertCanArchive($idea, $me, $kind);
        if ($idea->isArchived()) {
            throw new ApiProblemException('idea.already_archived', 'This idea is already archived.', 409);
        }

        $archivedAsGift = IdeaArchiveKind::Gifted === $kind;
        $idea->archive($me, $kind);
        // Spec §5.4: archiving closes an open contribution.
        $this->contributions->findOpenForIdea($idea)?->close();
        $this->em->flush();
        if ($archivedAsGift) {
            $this->events->interaction(NotificationType::SuggestionGifted, $idea, $me);
        }

        return new JsonResponse($this->view($idea, $me));
    }

    #[Route('/api/ideas/{id}/unarchive', name: 'ideas_unarchive', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function unarchive(string $id, #[ActingUser] User $me): JsonResponse
    {
        $idea = $this->find($id);
        $this->access->assertCanUnarchive($idea, $me);

        $idea->unarchive();
        $this->em->flush();

        return new JsonResponse($this->view($idea, $me));
    }

    #[Route('/api/ideas/{id}/image', name: 'ideas_image_upload', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function uploadImage(string $id, Request $request, #[ActingUser] User $me): JsonResponse
    {
        $idea = $this->find($id);
        $this->access->assertCanEdit($idea, $me);

        $file = $request->files->get('image');
        if (null === $file) {
            throw new ApiProblemException('validation.image_required', 'The "image" file field is required.', 422);
        }

        $idea->setImagePath($this->images->upload($idea, $file));
        $this->em->flush();

        return new JsonResponse($this->view($idea, $me));
    }

    #[Route('/api/ideas/{id}/image', name: 'ideas_image_delete', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    public function deleteImage(string $id, #[ActingUser] User $me): JsonResponse
    {
        $idea = $this->find($id);
        $this->access->assertCanEdit($idea, $me);

        $this->images->delete($idea->getImagePath());
        $idea->setImagePath(null);
        $this->em->flush();

        return new JsonResponse($this->view($idea, $me));
    }

    /**
     * `recipientIds` (or the older `ownerId`, one recipient) from a
     * body; null when it names none. Anyone `$me` may not name is the
     * same 404 as an unknown id: never reveals an account.
     *
     * @param array<string, mixed> $body
     *
     * @return User[]|null
     */
    private function parseRecipients(array $body, User $me): ?array
    {
        if (\array_key_exists('recipientIds', $body)) {
            $ids = $body['recipientIds'];
            if (!\is_array($ids) || [] === $ids || \count($ids) > self::MAX_RECIPIENTS) {
                throw new ApiProblemException('validation.idea_recipients_invalid', 'recipientIds must list 1 to 20 people.', 422);
            }
        } elseif (isset($body['ownerId'])) {
            $ids = [$body['ownerId']];
        } else {
            return null;
        }

        $recipients = [];
        foreach ($ids as $id) {
            if (!\is_string($id) || !Uuid::isValid($id)) {
                throw new HiddenResourceException();
            }
            $user = $id === $me->getId()->toRfc4122() ? $me : $this->users->find($id);
            if (null === $user || !$this->access->canAddRecipient($user, $me)) {
                throw new HiddenResourceException();
            }
            $recipients[$user->getId()->toRfc4122()] = $user;
        }

        if ($me->isManaged() && !isset($recipients[$me->getId()->toRfc4122()])) {
            // Spec §11 décision 25: no suggestion on behalf of a child.
            throw new ApiProblemException('acting_as.not_allowed', 'This action is not available on behalf of a managed profile.', 403);
        }

        return array_values($recipients);
    }

    /**
     * @param User[] $a
     * @param User[] $b
     */
    private static function sameUsers(array $a, array $b): bool
    {
        $ids = static fn (array $users): array => array_map(static fn (User $u) => $u->getId()->toRfc4122(), $users);
        $x = $ids($a);
        $y = $ids($b);
        sort($x);
        sort($y);

        return $x === $y;
    }

    /**
     * @return array<string, mixed>
     */
    private function view(Idea $idea, User $me): array
    {
        return $this->normalizer->normalizeFor($idea, $me);
    }

    private function find(string $id): Idea
    {
        return $this->ideas->find($id) ?? throw new HiddenResourceException();
    }

    private function findIncludingDeleted(Uuid $id): ?Idea
    {
        $filters = $this->em->getFilters();
        $wasEnabled = $filters->isEnabled('soft_deleteable');
        if ($wasEnabled) {
            $filters->disable('soft_deleteable');
        }

        try {
            return $this->ideas->find($id);
        } finally {
            if ($wasEnabled) {
                $filters->enable('soft_deleteable');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Request $request): array
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
}
