<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * One person's answer to a team introduction (spec §5.17, §11 décision
 * 71). The other person never learns it: « Ignorer » is a silent refusal.
 */
enum IntroductionAnswer: string
{
    case Accepted = 'accepted';
    case Ignored = 'ignored';
}
