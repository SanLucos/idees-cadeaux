<?php

declare(strict_types=1);

namespace App\Controller\Friendship;

use App\Entity\Enum\UserType;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\UserRepository;
use App\Security\ActingContext;
use App\Service\FriendRequests;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use App\Security\Attribute\ActingUser;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * POST /api/friendships (spec §5.3): "ajout par email exact ...
 * réponse identique que le compte existe ou non" — this always
 * answers the same way, whatever actually happened (or didn't)
 * server-side, exactly like the register-email anti-enumeration
 * pattern from lot 1.
 *
 * Also accepts `{userId}` instead of `{email}`, for the "add from a
 * contacts match" flow (spec §5.3): POST /contacts/match already
 * proved the client's own device knows that email, so there's no new
 * enumeration risk in targeting the match directly by id.
 */
final class SendFriendRequestController
{
    public function __construct(
        private readonly UserRepository $users,
        #[Autowire(service: 'limiter.friend_request')]
        private readonly RateLimiterFactory $rateLimiter,
        private readonly ActingContext $acting,
        private readonly FriendRequests $requests,
    ) {
    }

    #[Route('/api/friendships', name: 'friendship_send', methods: ['POST'])]
    public function __invoke(Request $request, #[ActingUser] User $me): JsonResponse
    {
        // Keyed on the adult behind the request: acting as several
        // children must not multiply the quota.
        $limit = $this->rateLimiter->create(($this->acting->human() ?? $me)->getId()->toRfc4122())->consume();
        if (!$limit->isAccepted()) {
            throw new ApiProblemException('request.rate_limited', 'Too many friend requests.', 429);
        }

        $body = json_decode($request->getContent(), true);
        $userId = \is_array($body) ? (string) ($body['userId'] ?? '') : '';

        if ('' !== $userId) {
            if (!Uuid::isValid($userId)) {
                throw new ApiProblemException('validation.user_id_invalid', 'userId must be a valid identifier.', 422);
            }
            $this->requests->send($me, $this->users->find($userId));
        } else {
            $email = \is_array($body) ? mb_strtolower(trim((string) ($body['email'] ?? ''))) : '';
            if ('' === $email || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                throw new ApiProblemException('validation.email_invalid', 'A valid email is required.', 422);
            }

            // Managed profiles (spec §5.15) are excluded from email search.
            $this->requests->send($me, $this->users->findOneBy(['email' => $email, 'type' => UserType::Regular]));
        }

        return new JsonResponse(['status' => 'sent_if_applicable']);
    }
}
