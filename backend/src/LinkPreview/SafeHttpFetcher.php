<?php

declare(strict_types=1);

namespace App\LinkPreview;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a user-supplied URL without letting it reach our own network
 * (spec §5.5, CLAUDE.md règle 10):
 *
 * - http/https only, ports 80/443 only, no credentials in the URL;
 * - the host is resolved, then any private, loopback, link-local or
 *   otherwise reserved address is refused; the connection is pinned to
 *   the address that was checked (NoPrivateNetworkHttpClient), so a
 *   second DNS answer can't swap it for an internal one;
 * - redirects are followed here, by hand, at most 3, and every hop goes
 *   through the same checks;
 * - ~5 s for the whole exchange, body capped (2 MB for a page).
 */
final class SafeHttpFetcher
{
    public const int MAX_REDIRECTS = 3;
    public const float TIMEOUT_SECONDS = 5.0;

    private readonly HttpClientInterface $client;

    public function __construct(
        #[Autowire(service: 'link_preview.transport')]
        HttpClientInterface $transport,
        #[Autowire('%env(APP_NAME)%')]
        private readonly string $appName,
    ) {
        $this->client = new NoPrivateNetworkHttpClient($transport);
    }

    /**
     * @param list<string> $acceptedTypes content-type prefixes, e.g. ['text/html']
     *
     * @throws LinkPreviewException
     */
    public function fetch(string $url, array $acceptedTypes, int $maxBytes, string $acceptLanguage = 'fr'): FetchedResource
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        try {
            for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
                self::assertAllowedUrl($url);

                $response = $this->client->request('GET', $url, [
                    'max_redirects' => 0,
                    'timeout' => self::remaining($deadline),
                    'max_duration' => self::remaining($deadline),
                    'headers' => [
                        'User-Agent' => \sprintf('Mozilla/5.0 (compatible; %sLinkPreview/1.0)', preg_replace('/[^A-Za-z0-9]/', '', $this->appName)),
                        'Accept' => implode(', ', array_map(static fn (string $type): string => $type.(str_ends_with($type, '/') ? '*' : ''), $acceptedTypes)).', */*;q=0.1',
                        'Accept-Language' => $acceptLanguage,
                    ],
                ]);

                $status = $response->getStatusCode();
                if ($status >= 300 && $status < 400) {
                    $location = $response->getHeaders(false)['location'][0] ?? null;
                    $response->cancel();
                    if (null === $location) {
                        throw new LinkPreviewException('Redirect without a location.');
                    }
                    $url = UrlResolver::resolve($location, $url) ?? throw new LinkPreviewException('Unusable redirect.');

                    continue;
                }
                if ($status >= 400) {
                    $response->cancel();
                    throw new LinkPreviewException(\sprintf('HTTP %d.', $status));
                }

                $headers = $response->getHeaders(false);
                $contentType = strtolower(trim(explode(';', $headers['content-type'][0] ?? '')[0]));
                if (!self::isAccepted($contentType, $acceptedTypes)) {
                    $response->cancel();
                    throw new LinkPreviewException('Unexpected content type.');
                }
                if ((int) ($headers['content-length'][0] ?? 0) > $maxBytes) {
                    $response->cancel();
                    throw new LinkPreviewException('Response too large.');
                }

                $body = '';
                foreach ($this->client->stream($response, self::remaining($deadline)) as $chunk) {
                    if ($chunk->isTimeout()) {
                        $response->cancel();
                        throw new LinkPreviewException('Timed out.');
                    }
                    $body .= $chunk->getContent();
                    if (\strlen($body) > $maxBytes) {
                        $response->cancel();
                        throw new LinkPreviewException('Response too large.');
                    }
                }

                return new FetchedResource($url, $headers['content-type'][0] ?? $contentType, $body);
            }
        } catch (ExceptionInterface $e) {
            throw new LinkPreviewException('Fetch failed.', 0, $e);
        }

        throw new LinkPreviewException('Too many redirects.');
    }

    /** Scheme, port and credentials — the host's address is checked at connection time. */
    public static function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (false === $parts || !\in_array($scheme, ['http', 'https'], true) || '' === ($parts['host'] ?? '')) {
            throw new LinkPreviewException('Only http and https URLs are allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new LinkPreviewException('Credentials in URLs are not allowed.');
        }
        if (isset($parts['port']) && !\in_array($parts['port'], [80, 443], true)) {
            throw new LinkPreviewException('Only ports 80 and 443 are allowed.');
        }
    }

    /** @param list<string> $acceptedTypes */
    private static function isAccepted(string $contentType, array $acceptedTypes): bool
    {
        foreach ($acceptedTypes as $accepted) {
            if (str_ends_with($accepted, '/') ? str_starts_with($contentType, $accepted) : $contentType === $accepted) {
                return true;
            }
        }

        return false;
    }

    private static function remaining(float $deadline): float
    {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            throw new LinkPreviewException('Timed out.');
        }

        return $left;
    }
}
