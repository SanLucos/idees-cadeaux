<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Adds a minimal user summary to the login/social-login response so
 * the client can route straight to onboarding without a second
 * round-trip (spec §5.1 onboarding).
 */
#[AsEventListener(event: Events::AUTHENTICATION_SUCCESS)]
final class JwtAuthenticationSuccessListener
{
    public function __invoke(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $data = $event->getData();
        $data['user'] = [
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'isOnboarded' => $user->isOnboarded(),
            'locale' => $user->getLocale(),
        ];
        $event->setData($data);
    }
}
