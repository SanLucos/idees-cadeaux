<?php

declare(strict_types=1);

namespace App\Tests\Visibility;

use App\Tests\Functional\AuthTestCase;

/**
 * Sizes and preferences (spec §5.2) are owner-only in lot 1 — friend
 * read access lands in lot 2 once Friendship exists. Someone else's
 * entry must be a 404, never a 403 (CLAUDE.md règle 1's mechanism),
 * via App\Doctrine\Extension\OwnedByCurrentUserExtension.
 */
final class ProfileSizeAndPreferenceVisibilityTest extends AuthTestCase
{
    public function testAnotherUsersProfileSizeIsA404NotA403(): void
    {
        $this->registerAndVerify('owner@example.com');
        $ownerToken = $this->login('owner@example.com');

        $created = static::createClient()->request('POST', '/api/profile_sizes', [
            'auth_bearer' => $ownerToken,
            'json' => ['label' => 'Pointure', 'value' => '42'],
        ])->toArray();

        $this->registerAndVerify('intruder@example.com');
        $intruderToken = $this->login('intruder@example.com');

        $response = static::createClient()->request('GET', $created['@id'], [
            'auth_bearer' => $intruderToken,
        ]);
        self::assertResponseStatusCodeSame(404);

        $collection = static::createClient()->request('GET', '/api/profile_sizes', [
            'auth_bearer' => $intruderToken,
        ])->toArray();
        self::assertSame(0, $collection['totalItems']);
    }

    public function testAnotherUsersProfileSizeCannotBeEditedOrDeleted(): void
    {
        $this->registerAndVerify('owner2@example.com');
        $ownerToken = $this->login('owner2@example.com');

        $created = static::createClient()->request('POST', '/api/profile_sizes', [
            'auth_bearer' => $ownerToken,
            'json' => ['label' => 'T-shirt', 'value' => 'M'],
        ])->toArray();

        $this->registerAndVerify('intruder2@example.com');
        $intruderToken = $this->login('intruder2@example.com');

        static::createClient()->request('PATCH', $created['@id'], [
            'auth_bearer' => $intruderToken,
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['value' => 'L'],
        ]);
        self::assertResponseStatusCodeSame(404);

        static::createClient()->request('DELETE', $created['@id'], [
            'auth_bearer' => $intruderToken,
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testProfileSizeCapIsEnforced(): void
    {
        $this->registerAndVerify('hoarder@example.com');
        $token = $this->login('hoarder@example.com');
        $client = static::createClient();

        for ($i = 0; $i < 100; ++$i) {
            $client->request('POST', '/api/profile_sizes', [
                'auth_bearer' => $token,
                'json' => ['label' => 'Taille', 'value' => (string) $i],
            ]);
        }

        $client->request('POST', '/api/profile_sizes', [
            'auth_bearer' => $token,
            'json' => ['label' => 'Taille', 'value' => 'one too many'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAnotherUsersProfilePreferenceIsA404NotA403(): void
    {
        $this->registerAndVerify('prefowner@example.com');
        $ownerToken = $this->login('prefowner@example.com');

        $created = static::createClient()->request('POST', '/api/profile_preferences', [
            'auth_bearer' => $ownerToken,
            'json' => ['category' => 'gout', 'label' => 'Chocolat', 'value' => 'noir'],
        ])->toArray();

        $this->registerAndVerify('prefintruder@example.com');
        $intruderToken = $this->login('prefintruder@example.com');

        static::createClient()->request('GET', $created['@id'], [
            'auth_bearer' => $intruderToken,
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAClientIdMakesCreatesReplayableButNeverLetsAnotherUserTakeTheId(): void
    {
        $this->registerAndVerify('replay-owner@example.com');
        $ownerToken = $this->login('replay-owner@example.com');
        $id = '0190a1b2-0000-7000-8000-000000005001';

        foreach ([1, 2] as $attempt) {
            static::createClient()->request('POST', '/api/profile_sizes', [
                'auth_bearer' => $ownerToken,
                'json' => ['clientId' => $id, 'label' => 'Pointure', 'value' => '42'],
            ]);
            self::assertResponseIsSuccessful();
        }
        self::assertSame(1, static::createClient()->request('GET', '/api/profile_sizes', ['auth_bearer' => $ownerToken])->toArray()['totalItems']);

        $this->registerAndVerify('replay-intruder@example.com');
        $intruder = $this->login('replay-intruder@example.com');
        $response = static::createClient()->request('POST', '/api/profile_sizes', [
            'auth_bearer' => $intruder,
            'json' => ['clientId' => $id, 'label' => 'x', 'value' => 'y'],
        ]);
        self::assertSame(409, $response->getStatusCode(), 'someone else\'s id is never taken over');
    }
}
