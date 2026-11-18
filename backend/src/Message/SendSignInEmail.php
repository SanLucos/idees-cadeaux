<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Sign-in without a password (spec §11 décision 68): the code and the
 * link carrying it. Addressed by email, the account may not exist yet.
 */
final class SendSignInEmail
{
    public function __construct(
        public readonly string $email,
        public readonly string $locale,
        public readonly string $code,
        public readonly bool $newAccount,
    ) {
    }
}
