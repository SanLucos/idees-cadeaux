<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Exception\HiddenResourceException;

/**
 * The administration exists for the superadmin only (spec §5.17): for
 * anyone else, every /api/admin endpoint is a 404, as if it didn't exist.
 */
final class AdminGuard
{
    public function check(?User $user): User
    {
        if (null === $user || !$user->isSuperAdmin()) {
            throw new HiddenResourceException();
        }

        return $user;
    }
}
