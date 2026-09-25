<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ApiProblemException;
use App\Security\ActingContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Spec §5.13: during the 14 days of grace "la connexion est impossible
 * sauf pour annuler la suppression". Signing in still works, but the
 * account only reaches what the « Votre compte sera supprimé le … »
 * screen needs: itself, cancelling, exporting its data, signing out.
 * Runs after the firewall (priority 8).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
final class SuspendedAccountGuardListener
{
    /** [method, path regex] pairs a suspended account may use. */
    private const array ALLOWED = [
        ['GET', '#^/api/users/me$#'],
        ['POST', '#^/api/account/(cancel-deletion|reauth-code|export)$#'],
        ['POST', '#^/api/auth/logout$#'],
    ];

    public function __construct(private readonly ActingContext $acting)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $scheduled = $this->acting->human()?->getDeletionScheduledAt();
        if (null === $scheduled) {
            return;
        }

        foreach (self::ALLOWED as [$method, $path]) {
            if ($request->getMethod() === $method && 1 === preg_match($path, $request->getPathInfo())) {
                return;
            }
        }

        throw new ApiProblemException('account.deletion_scheduled', 'This account is scheduled for deletion.', 403, extra: ['deletionScheduledAt' => $scheduled->format(\DATE_ATOM)]);
    }
}
