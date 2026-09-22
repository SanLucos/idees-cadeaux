<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum VerificationCodePurpose: string
{
    case VerifyEmail = 'verify_email';
    case ResetPassword = 'reset_password';
}
