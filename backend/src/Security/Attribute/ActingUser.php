<?php

declare(strict_types=1);

namespace App\Security\Attribute;

use App\ArgumentResolver\ActingUserResolver;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;

/**
 * Injects App\Security\ActingContext::actor() — the managed profile
 * named by `X-Acting-As`, or the authenticated user. Use it instead of
 * #[CurrentUser] on every endpoint App\EventListener\ActingAsGuardListener
 * allows while acting. Targets its resolver explicitly so Doctrine's
 * EntityValueResolver never tries to load the User from a route `{id}`.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class ActingUser extends ValueResolver
{
    public function __construct()
    {
        parent::__construct(ActingUserResolver::class);
    }
}
