<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * End-to-end: register → verify → login → refresh → logout (spec §5.1).
 */
final class RegistrationAndLoginTest extends AuthTestCase
{
    public function testRegisterRequiresAValidEmailAndAStrongPassword(): void
    {
        static::createClient()->request('POST', '/api/auth/register', [
            'json' => ['email' => 'not-an-email', 'password' => 'correcthorsebattery'],
        ]);
        self::assertResponseStatusCodeSame(422);

        static::createClient()->request('POST', '/api/auth/register', [
            'json' => ['email' => 'short@example.com', 'password' => 'short'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRegisterRejectsAnAlreadyUsedEmail(): void
    {
        $this->registerAndVerify('dup@example.com');

        $response = static::createClient()->request('POST', '/api/auth/register', [
            'json' => ['email' => 'dup@example.com', 'password' => 'correcthorsebattery'],
        ]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('auth.email_already_registered', $response->toArray(false)['code']);
    }

    public function testLoginIsRejectedBeforeEmailVerification(): void
    {
        static::createClient()->request('POST', '/api/auth/register', [
            'json' => ['email' => 'unverified@example.com', 'password' => 'correcthorsebattery'],
        ]);

        $response = static::createClient()->request('POST', '/api/auth/login', [
            'json' => ['email' => 'unverified@example.com', 'password' => 'correcthorsebattery'],
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('auth.email_not_verified', $response->toArray(false)['code']);
    }

    public function testFullLoginRefreshLogoutCycle(): void
    {
        $this->registerAndVerify('cycle@example.com');

        $login = static::createClient()->request('POST', '/api/auth/login', [
            'json' => ['email' => 'cycle@example.com', 'password' => 'correcthorsebattery'],
        ])->toArray();

        self::assertArrayHasKey('token', $login);
        self::assertArrayHasKey('refresh_token', $login);
        self::assertFalse($login['user']['isOnboarded']);

        $refreshed = static::createClient()->request('POST', '/api/auth/refresh', [
            'json' => ['refresh_token' => $login['refresh_token']],
        ]);
        self::assertResponseStatusCodeSame(200);
        $refreshedData = $refreshed->toArray();
        self::assertNotSame($login['refresh_token'], $refreshedData['refresh_token'], 'refresh token must rotate');

        // The old refresh token was single-use: replaying it now fails.
        static::createClient()->request('POST', '/api/auth/refresh', [
            'json' => ['refresh_token' => $login['refresh_token']],
        ]);
        self::assertResponseStatusCodeSame(401);

        $client = static::createClient();
        $client->request('POST', '/api/auth/logout', [
            'auth_bearer' => $refreshedData['token'],
            'json' => ['refresh_token' => $refreshedData['refresh_token']],
        ]);
        self::assertResponseStatusCodeSame(200);

        static::createClient()->request('POST', '/api/auth/refresh', [
            'json' => ['refresh_token' => $refreshedData['refresh_token']],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testLoginIsThrottledAfterRepeatedFailures(): void
    {
        $this->registerAndVerify('throttled@example.com');

        for ($i = 0; $i < 5; ++$i) {
            static::createClient()->request('POST', '/api/auth/login', [
                'json' => ['email' => 'throttled@example.com', 'password' => 'wrong-password'],
            ]);
        }

        $response = static::createClient()->request('POST', '/api/auth/login', [
            'json' => ['email' => 'throttled@example.com', 'password' => 'wrong-password'],
        ]);
        self::assertResponseStatusCodeSame(429);
    }
}
