<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class ContactsMatchTest extends AuthTestCase
{
    public function testMatchesOnlyKnownHashedEmails(): void
    {
        $token = $this->registerVerifyAndLogin('cm-me@example.com');
        $this->registerVerifyAndLogin('cm-friend@example.com');

        $response = static::createClient()->request('POST', '/api/contacts/match', [
            'auth_bearer' => $token,
            'json' => [
                'hashedEmails' => [
                    hash('sha256', 'cm-friend@example.com'),
                    hash('sha256', 'nobody-cm@example.com'),
                ],
            ],
        ])->toArray();

        self::assertCount(1, $response);
    }

    public function testNeverMatchesSelf(): void
    {
        $token = $this->registerVerifyAndLogin('cm-self@example.com');

        $response = static::createClient()->request('POST', '/api/contacts/match', [
            'auth_bearer' => $token,
            'json' => ['hashedEmails' => [hash('sha256', 'cm-self@example.com')]],
        ])->toArray();

        self::assertSame([], $response);
    }

    public function testRejectsAnEmptyOrOversizedList(): void
    {
        $token = $this->registerVerifyAndLogin('cm-empty@example.com');

        static::createClient()->request('POST', '/api/contacts/match', [
            'auth_bearer' => $token,
            'json' => ['hashedEmails' => []],
        ]);
        self::assertResponseStatusCodeSame(422);
    }
}
