<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\User;
use App\EventListener\WebSessionCookieListener;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * POST /api/auth/logout (spec §5.1): revokes this device's refresh
 * token. The short-lived access token itself is left to expire — spec
 * only asks for revocation "à la déconnexion" on the refresh token.
 * A browser's token comes from its cookie, which is then cleared
 * (spec §11 décision 56).
 */
final class LogoutController
{
    public function __construct(private readonly RefreshTokenManagerInterface $refreshTokens)
    {
    }

    #[Route('/api/auth/logout', name: 'auth_logout', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $refreshTokenString = \is_array($body) ? (string) ($body['refresh_token'] ?? '') : '';
        if ('' === $refreshTokenString) {
            $refreshTokenString = (string) $request->cookies->get(WebSessionCookieListener::COOKIE, '');
        }

        if ('' !== $refreshTokenString) {
            $refreshToken = $this->refreshTokens->get($refreshTokenString);
            if (null !== $refreshToken && $refreshToken->getUsername() === $user->getUserIdentifier()) {
                $this->refreshTokens->delete($refreshToken);
            }
        }

        $response = new JsonResponse(['status' => 'logged_out']);
        if (WebSessionCookieListener::isWeb($request)) {
            $response->headers->setCookie(WebSessionCookieListener::cookie($request, '', 0));
        }

        return $response;
    }
}
