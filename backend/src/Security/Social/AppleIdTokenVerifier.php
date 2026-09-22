<?php

declare(strict_types=1);

namespace App\Security\Social;

/**
 * Apple's private-relay email (when the user hides their real
 * address) is a real, working forwarding address, so it needs no
 * special handling beyond accepting whatever email the token carries
 * (spec §5.1 "gérer le relais d'email privé d'Apple").
 */
final class AppleIdTokenVerifier
{
    private const JWKS_URL = 'https://appleid.apple.com/auth/keys';
    private const ISSUERS = ['https://appleid.apple.com'];

    public function __construct(
        private readonly OidcIdTokenVerifier $verifier,
        private readonly string $appleClientId,
    ) {
    }

    public function verify(string $idToken): IdTokenClaims
    {
        return $this->verifier->verify($idToken, self::JWKS_URL, self::ISSUERS, $this->appleClientId);
    }
}
