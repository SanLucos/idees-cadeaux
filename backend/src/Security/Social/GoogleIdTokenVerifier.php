<?php

declare(strict_types=1);

namespace App\Security\Social;

final class GoogleIdTokenVerifier
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    public function __construct(
        private readonly OidcIdTokenVerifier $verifier,
        private readonly string $googleClientId,
    ) {
    }

    public function verify(string $idToken): IdTokenClaims
    {
        return $this->verifier->verify($idToken, self::JWKS_URL, self::ISSUERS, $this->googleClientId);
    }
}
