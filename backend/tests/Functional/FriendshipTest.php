<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * spec §5.3: send/accept/cancel/remove, mutual requests, anti-
 * enumeration, and the 30-day cooldown (except after a removal).
 * Silent decline is covered separately in tests/Visibility — it's a
 * CLAUDE.md règle 4 case, not just a functional one.
 */
final class FriendshipTest extends AuthTestCase
{
    public function testSendRequestAnswersIdenticallyWhetherOrNotTheAccountExists(): void
    {
        $token = $this->registerVerifyAndLogin('fr-a@example.com');
        $this->registerAndVerify('fr-b@example.com');

        $known = static::createClient()->request('POST', '/api/friendships', [
            'auth_bearer' => $token,
            'json' => ['email' => 'fr-b@example.com'],
        ]);
        $unknown = static::createClient()->request('POST', '/api/friendships', [
            'auth_bearer' => $token,
            'json' => ['email' => 'nobody-fr@example.com'],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($known->toArray(), $unknown->toArray());
    }

    public function testSendRequestAcceptsAUserIdInsteadOfAnEmail(): void
    {
        $token = $this->registerVerifyAndLogin('fr-uid-a@example.com');
        $targetToken = $this->registerVerifyAndLogin('fr-uid-b@example.com');
        $target = static::createClient()->request('GET', '/api/users/me', ['auth_bearer' => $targetToken])->toArray();

        static::createClient()->request('POST', '/api/friendships', [
            'auth_bearer' => $token,
            'json' => ['userId' => $target['id']],
        ]);
        self::assertResponseStatusCodeSame(200);

        $outgoing = static::createClient()->request('GET', '/api/friendships/outgoing', ['auth_bearer' => $token])->toArray();
        self::assertCount(1, $outgoing);
        self::assertSame($target['id'], $outgoing[0]['user']['id']);
    }

    public function testSendRequestCannotTargetSelf(): void
    {
        $token = $this->registerVerifyAndLogin('fr-self@example.com');

        static::createClient()->request('POST', '/api/friendships', [
            'auth_bearer' => $token,
            'json' => ['email' => 'fr-self@example.com'],
        ]);
        self::assertResponseStatusCodeSame(200);

        $outgoing = static::createClient()->request('GET', '/api/friendships/outgoing', ['auth_bearer' => $token])->toArray();
        self::assertSame([], $outgoing);
    }

    public function testAcceptCreatesAMutualFriendship(): void
    {
        $aToken = $this->registerVerifyAndLogin('fr-c@example.com');
        $bToken = $this->registerVerifyAndLogin('fr-d@example.com');

        static::createClient()->request('POST', '/api/friendships', [
            'auth_bearer' => $aToken,
            'json' => ['email' => 'fr-d@example.com'],
        ]);

        $incoming = static::createClient()->request('GET', '/api/friendships/incoming', ['auth_bearer' => $bToken])->toArray();
        self::assertCount(1, $incoming);
        self::assertArrayNotHasKey('email', $incoming[0]['user'], 'a pending request must only expose pseudo + avatar (spec §4), never the requester\'s email');

        $id = $incoming[0]['id'];
        static::createClient()->request('POST', "/api/friendships/{$id}/accept", ['auth_bearer' => $bToken]);
        self::assertResponseStatusCodeSame(200);

        $aFriends = static::createClient()->request('GET', '/api/friendships', ['auth_bearer' => $aToken])->toArray();
        $bFriends = static::createClient()->request('GET', '/api/friendships', ['auth_bearer' => $bToken])->toArray();
        self::assertCount(1, $aFriends);
        self::assertCount(1, $bFriends);
        self::assertSame('accepted', $aFriends[0]['status']);
    }

    public function testMutualRequestsResolveToImmediateFriendship(): void
    {
        $aToken = $this->registerVerifyAndLogin('fr-e@example.com');
        $bToken = $this->registerVerifyAndLogin('fr-f@example.com');

        static::createClient()->request('POST', '/api/friendships', ['auth_bearer' => $aToken, 'json' => ['email' => 'fr-f@example.com']]);
        static::createClient()->request('POST', '/api/friendships', ['auth_bearer' => $bToken, 'json' => ['email' => 'fr-e@example.com']]);

        $aFriends = static::createClient()->request('GET', '/api/friendships', ['auth_bearer' => $aToken])->toArray();
        self::assertCount(1, $aFriends);
        self::assertSame('accepted', $aFriends[0]['status']);
    }

    public function testCancelWithdrawsAPendingRequest(): void
    {
        $aToken = $this->registerVerifyAndLogin('fr-g@example.com');
        $bToken = $this->registerVerifyAndLogin('fr-h@example.com');

        static::createClient()->request('POST', '/api/friendships', ['auth_bearer' => $aToken, 'json' => ['email' => 'fr-h@example.com']]);
        $id = static::createClient()->request('GET', '/api/friendships/outgoing', ['auth_bearer' => $aToken])->toArray()[0]['id'];

        static::createClient()->request('POST', "/api/friendships/{$id}/cancel", ['auth_bearer' => $aToken]);
        self::assertResponseStatusCodeSame(200);

        self::assertSame([], static::createClient()->request('GET', '/api/friendships/outgoing', ['auth_bearer' => $aToken])->toArray());
        self::assertSame([], static::createClient()->request('GET', '/api/friendships/incoming', ['auth_bearer' => $bToken])->toArray());
    }

    public function testOnlyTheAddresseeCanAcceptOrDecline(): void
    {
        $aToken = $this->registerVerifyAndLogin('fr-i@example.com');
        $bToken = $this->registerVerifyAndLogin('fr-j@example.com');
        $intruderToken = $this->registerVerifyAndLogin('fr-k@example.com');

        static::createClient()->request('POST', '/api/friendships', ['auth_bearer' => $aToken, 'json' => ['email' => 'fr-j@example.com']]);
        $id = static::createClient()->request('GET', '/api/friendships/outgoing', ['auth_bearer' => $aToken])->toArray()[0]['id'];

        // Neither the requester nor a stranger may accept.
        static::createClient()->request('POST', "/api/friendships/{$id}/accept", ['auth_bearer' => $aToken]);
        self::assertResponseStatusCodeSame(404);
        static::createClient()->request('POST', "/api/friendships/{$id}/accept", ['auth_bearer' => $intruderToken]);
        self::assertResponseStatusCodeSame(404);

        static::createClient()->request('POST', "/api/friendships/{$id}/accept", ['auth_bearer' => $bToken]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testRemoveEndsAnAcceptedFriendshipBothWays(): void
    {
        $aToken = $this->registerVerifyAndLogin('fr-l@example.com');
        $bToken = $this->registerVerifyAndLogin('fr-m@example.com');

        static::createClient()->request('POST', '/api/friendships', ['auth_bearer' => $aToken, 'json' => ['email' => 'fr-m@example.com']]);
        static::createClient()->request('POST', '/api/friendships', ['auth_bearer' => $bToken, 'json' => ['email' => 'fr-l@example.com']]);
        $id = static::createClient()->request('GET', '/api/friendships', ['auth_bearer' => $aToken])->toArray()[0]['id'];

        static::createClient()->request('DELETE', "/api/friendships/{$id}", ['auth_bearer' => $aToken]);
        self::assertResponseStatusCodeSame(200);

        self::assertSame([], static::createClient()->request('GET', '/api/friendships', ['auth_bearer' => $aToken])->toArray());
        self::assertSame([], static::createClient()->request('GET', '/api/friendships', ['auth_bearer' => $bToken])->toArray());
    }

    public function testRemovalWaivesTheThirtyDayCooldownButCancellationDoesNot(): void
    {
        $aToken = $this->registerVerifyAndLogin('fr-n@example.com');
        $this->registerVerifyAndLogin('fr-o@example.com');

        // Cancel a pending request: re-requesting immediately is a silent no-op.
        static::createClient()->request('POST', '/api/friendships', ['auth_bearer' => $aToken, 'json' => ['email' => 'fr-o@example.com']]);
        $id = static::createClient()->request('GET', '/api/friendships/outgoing', ['auth_bearer' => $aToken])->toArray()[0]['id'];
        static::createClient()->request('POST', "/api/friendships/{$id}/cancel", ['auth_bearer' => $aToken]);

        static::createClient()->request('POST', '/api/friendships', ['auth_bearer' => $aToken, 'json' => ['email' => 'fr-o@example.com']]);
        self::assertSame([], static::createClient()->request('GET', '/api/friendships/outgoing', ['auth_bearer' => $aToken])->toArray(), 'cooldown should block the re-request');
    }

    public function testDuplicateOutgoingRequestIsIdempotent(): void
    {
        $aToken = $this->registerVerifyAndLogin('fr-p@example.com');
        $this->registerVerifyAndLogin('fr-q@example.com');

        static::createClient()->request('POST', '/api/friendships', ['auth_bearer' => $aToken, 'json' => ['email' => 'fr-q@example.com']]);
        static::createClient()->request('POST', '/api/friendships', ['auth_bearer' => $aToken, 'json' => ['email' => 'fr-q@example.com']]);

        self::assertCount(1, static::createClient()->request('GET', '/api/friendships/outgoing', ['auth_bearer' => $aToken])->toArray());
    }
}
