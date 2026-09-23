<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/** Spec §5.9: a single "j'aime" in v1. */
enum ReactionType: string
{
    case Like = 'like';
}
