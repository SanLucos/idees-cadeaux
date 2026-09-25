<?php

declare(strict_types=1);

namespace App\Controller\ShareLink;

use App\Dto\IdeaListFilter;
use App\Exception\ApiProblemException;
use App\Repository\IdeaRepository;
use App\Serializer\IdeaNormalizer;
use App\Service\GuestMediaUrls;
use App\Service\GuestView;
use App\Service\ShareLinks;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Twig\Environment;

/**
 * The light web page behind `https://<domaine>/u/<token>` (spec §5.16
 * « Deux surfaces », 1): the guest view rendered server-side, read-only,
 * no sign-in on the web. Not indexed, not cached, strict CSP (no script
 * at all), no referrer. Its Open Graph preview names the owner and
 * nothing else — never an idea.
 *
 * Also serves the guest view's signed, short-lived images.
 */
final class GuestPageController
{
    public function __construct(
        private readonly ShareLinks $service,
        private readonly GuestView $guestView,
        private readonly GuestMediaUrls $media,
        private readonly IdeaRepository $ideas,
        #[Autowire(service: 'default.storage')]
        private readonly FilesystemOperator $storage,
        private readonly Environment $twig,
        #[Autowire(service: 'limiter.share_link_public')]
        private readonly RateLimiterFactory $publicLimiter,
        #[Autowire(service: 'limiter.share_link_media')]
        private readonly RateLimiterFactory $mediaLimiter,
        private readonly string $appId,
        private readonly string $appStoreUrl,
        private readonly string $playStoreUrl,
    ) {
    }

    #[Route('/u/{token}', name: 'share_link_page', methods: ['GET'])]
    public function page(string $token, Request $request): Response
    {
        $locale = $request->getPreferredLanguage(['fr', 'en']) ?? 'fr';
        $nonce = base64_encode(random_bytes(16));

        if (!$this->publicLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $this->render('share/invalid.html.twig', ['locale' => $locale, 'nonce' => $nonce, 'rate_limited' => true], 429, $nonce);
        }

        try {
            $link = $this->service->resolve($token);
            $filter = IdeaListFilter::fromRequest($request);
        } catch (ApiProblemException) {
            return $this->render('share/invalid.html.twig', ['locale' => $locale, 'nonce' => $nonce, 'rate_limited' => false], 404, $nonce);
        }

        $view = $this->guestView->build($link, $filter);
        $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        foreach ($view['member'] as &$idea) {
            // Whole amounts without decimals, as in the app (« 120 € »).
            $amount = null !== $idea['priceAmount'] ? (float) $idea['priceAmount'] : null;
            $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, null !== $amount && floor($amount) === $amount ? 0 : 2);
            $idea['price'] = null !== $amount ? $formatter->formatCurrency($amount, $idea['priceCurrency'] ?? 'EUR') : null;
        }
        unset($idea);

        return $this->render('share/guest.html.twig', [
            'locale' => $locale,
            'nonce' => $nonce,
            'view' => $view,
            'filter' => $filter,
            'token' => $link->getToken(),
            'open_in_app_url' => $this->appId.'://u/'.$link->getToken(),
            'app_store_url' => $this->appStoreUrl,
            'play_store_url' => $this->playStoreUrl,
            'has_more' => $filter->page * $filter->itemsPerPage < $view['totalItems'],
        ], 200, $nonce);
    }

    #[Route('/u/{token}/media/{kind}/{id}', name: 'share_link_media', methods: ['GET'], requirements: ['kind' => 'avatar|image|thumb', 'id' => Requirement::UUID])]
    public function media(string $token, string $kind, string $id, Request $request): Response
    {
        if (!$this->mediaLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new Response('', 429);
        }

        $notFound = new Response('', 404, ['Cache-Control' => 'no-store']);
        if (!$this->media->isValid($token, $kind, $id, $request->query->getInt('e'), (string) $request->query->get('s', ''))) {
            return $notFound;
        }

        try {
            $link = $this->service->resolve($token);
        } catch (ApiProblemException) {
            return $notFound;
        }
        $owner = $link->getOwner();

        if ('avatar' === $kind) {
            $path = $owner->getId()->toRfc4122() === $id ? $owner->getAvatarPath() : null;
        } else {
            // Re-checked, not trusted from the signature alone: the idea
            // must still be in the guest view (published, personal, active).
            $idea = $this->ideas->find($id);
            $visible = null !== $idea && $idea->getOwner() === $owner && $idea->getAuthor() === $owner
                && $idea->isPublished() && !$idea->isArchived() && null !== $idea->getImagePath();
            $path = $visible ? ('thumb' === $kind ? IdeaNormalizer::thumbnailPath((string) $idea->getImagePath()) : $idea->getImagePath()) : null;
        }

        try {
            if (null === $path || !$this->storage->fileExists($path)) {
                return $notFound;
            }
            $stream = $this->storage->readStream($path);
            $mimeType = $this->storage->mimeType($path);
        } catch (FilesystemException) {
            return $notFound;
        }

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, max-age='.GuestMediaUrls::TTL,
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context, int $status, string $nonce): Response
    {
        $response = new Response($this->twig->render($template, $context), $status);
        $headers = $response->headers;
        $headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
        $headers->set('Cache-Control', 'no-store, private');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
