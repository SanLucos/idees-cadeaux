<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Account-life emails (spec §5.13): deletion requested (with its cancel
 * link), account or child profile deleted, export ready. Self-contained:
 * the account may no longer exist when it is sent.
 */
final class SendAccountEmail
{
    /**
     * @param array<string, string> $params translation parameters (%date%, %profile%…)
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $email,
        public readonly string $locale,
        public readonly array $params = [],
        public readonly ?string $actionUrl = null,
        public readonly bool $aboutProfile = false,
    ) {
    }
}
