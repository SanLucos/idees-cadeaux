<?php

declare(strict_types=1);

namespace App\Security\Social;

/**
 * The subset of an ID token's claims we actually use, after signature,
 * issuer, audience and expiry have all been verified.
 */
final class IdTokenClaims
{
    public function __construct(
        public readonly string $subject,
        public readonly ?string $email,
        public readonly bool $emailVerified,
    ) {
    }
}
