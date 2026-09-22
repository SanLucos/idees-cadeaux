<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * regular: autonomous account (email + password and/or social login).
 * managed: profil enfant (spec §5.15) — no email, no login, acted on
 * only by its managedBy via the X-Acting-As header.
 */
enum UserType: string
{
    case Regular = 'regular';
    case Managed = 'managed';
}
