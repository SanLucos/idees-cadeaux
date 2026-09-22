<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Marks a resource as belonging to exactly one user, so
 * App\Doctrine\Extension\OwnedByCurrentUserExtension can scope every
 * API Platform query (collection and item alike) to the current user.
 * An item outside that scope simply isn't found — 404, never 403
 * (CLAUDE.md règle 1's mechanism, reused here even though these
 * resources aren't hidden-from-owner, only owner-only).
 */
interface OwnedEntityInterface
{
    public function getUser(): User;
}
