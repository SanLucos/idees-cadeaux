<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Spec §5.4: a private idea (brouillon) is visible to its author only,
 * everywhere; published is the default.
 */
enum IdeaVisibility: string
{
    case Private = 'private';
    case Published = 'published';
}
