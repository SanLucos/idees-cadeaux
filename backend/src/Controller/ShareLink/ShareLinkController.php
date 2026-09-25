<?php

declare(strict_types=1);

namespace App\Controller\ShareLink;

use App\Entity\ShareLink;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\ShareLinkRepository;
use App\Security\ActingContext;
use App\Security\Attribute\ActingUser;
use App\Service\ShareLinks;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Partager mon profil » (spec §5.16), the owner's side: my link — or,
 * with X-Acting-As, my child's, managed by me. Sharing stays off until
 * a link exists; creating one needs the explicit confirmation the app
 * shows (`confirmed: true`).
 */
final class ShareLinkController
{
    public function __construct(
        private readonly ShareLinkRepository $links,
        private readonly ShareLinks $service,
        private readonly ActingContext $acting,
        private readonly string $shareLinkBaseUrl,
    ) {
    }

    #[Route('/api/share-link', name: 'share_link_get', methods: ['GET'])]
    public function get(#[ActingUser] User $me): JsonResponse
    {
        return new JsonResponse(['link' => $this->normalize($this->links->findForOwner($me))]);
    }

    #[Route('/api/share-link', name: 'share_link_create', methods: ['POST'])]
    public function create(Request $request, #[ActingUser] User $me): JsonResponse
    {
        $existing = $this->links->findForOwner($me);
        if (null !== $existing) {
            return new JsonResponse(['link' => $this->normalize($existing)]);
        }

        $body = json_decode($request->getContent(), true);
        if (true !== (\is_array($body) ? ($body['confirmed'] ?? null) : null)) {
            throw new ApiProblemException('share_link.confirmation_required', 'Creating a share link must be confirmed.', 422);
        }

        return new JsonResponse(['link' => $this->normalize($this->service->create($me, $this->acting->human()))], 201);
    }

    #[Route('/api/share-link/regenerate', name: 'share_link_regenerate', methods: ['POST'])]
    public function regenerate(#[ActingUser] User $me): JsonResponse
    {
        $current = $this->links->findForOwner($me)
            ?? throw new ApiProblemException('resource.not_found', 'No share link to regenerate.', 404);

        return new JsonResponse(['link' => $this->normalize($this->service->regenerate($current, $this->acting->human()))]);
    }

    #[Route('/api/share-link', name: 'share_link_disable', methods: ['DELETE'])]
    public function disable(#[ActingUser] User $me): Response
    {
        $this->service->disable($me);

        return new Response(null, 204);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalize(?ShareLink $link): ?array
    {
        if (null === $link) {
            return null;
        }

        return [
            'id' => $link->getId()->toRfc4122(),
            'token' => $link->getToken(),
            'url' => rtrim($this->shareLinkBaseUrl, '/').'/u/'.$link->getToken(),
            'status' => $link->isSuspended() ? 'suspended' : 'active',
            'joinCount' => $link->getJoinCount(),
            'createdAt' => $link->getCreatedAt()->format(\DATE_ATOM),
            'suspendedAt' => $link->getSuspendedAt()?->format(\DATE_ATOM),
        ];
    }
}
