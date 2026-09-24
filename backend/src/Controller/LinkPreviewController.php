<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\ApiProblemException;
use App\LinkPreview\LinkPreviewException;
use App\LinkPreview\LinkPreviewService;
use App\LinkPreview\SafeHttpFetcher;
use App\Security\ActingContext;
use App\Security\Attribute\ActingUser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/link-previews (spec §5.5): `{url}` → proposed title, price
 * and image. All null when the page says nothing usable (the client
 * falls back to manual entry); `link_preview.unavailable` when the page
 * can't be fetched at all, whatever the reason — the same answer for a
 * refused internal address as for a dead site.
 */
final class LinkPreviewController
{
    private const int URL_MAX_LENGTH = 2048;

    public function __construct(
        private readonly LinkPreviewService $previews,
        #[Autowire(service: 'limiter.link_preview')]
        private readonly RateLimiterFactory $rateLimiter,
        private readonly ActingContext $acting,
    ) {
    }

    #[Route('/api/link-previews', name: 'link_preview', methods: ['POST'])]
    public function __invoke(Request $request, #[ActingUser] User $me): JsonResponse
    {
        $human = $this->acting->human() ?? $me;
        if (!$this->rateLimiter->create($human->getId()->toRfc4122())->consume()->isAccepted()) {
            throw new ApiProblemException('request.rate_limited', 'Too many link previews.', 429);
        }

        $body = json_decode($request->getContent(), true);
        $url = \is_array($body) && \is_string($body['url'] ?? null) ? trim($body['url']) : '';
        try {
            if ('' === $url || \strlen($url) > self::URL_MAX_LENGTH) {
                throw new LinkPreviewException('Missing or oversized URL.');
            }
            SafeHttpFetcher::assertAllowedUrl($url);
        } catch (LinkPreviewException) {
            throw new ApiProblemException('link_preview.invalid_url', 'The link must be an http or https URL.', 422);
        }

        try {
            return new JsonResponse(['url' => $url, ...$this->previews->preview($url, $human->getLocale())]);
        } catch (LinkPreviewException) {
            throw new ApiProblemException('link_preview.unavailable', 'The page could not be read.', 422);
        }
    }
}
