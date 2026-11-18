<?php

declare(strict_types=1);

namespace App\EventListener;

use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Web sessions (spec §11 décision 56): for a browser (`X-Client: web`),
 * the refresh token never reaches the JavaScript. Every auth response that
 * carries one (login, refresh, social login, managed-profile takeover) has
 * it moved into an httpOnly, SameSite=Strict cookie scoped to /api/auth;
 * the refresh firewall reads it back from there (gesdinet's cookie
 * extractor, parameter `refresh_token`). The phone apps keep receiving it
 * in the body and storing it in the Keychain / Keystore. The cookie ends
 * with its token, a long session's included (spec §11 décision 68).
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class WebSessionCookieListener
{
    public const string CLIENT_HEADER = 'X-Client';
    public const string COOKIE = 'refresh_token';
    public const string PATH = '/api/auth';

    public function __construct(
        private readonly int $refreshTokenTtl,
        private readonly RefreshTokenManagerInterface $refreshTokens,
    ) {
    }

    public static function isWeb(Request $request): bool
    {
        return 'web' === $request->headers->get(self::CLIENT_HEADER);
    }

    public static function cookie(Request $request, string $value, int $ttl): Cookie
    {
        return Cookie::create(self::COOKIE)
            ->withValue($value)
            ->withExpires(0 === $ttl ? 1 : time() + $ttl)
            ->withPath(self::PATH)
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$event->isMainRequest() || !self::isWeb($request) || !str_starts_with($request->getPathInfo(), self::PATH.'/')
            || !$response instanceof JsonResponse || $response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
            return;
        }

        $data = json_decode((string) $response->getContent(), true);
        if (!\is_array($data) || !\is_string($data['refresh_token'] ?? null)) {
            return;
        }

        $expiresAt = $this->refreshTokens->get($data['refresh_token'])?->getValid()?->getTimestamp();
        $ttl = null !== $expiresAt ? max(1, $expiresAt - time()) : $this->refreshTokenTtl;
        $response->headers->setCookie(self::cookie($request, $data['refresh_token'], $ttl));
        unset($data['refresh_token']);
        $response->setData($data);
    }
}
