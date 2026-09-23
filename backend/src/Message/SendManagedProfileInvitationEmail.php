<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Spec §5.15 "rattacher un email": sent to an address that has no
 * account yet, so everything the email needs travels in the message.
 */
final class SendManagedProfileInvitationEmail
{
    public function __construct(
        public readonly string $email,
        public readonly string $code,
        public readonly string $profileName,
        public readonly string $managerName,
        public readonly string $locale,
    ) {
    }
}
