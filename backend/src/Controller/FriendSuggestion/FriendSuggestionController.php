<?php

declare(strict_types=1);

namespace App\Controller\FriendSuggestion;

use App\Entity\FriendSuggestion;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Exception\HiddenResourceException;
use App\Repository\FriendSuggestionRepository;
use App\Repository\UserRepository;
use App\Service\FriendSuggestions;
use App\Service\MediaUrls;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Friends introduced to each other (spec §5.3, §11 décision 65). Not
 * on behalf of a child: ActingAsGuardListener refuses these paths.
 * Anything not mine to see is a 404, like a suggestion that doesn't
 * exist.
 */
final class FriendSuggestionController
{
    public const int MAX_RECIPIENTS = 30;

    public function __construct(
        private readonly FriendSuggestions $service,
        private readonly FriendSuggestionRepository $suggestions,
        private readonly UserRepository $users,
        private readonly MediaUrls $media,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'limiter.friend_suggestion')]
        private readonly RateLimiterFactory $suggestionLimiter,
        #[Autowire(service: 'limiter.friend_request')]
        private readonly RateLimiterFactory $requestLimiter,
    ) {
    }

    /** Body: `{suggested: <B's id>, recipients: [<A's id>, …]}`, all adult friends of mine. */
    #[Route('/api/friend-suggestions', name: 'friend_suggestion_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        if (!\is_array($body)) {
            throw new ApiProblemException('validation.invalid_body', 'Malformed JSON body.', 400);
        }

        $suggested = $this->friend($body['suggested'] ?? null, $me);
        $ids = $body['recipients'] ?? null;
        if (!\is_array($ids) || [] === $ids || \count($ids) > self::MAX_RECIPIENTS) {
            throw new ApiProblemException('friend_suggestion.recipients_invalid', 'Choose between 1 and 30 friends.', 422);
        }
        $recipients = [];
        foreach (array_unique(array_map('strval', $ids)) as $id) {
            $recipient = $this->friend($id, $me);
            if ($recipient === $suggested) {
                throw new ApiProblemException('friend_suggestion.recipients_invalid', 'Nobody is suggested to themself.', 422);
            }
            $recipients[] = $recipient;
        }

        if (!$this->suggestionLimiter->create($me->getId()->toRfc4122())->consume(\count($recipients))->isAccepted()) {
            throw new ApiProblemException('request.rate_limited', 'Too many suggestions.', 429);
        }

        $this->service->suggest($me, $suggested, $recipients);

        return new JsonResponse($this->sentList($me), 201);
    }

    /** Suggestions I received; with `?sent=1`, those I made, all « en attente ». */
    #[Route('/api/friend-suggestions', name: 'friend_suggestion_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $me): JsonResponse
    {
        if ($request->query->getBoolean('sent')) {
            return new JsonResponse($this->sentList($me));
        }

        $bySuggested = [];
        foreach ($this->service->visibleTo($me) as $suggestion) {
            $bySuggested[$suggestion->getSuggested()->getId()->toRfc4122()][] = $suggestion;
        }

        return new JsonResponse(array_values(array_map(function (array $group): array {
            $first = $group[0];

            return [
                'id' => $first->getId()->toRfc4122(),
                'createdAt' => $first->getCreatedAt()->format(\DATE_ATOM),
                'suggested' => $this->summary($first->getSuggested()),
                'suggestedBy' => array_map(static fn (FriendSuggestion $s) => [
                    'id' => $s->getSuggestedBy()->getId()->toRfc4122(),
                    'displayName' => $s->getSuggestedBy()->getDisplayName(),
                ], $group),
            ];
        }, $bySuggested)));
    }

    #[Route('/api/friend-suggestions/{id}/send-request', name: 'friend_suggestion_send_request', methods: ['POST'])]
    public function sendRequest(string $id, #[CurrentUser] User $me): JsonResponse
    {
        $group = $this->receivedGroup($id, $me);

        if (!$this->requestLimiter->create($me->getId()->toRfc4122())->consume()->isAccepted()) {
            throw new ApiProblemException('request.rate_limited', 'Too many friend requests.', 429);
        }

        $this->service->sendRequest($group);

        // As for any request (spec §5.3): the same answer whatever happened.
        return new JsonResponse(['status' => 'sent_if_applicable']);
    }

    #[Route('/api/friend-suggestions/{id}/dismiss', name: 'friend_suggestion_dismiss', methods: ['POST'])]
    public function dismiss(string $id, #[CurrentUser] User $me): JsonResponse
    {
        $this->service->dismiss($this->receivedGroup($id, $me));

        return new JsonResponse(null, 204);
    }

    /** Its author withdraws it; whatever A did with it, the answer is the same. */
    #[Route('/api/friend-suggestions/{id}', name: 'friend_suggestion_cancel', methods: ['DELETE'])]
    public function cancel(string $id, #[CurrentUser] User $me): JsonResponse
    {
        $suggestion = Uuid::isValid($id) ? $this->suggestions->find($id) : null;
        $open = array_filter($this->service->openBy($me), static fn (FriendSuggestion $s) => $s === $suggestion);
        if (null === $suggestion || [] === $open) {
            throw new HiddenResourceException();
        }

        $suggestion->cancel();
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    /**
     * @return FriendSuggestion[] what I see about the person suggested in $id, oldest first
     */
    private function receivedGroup(string $id, User $me): array
    {
        $suggestion = Uuid::isValid($id) ? $this->suggestions->find($id) : null;
        if (null === $suggestion || $suggestion->getRecipient() !== $me) {
            throw new HiddenResourceException();
        }
        $group = $this->service->sameSuggested($suggestion);
        if (!\in_array($suggestion, $group, true)) {
            throw new HiddenResourceException();
        }

        return $group;
    }

    private function friend(mixed $id, User $me): User
    {
        $user = \is_string($id) && Uuid::isValid($id) ? $this->users->find($id) : null;
        if (null === $user || !$this->service->isSuggestable($me, $user)) {
            throw new ApiProblemException('friend_suggestion.not_a_friend', 'Only your adult friends can be introduced.', 422);
        }

        return $user;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sentList(User $me): array
    {
        return array_map(fn (FriendSuggestion $s) => [
            'id' => $s->getId()->toRfc4122(),
            // Never anything else (spec §11 décision 65).
            'status' => 'pending',
            'createdAt' => $s->getCreatedAt()->format(\DATE_ATOM),
            'recipient' => $this->summary($s->getRecipient()),
            'suggested' => $this->summary($s->getSuggested()),
        ], $this->service->openBy($me));
    }

    /**
     * Pseudo and avatar only, as in a friend request (spec §4).
     *
     * @return array<string, mixed>
     */
    private function summary(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'displayName' => $user->getDisplayName(),
            'avatarUrl' => $this->media->url($user->getAvatarPath()),
        ];
    }
}
