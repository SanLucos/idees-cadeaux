<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class UserProfileTest extends AuthTestCase
{
    public function testOnboardingSetsThePseudoAndBirthDate(): void
    {
        $this->registerAndVerify('profile@example.com');
        $token = $this->login('profile@example.com');

        $client = static::createClient();
        $before = $client->request('GET', '/api/users/me', ['auth_bearer' => $token])->toArray();
        self::assertFalse($before['isOnboarded']);
        self::assertNull($before['displayName']);

        $after = $client->request('PATCH', '/api/users/me', [
            'auth_bearer' => $token,
            'json' => ['displayName' => 'Profil Test', 'birthDay' => 14, 'birthMonth' => 7],
        ])->toArray();

        self::assertTrue($after['isOnboarded']);
        self::assertSame('Profil Test', $after['displayName']);
        self::assertSame(14, $after['birthDay']);
        self::assertSame(7, $after['birthMonth']);
        self::assertNull($after['birthYear']);
    }

    public function testDisplayNameMustBeTwoToThirtyCharacters(): void
    {
        $this->registerAndVerify('shortname@example.com');
        $token = $this->login('shortname@example.com');

        static::createClient()->request('PATCH', '/api/users/me', [
            'auth_bearer' => $token,
            'json' => ['displayName' => 'A'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testBirthMonthWithoutBirthDayIsRejected(): void
    {
        $this->registerAndVerify('birthdate@example.com');
        $token = $this->login('birthdate@example.com');

        static::createClient()->request('PATCH', '/api/users/me', [
            'auth_bearer' => $token,
            'json' => ['birthMonth' => 7],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testMeRequiresAuthentication(): void
    {
        static::createClient()->request('GET', '/api/users/me');
        self::assertResponseStatusCodeSame(401);
    }
}
