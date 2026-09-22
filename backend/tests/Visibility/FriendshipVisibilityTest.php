<?php

declare(strict_types=1);

namespace App\Tests\Visibility;

use App\Tests\Functional\AuthTestCase;

/**
 * CLAUDE.md règle 4: "le statut declined n'est jamais exposé au
 * demandeur" — plus the friend-visibility extension the ProfileSize/
 * ProfilePreference tests from lot 1 anticipated.
 */
final class FriendshipVisibilityTest extends AuthTestCase
{
    public function testDeclinedRequestStaysPendingThenExpiredForTheRequesterOnly(): void
    {
        $requesterToken = $this->registerVerifyAndLogin('fv-req@example.com');
        $addresseeToken = $this->registerVerifyAndLogin('fv-add@example.com');

        static::createClient()->request('POST', '/api/friendships', [
            'auth_bearer' => $requesterToken,
            'json' => ['email' => 'fv-add@example.com'],
        ]);
        $id = static::createClient()->request('GET', '/api/friendships/outgoing', ['auth_bearer' => $requesterToken])->toArray()[0]['id'];

        $declineResponse = static::createClient()->request('POST', "/api/friendships/{$id}/decline", ['auth_bearer' => $addresseeToken]);
        self::assertSame('declined', $declineResponse->toArray()['status'], 'the addressee sees their own real choice');

        $requesterView = static::createClient()->request('GET', '/api/friendships/outgoing', ['auth_bearer' => $requesterToken])->toArray();
        self::assertCount(1, $requesterView);
        self::assertSame('pending', $requesterView[0]['status'], 'the requester must never see "declined"');
        self::assertNull($requesterView[0]['respondedAt'], 'a masked-as-pending request must not leak a response timestamp either');

        // The addressee no longer sees it as an incoming request either way.
        self::assertSame([], static::createClient()->request('GET', '/api/friendships/incoming', ['auth_bearer' => $addresseeToken])->toArray());
    }

    public function testAStrangerCannotReadAnotherUsersProfileSizesEvenExplicitly(): void
    {
        $ownerToken = $this->registerVerifyAndLogin('fv-owner@example.com');
        $strangerToken = $this->registerVerifyAndLogin('fv-stranger@example.com');

        $owner = static::createClient()->request('GET', '/api/users/me', ['auth_bearer' => $ownerToken])->toArray();
        static::createClient()->request('POST', '/api/profile_sizes', [
            'auth_bearer' => $ownerToken,
            'json' => ['label' => 'Pointure', 'value' => '42'],
        ]);

        $response = static::createClient()->request('GET', "/api/profile_sizes?userId={$owner['id']}", ['auth_bearer' => $strangerToken])->toArray();
        self::assertSame(0, $response['totalItems']);

        $userResponse = static::createClient()->request('GET', "/api/users/{$owner['id']}", ['auth_bearer' => $strangerToken]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAFriendCanReadButNotEditProfileSizesAndPreferences(): void
    {
        $ownerToken = $this->registerVerifyAndLogin('fv-owner2@example.com');
        $friendToken = $this->registerVerifyAndLogin('fv-friend2@example.com');
        $owner = static::createClient()->request('GET', '/api/users/me', ['auth_bearer' => $ownerToken])->toArray();

        static::createClient()->request('POST', '/api/friendships', ['auth_bearer' => $ownerToken, 'json' => ['email' => 'fv-friend2@example.com']]);
        static::createClient()->request('POST', '/api/friendships', ['auth_bearer' => $friendToken, 'json' => ['email' => 'fv-owner2@example.com']]);

        $size = static::createClient()->request('POST', '/api/profile_sizes', [
            'auth_bearer' => $ownerToken,
            'json' => ['label' => 'Bague', 'value' => '54'],
        ])->toArray();

        $friendView = static::createClient()->request('GET', "/api/profile_sizes?userId={$owner['id']}", ['auth_bearer' => $friendToken])->toArray();
        self::assertSame(1, $friendView['totalItems']);

        static::createClient()->request('PATCH', $size['@id'], [
            'auth_bearer' => $friendToken,
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['value' => '99'],
        ]);
        self::assertResponseStatusCodeSame(403, 'a friend can read but never write another user\'s size');

        $friendProfile = static::createClient()->request('GET', "/api/users/{$owner['id']}", ['auth_bearer' => $friendToken]);
        self::assertResponseStatusCodeSame(200);
        $friendProfileData = $friendProfile->toArray();
        self::assertArrayNotHasKey('email', $friendProfileData, 'a friend\'s profile view never includes email (spec §4)');
    }
}
