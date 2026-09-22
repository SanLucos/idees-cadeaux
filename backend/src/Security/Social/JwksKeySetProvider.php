<?php

declare(strict_types=1);

namespace App\Security\Social;

use Jose\Component\Core\JWKSet;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches and caches a provider's public JWKS (Google/Apple ID token
 * signature verification, spec §2: "validation côté serveur des ID
 * tokens Google et Apple"). Cached for an hour so a login doesn't
 * refetch the key set on every request; providers rotate keys rarely
 * and always keep the previous one available for a transition period.
 */
final class JwksKeySetProvider
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function get(string $jwksUrl): JWKSet
    {
        $item = $this->cache->getItem('jwks_'.hash('sha256', $jwksUrl));
        if ($item->isHit()) {
            /** @var string $json */
            $json = $item->get();

            return JWKSet::createFromJson($json);
        }

        $json = $this->httpClient->request('GET', $jwksUrl)->getContent();

        $item->set($json);
        $item->expiresAfter(3600);
        $this->cache->save($item);

        return JWKSet::createFromJson($json);
    }
}
