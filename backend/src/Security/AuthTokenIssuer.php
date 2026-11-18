<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\RefreshToken;
use App\Entity\User;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * Issues an access + refresh token pair outside the login firewall
 * (social login, spec §5.1), in the same shape as a password login's
 * response (App\EventListener\JwtAuthenticationSuccessListener).
 * A long session (sign-in by emailed code, spec §11 décision 68) gets a
 * refresh token of $longSessionTtl instead of the usual ~30 days.
 */
final class AuthTokenIssuer
{
    public function __construct(
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RefreshTokenGeneratorInterface $refreshTokenGenerator,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly int $refreshTokenTtl,
        private readonly int $longSessionTtl,
    ) {
    }

    /**
     * @return array{token: string, refresh_token: string, user: array<string, mixed>}
     */
    public function issue(User $user, bool $longSession = false): array
    {
        $refreshToken = $this->refreshTokenGenerator->createForUserWithTtl($user, $longSession ? $this->longSessionTtl : $this->refreshTokenTtl);
        if ($refreshToken instanceof RefreshToken) {
            $refreshToken->setLongSession($longSession);
        }
        $this->refreshTokenManager->save($refreshToken);

        return [
            'token' => $this->jwtManager->create($user),
            'refresh_token' => $refreshToken->getRefreshToken(),
            'user' => [
                'id' => $user->getId()->toRfc4122(),
                'email' => $user->getEmail(),
                'displayName' => $user->getDisplayName(),
                'isOnboarded' => $user->isOnboarded(),
                'locale' => $user->getLocale(),
            ],
        ];
    }
}
