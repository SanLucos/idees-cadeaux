<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Blocks login before the email is verified (spec §5.1). Managed
 * profiles (spec §5.15) never authenticate directly, so they can't
 * reach here through the login firewall regardless.
 */
final class AppUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && null === $user->getEmailVerifiedAt()) {
            throw new CustomUserMessageAccountStatusException('auth.email_not_verified');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
