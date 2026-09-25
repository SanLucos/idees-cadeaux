<?php

declare(strict_types=1);

namespace App\Controller\ShareLink;

use App\Dto\IdeaListFilter;
use App\Entity\ShareLink;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Service\GuestView;
use App\Service\ShareLinks;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * A share link seen from the other side (spec §5.16):
 *
 * - GET  /api/share-links/{token}: the guest view, public (the app's
 *   guest mode, and the owner's « Aperçu de la vue invité »);
 * - GET  /api/share-links/{token}/invitation: the confirmation screen
 *   of a signed-in adult (« Devenir ami avec X ? »);
 * - POST /api/share-links/{token}/join: the confirmed friendship.
 *
 * Never on behalf of a child (ActingAsGuardListener): only adult
 * accounts join. Rate-limited; every refusal is `share_link.invalid`.
 */
final class JoinViaLinkController
{
    public function __construct(
        private readonly ShareLinks $service,
        private readonly GuestView $guestView,
        #[Autowire(service: 'limiter.share_link_public')]
        private readonly RateLimiterFactory $publicLimiter,
        #[Autowire(service: 'limiter.share_link_join')]
        private readonly RateLimiterFactory $joinLimiter,
    ) {
    }

    #[Route('/api/share-links/{token}', name: 'share_link_guest_view', methods: ['GET'])]
    public function guestView(string $token, Request $request): JsonResponse
    {
        $this->limit($this->publicLimiter, $request->getClientIp() ?? 'unknown');
        $link = $this->service->resolve($token);

        $response = new JsonResponse($this->guestView->build($link, IdeaListFilter::fromRequest($request), $request->getSchemeAndHttpHost()));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/api/share-links/{token}/invitation', name: 'share_link_invitation', methods: ['GET'])]
    public function invitation(string $token, #[CurrentUser] User $me, Request $request): JsonResponse
    {
        $this->limit($this->joinLimiter, $me->getId()->toRfc4122());
        $link = $this->service->resolve($token);

        return new JsonResponse($this->describe($link, $this->service->relation($link, $me), $request));
    }

    #[Route('/api/share-links/{token}/join', name: 'share_link_join', methods: ['POST'])]
    public function join(string $token, #[CurrentUser] User $me, Request $request): JsonResponse
    {
        $this->limit($this->joinLimiter, $me->getId()->toRfc4122());
        $link = $this->service->resolve($token);
        $result = $this->service->join($link, $me);

        return new JsonResponse($this->describe($link, $result['relation'], $request) + [
            'friendshipId' => $result['friendship']?->getId()->toRfc4122(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(ShareLink $link, string $relation, Request $request): array
    {
        $owner = $link->getOwner();
        return [
            'relation' => $relation,
            'owner' => [
                'id' => $owner->getId()->toRfc4122(),
                'displayName' => $owner->getDisplayName(),
                'avatarUrl' => $this->guestView->avatarUrl($link, $request->getSchemeAndHttpHost()),
                'isManaged' => $owner->isManaged(),
                // « profil géré par [gestionnaire] » (spec §5.16).
                'managedBy' => $owner->isManaged() ? ['displayName' => $owner->getManagedBy()?->getDisplayName()] : null,
            ],
        ];
    }

    private function limit(RateLimiterFactory $factory, string $key): void
    {
        if (!$factory->create($key)->consume()->isAccepted()) {
            throw new ApiProblemException('request.rate_limited', 'Too many requests.', 429);
        }
    }
}
