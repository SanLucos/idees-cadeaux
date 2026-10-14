<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\BrowserKit\Cookie;

/**
 * Web sessions (spec §11 décision 56): a browser (`X-Client: web`) gets
 * its refresh token in an httpOnly cookie, never in a response body; the
 * phone apps keep it in the body.
 */
final class WebSessionTest extends AuthTestCase
{
    private const array WEB = ['X-Client' => 'web'];

    public function testABrowserGetsTheRefreshTokenInAnHttpOnlyCookieOnly(): void
    {
        $this->registerAndVerify('ws-web@example.com');

        $response = static::createClient()->request('POST', '/api/auth/login', [
            'headers' => self::WEB,
            'json' => ['email' => 'ws-web@example.com', 'password' => 'correcthorsebattery'],
        ]);
        self::assertSame(200, $response->getStatusCode());
        $body = $response->toArray();
        self::assertArrayHasKey('token', $body);
        self::assertArrayNotHasKey('refresh_token', $body, 'never readable by the JavaScript');

        $cookie = $this->refreshCookie($response->getHeaders()['set-cookie'] ?? []);
        self::assertNotNull($cookie);
        self::assertStringContainsStringIgnoringCase('httponly', $cookie['raw']);
        self::assertStringContainsStringIgnoringCase('samesite=strict', $cookie['raw']);
        self::assertStringContainsString('path=/api/auth', $cookie['raw']);

        // Refresh with the cookie alone, as a browser would send it back.
        $browser = static::createClient();
        $browser->getCookieJar()->set(new Cookie('refresh_token', $cookie['value'], null, '/api/auth', 'localhost'));
        $refreshed = $browser->request('POST', '/api/auth/refresh', ['headers' => self::WEB]);
        self::assertSame(200, $refreshed->getStatusCode());
        self::assertArrayNotHasKey('refresh_token', $refreshed->toArray());
        $rotated = $this->refreshCookie($refreshed->getHeaders()['set-cookie'] ?? []);
        self::assertNotNull($rotated);
        self::assertNotSame($cookie['value'], $rotated['value']);

        // The old one is single use.
        $replay = static::createClient();
        $replay->getCookieJar()->set(new Cookie('refresh_token', $cookie['value'], null, '/api/auth', 'localhost'));
        $replay->request('POST', '/api/auth/refresh', ['headers' => self::WEB]);
        self::assertResponseStatusCodeSame(401);

        // Logout revokes the cookie's token and clears the cookie.
        $logout = $browser->request('POST', '/api/auth/logout', ['auth_bearer' => $refreshed->toArray()['token'], 'headers' => self::WEB, 'json' => []]);
        self::assertSame(200, $logout->getStatusCode());
        $cleared = $this->refreshCookie($logout->getHeaders()['set-cookie'] ?? []);
        self::assertNotNull($cleared);
        self::assertSame('', $cleared['value']);
        $after = static::createClient();
        $after->getCookieJar()->set(new Cookie('refresh_token', $rotated['value'], null, '/api/auth', 'localhost'));
        $after->request('POST', '/api/auth/refresh', ['headers' => self::WEB]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testThePhoneAppsStillGetItInTheBody(): void
    {
        $this->registerAndVerify('ws-app@example.com');

        $response = static::createClient()->request('POST', '/api/auth/login', ['json' => ['email' => 'ws-app@example.com', 'password' => 'correcthorsebattery']]);
        self::assertArrayHasKey('refresh_token', $response->toArray());
        self::assertNull($this->refreshCookie($response->getHeaders()['set-cookie'] ?? []));
    }

    /**
     * @param string[] $headers
     *
     * @return array{value: string, raw: string}|null
     */
    private function refreshCookie(array $headers): ?array
    {
        foreach ($headers as $raw) {
            if (str_starts_with($raw, 'refresh_token=')) {
                $value = substr($raw, \strlen('refresh_token='), (int) strpos($raw.';', ';') - \strlen('refresh_token='));

                return ['value' => 'deleted' === $value ? '' : urldecode($value), 'raw' => $raw];
            }
        }

        return null;
    }
}
