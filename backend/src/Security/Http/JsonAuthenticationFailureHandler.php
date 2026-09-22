<?php

declare(strict_types=1);

namespace App\Security\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Gesdinet\JWTRefreshTokenBundle\Security\Exception\InvalidTokenException;
use Gesdinet\JWTRefreshTokenBundle\Security\Exception\MissingTokenException;
use Gesdinet\JWTRefreshTokenBundle\Security\Exception\TokenNotFoundException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Keeps login and refresh failures in the same problem+json shape as
 * the rest of the API (CLAUDE.md règle 7) instead of Lexik's or
 * Gesdinet's own {code,message} shape. Shared by the `login` and
 * `refresh` firewalls (config/packages/security.yaml).
 */
final class JsonAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        [$status, $code, $detail] = match (true) {
            $exception instanceof CustomUserMessageAccountStatusException => [403, $exception->getMessageKey(), 'Email address not verified.'],
            $exception instanceof TooManyLoginAttemptsAuthenticationException => [429, 'auth.too_many_attempts', 'Too many login attempts.'],
            $exception instanceof MissingTokenException,
            $exception instanceof TokenNotFoundException,
            $exception instanceof InvalidTokenException => [401, 'auth.refresh_token_invalid', 'Refresh token is missing, invalid or expired.'],
            default => [401, 'auth.invalid_credentials', 'Invalid credentials.'],
        };

        return new JsonResponse(
            [
                'type' => 'about:blank',
                'title' => $code,
                'status' => $status,
                'detail' => $detail,
                'code' => $code,
            ],
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
