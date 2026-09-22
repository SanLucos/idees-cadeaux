<?php

declare(strict_types=1);

namespace App\Controller\Friendship;

use App\Entity\Enum\FriendshipStatus;
use App\Entity\Enum\UserType;
use App\Entity\Friendship;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\FriendshipRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
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
        private readonly FriendshipRepository $friendships,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'limiter.friend_request')]
        private readonly RateLimiterFactory $rateLimiter,
    ) {
    }

    #[Route('/api/friendships', name: 'friendship_send', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $limit = $this->rateLimiter->create($me->getId()->toRfc4122())->consume();
        if (!$limit->isAccepted()) {
            throw new ApiProblemException('request.rate_limited', 'Too many friend requests.', 429);
        }

        $body = json_decode($request->getContent(), true);
        $userId = \is_array($body) ? (string) ($body['userId'] ?? '') : '';

        if ('' !== $userId) {
            if (!Uuid::isValid($userId)) {
                throw new ApiProblemException('validation.user_id_invalid', 'userId must be a valid identifier.', 422);
            }
            $this->tryCreateRequestToUser($me, $this->users->find($userId));
        } else {
            $email = \is_array($body) ? mb_strtolower(trim((string) ($body['email'] ?? ''))) : '';
            if ('' === $email || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
                throw new ApiProblemException('validation.email_invalid', 'A valid email is required.', 422);
            }

            // Managed profiles (spec §5.15) are excluded from email search —
            // moot until lot 4 bis creates any, but the filter costs nothing now.
            $this->tryCreateRequestToUser($me, $this->users->findOneBy(['email' => $email, 'type' => UserType::Regular]));
        }

        return new JsonResponse(['status' => 'sent_if_applicable']);
    }

    private function tryCreateRequestToUser(User $me, ?User $target): void
    {
        if (null === $target || $target === $me) {
            return;
        }

        $active = $this->friendships->findActiveBetween($me, $target);
        if (null !== $active) {
            if (FriendshipStatus::Pending === $active->getStatus() && $active->getRequester() === $target) {
                // They'd already asked us: this mutual interest becomes an
                // immediate friendship instead of a second, redundant request.
                $active->accept();
                $this->em->flush();
            }
            // Otherwise: already friends, or we already asked them — no-op.

            return;
        }

        $latest = $this->friendships->findLatestForDirection($me, $target);
        if (null !== $latest) {
            $exemptFromCooldown = FriendshipStatus::Accepted === $latest->getStatus() && $latest->isDeleted();
            $cooldownEndsAt = $latest->getCreatedAt()->modify('+30 days');
            if (!$exemptFromCooldown && $cooldownEndsAt > new \DateTimeImmutable()) {
                return;
            }
        }

        $this->em->persist(new Friendship($me, $target));
        $this->em->flush();
    }
}
