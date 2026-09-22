<?php

declare(strict_types=1);

namespace App\Security\Social;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;

/**
 * Verifies an OpenID Connect ID token's signature (RS256, against the
 * issuer's published JWKS), issuer, audience and expiry — the "validation
 * côté serveur" spec §2 requires for Google and Apple sign-in. Never
 * trusts a token's claims before all four checks pass.
 */
final class OidcIdTokenVerifier
{
    private readonly JWSVerifier $jwsVerifier;
    private readonly CompactSerializer $serializer;

    public function __construct(private readonly JwksKeySetProvider $jwks)
    {
        $this->jwsVerifier = new JWSVerifier(new AlgorithmManager([new RS256()]));
        $this->serializer = new CompactSerializer();
    }

    /**
     * @param string[] $allowedIssuers
     */
    public function verify(string $idToken, string $jwksUrl, array $allowedIssuers, string $expectedAudience): IdTokenClaims
    {
        try {
            $jws = $this->serializer->unserialize($idToken);
        } catch (\Throwable $e) {
            throw new IdTokenVerificationException('Malformed ID token.', previous: $e);
        }

        $keySet = $this->jwks->get($jwksUrl);
        if (!$this->jwsVerifier->verifyWithKeySet($jws, $keySet, 0)) {
            throw new IdTokenVerificationException('ID token signature verification failed.');
        }

        $payload = json_decode($jws->getPayload() ?? '', true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($payload)) {
            throw new IdTokenVerificationException('Malformed ID token payload.');
        }

        if (!\in_array($payload['iss'] ?? null, $allowedIssuers, true)) {
            throw new IdTokenVerificationException('Unexpected issuer.');
        }

        $audience = $payload['aud'] ?? null;
        $audienceMatches = $audience === $expectedAudience
            || (\is_array($audience) && \in_array($expectedAudience, $audience, true));
        if (!$audienceMatches) {
            throw new IdTokenVerificationException('Unexpected audience.');
        }

        if (($payload['exp'] ?? 0) < time()) {
            throw new IdTokenVerificationException('ID token expired.');
        }

        if (!isset($payload['sub']) || !\is_string($payload['sub'])) {
            throw new IdTokenVerificationException('Missing subject claim.');
        }

        $emailVerifiedRaw = $payload['email_verified'] ?? false;
        $emailVerified = true === $emailVerifiedRaw || 'true' === $emailVerifiedRaw;

        return new IdTokenClaims(
            subject: $payload['sub'],
            email: \is_string($payload['email'] ?? null) ? $payload['email'] : null,
            emailVerified: $emailVerified,
        );
    }
}
