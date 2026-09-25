<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum VerificationCodePurpose: string
{
    case VerifyEmail = 'verify_email';
    case ResetPassword = 'reset_password';
    /** Spec §5.13: before an export or an account deletion, for accounts without a password. */
    case Reauthenticate = 'reauthenticate';
}
