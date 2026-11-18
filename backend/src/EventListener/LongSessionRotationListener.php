<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\RefreshToken;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Security\Http\Authenticator\Token\PostRefreshTokenAuthenticationToken;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * A long session (sign-in by emailed code, spec §11 décision 68) stays
 * long: when /api/auth/refresh rotates its token, gesdinet issues the
 * replacement with the usual ~30 days; this extends it to a long one
 * again. Runs after gesdinet's listener (priority 0).
 */
#[AsEventListener(event: Events::AUTHENTICATION_SUCCESS, priority: -10)]
final class LongSessionRotationListener
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RefreshTokenManagerInterface $refreshTokens,
        private readonly int $longSessionTtl,
    ) {
    }

    public function __invoke(AuthenticationSuccessEvent $event): void
    {
        $token = $this->tokenStorage->getToken();
        if (!$token instanceof PostRefreshTokenAuthenticationToken) {
            return;
        }
        $consumed = $token->getRefreshToken();
        if (!$consumed instanceof RefreshToken || !$consumed->isLongSession()) {
            return;
        }

        $value = $event->getData()['refresh_token'] ?? null;
        $issued = \is_string($value) ? $this->refreshTokens->get($value) : null;
        if (!$issued instanceof RefreshToken) {
            return;
        }

        $issued->setLongSession(true);
        $issued->setValid(new \DateTime('+'.$this->longSessionTtl.' seconds'));
        $this->refreshTokens->save($issued);
    }
}
