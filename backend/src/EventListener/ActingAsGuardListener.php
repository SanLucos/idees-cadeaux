<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ApiProblemException;
use App\Security\ActingContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Spec §11 décision 25: acting as a managed profile covers its list,
 * profile and friends — nothing else. Default deny: any path not below
 * is refused with `acting_as.not_allowed`, so a new endpoint never
 * becomes actable by accident. Runs after the firewall (priority 8),
 * and validates the header itself via ActingContext.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
final class ActingAsGuardListener
{
    /** [method regex, path regex] pairs a managed profile may use. */
    private const array ALLOWED = [
        ['GET|PATCH', '#^/api/users/me$#'],
        ['POST', '#^/api/users/me/avatar$#'],
        ['GET', '#^/api/users/[0-9a-f-]{36}$#'],
        // Its list and ideas (suggesting to others is refused in IdeaController).
        ['GET', '#^/api/users/(me|[0-9a-f-]{36})/ideas$#'],
        ['GET', '#^/api/ideas/private$#'],
        ['POST', '#^/api/ideas$#'],
        ['GET|PATCH|DELETE', '#^/api/ideas/[0-9a-f-]{36}$#'],
        ['POST', '#^/api/ideas/[0-9a-f-]{36}/(publish|unpublish|archive|unarchive)$#'],
        ['POST|DELETE', '#^/api/ideas/[0-9a-f-]{36}/image$#'],
        ['GET', '#^/api/occasions$#'],
        // Pre-filling its ideas from a link (spec §5.5).
        ['POST', '#^/api/link-previews$#'],
        // Profile details.
        ['GET|POST', '#^/api/profile_(sizes|preferences)$#'],
        ['GET|PATCH|DELETE', '#^/api/profile_(sizes|preferences)/[0-9a-f-]{36}$#'],
        // Friends (spec §5.15: "gère ses amis").
        ['GET', '#^/api/friendships(/incoming|/outgoing)?$#'],
        ['POST', '#^/api/friendships$#'],
        ['POST', '#^/api/friendships/[0-9a-f-]{36}/(accept|decline|cancel)$#'],
        ['DELETE', '#^/api/friendships/[0-9a-f-]{36}$#'],
        ['POST', '#^/api/contacts/match$#'],
        // Its share link (spec §5.16: "le gestionnaire crée, révoque et
        // régénère le lien"). Joining someone's link stays adult-only.
        ['GET|POST|DELETE', '#^/api/share-link$#'],
        ['POST', '#^/api/share-link/regenerate$#'],
    ];

    public function __construct(private readonly ActingContext $acting)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api') || !$request->headers->has('X-Acting-As')) {
            return;
        }

        // Unauthenticated: let the firewall / access_control answer 401.
        if (null === $this->acting->human()) {
            return;
        }

        $this->acting->actor();

        foreach (self::ALLOWED as [$methods, $path]) {
            if (1 === preg_match('#^('.$methods.')$#', $request->getMethod()) && 1 === preg_match($path, $request->getPathInfo())) {
                return;
            }
        }

        throw new ApiProblemException('acting_as.not_allowed', 'This action is not available on behalf of a managed profile.', 403);
    }
}
