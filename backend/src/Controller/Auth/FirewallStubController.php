<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Exception\ApiProblemException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * /api/auth/login and /api/auth/refresh are handled entirely by the
 * `login` and `refresh` firewalls (json_login / refresh-jwt
 * authenticators, config/packages/security.yaml): the firewall listener
 * intercepts and short-circuits the request during kernel.request,
 * before kernel.controller ever runs, so this method is normally never
 * actually invoked. The route still has to exist and accept POST,
 * because the router (which also listens on kernel.request, but before
 * the firewall) 404s/405s unmatched paths immediately and never gives
 * the firewall a chance to run at all. Reaching this code for real
 * means the firewall failed to intercept — a configuration bug, not a
 * normal auth failure.
 */
final class FirewallStubController
{
    #[Route('/api/auth/login', name: 'auth_login_stub', methods: ['POST'])]
    #[Route('/api/auth/refresh', name: 'auth_refresh_stub', methods: ['POST'])]
    public function __invoke(): never
    {
        throw new ApiProblemException('auth.required', 'Authentication required.', 401);
    }
}
