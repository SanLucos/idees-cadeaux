<?php

declare(strict_types=1);

namespace App\Message;

use App\Entity\Enum\VerificationCodePurpose;

final class SendVerificationCodeEmail
{
    public function __construct(
        public readonly string $userId,
        public readonly VerificationCodePurpose $purpose,
        public readonly string $code,
    ) {
    }
}
