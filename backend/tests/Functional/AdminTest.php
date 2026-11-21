<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * The administration (spec §5.17, §11 décision 71): accounts, team
 * introductions, silent friendships, silent removals.
 *
 * Cast: Admin (superadmin); Anna, Bruno, Chloé, adults.
 */
final class AdminTest extends AuthTestCase
{
    use AdminTestTrait;
    use ApiRequestTrait;
    use NotificationTestTrait;

    private string $admin;
    private string $anna;
    private string $bruno;
    private string $chloe;
    private string $annaId;
    private string $brunoId;
    private string $chloeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->registerVerifyAndLogin('adm-admin@example.com');
        $this->grantSuperAdmin('adm-admin@example.com');
        $this->anna = $this->registerVerifyAndLogin('adm-anna@example.com');
        $this->bruno = $this->registerVerifyAndLogin('adm-bruno@example.com');
        $this->chloe = $this->registerVerifyAndLogin('adm-chloe@example.com');
        $this->expect(200, $this->anna, 'PATCH', '/api/users/me', ['displayName' => 'Anna']);
        $this->expect(200, $this->bruno, 'PATCH', '/api/users/me', ['displayName' => 'Bruno']);
        $this->expect(200, $this->chloe, 'PATCH', '/api/users/me', ['displayName' => 'Chloé']);
        $this->annaId = $this->userId($this->anna);
        $this->brunoId = $this->userId($this->bruno);
        $this->chloeId = $this->userId($this->chloe);
    }

    public function testTheSuperAdminFlagIsOnMe(): void
    {
        self::assertTrue($this->expect(200, $this->admin, 'GET', '/api/users/me')['isSuperAdmin']);
        self::assertFalse($this->expect(200, $this->anna, 'GET', '/api/users/me')['isSuperAdmin']);
    }

    public function testListsSearchesAndTellsHowEachSignsIn(): void
    {
        $list = $this->expect(200, $this->admin, 'GET', '/api/admin/users?q=adm-');
        self::assertSame(4, $list['total']);

        $row = $this->adminRow($this->admin, $this->annaId);
        self::assertSame('adm-anna@example.com', $row['email']);
        self::assertSame('regular', $row['type']);
        self::assertTrue($row['emailVerified']);
        self::assertSame(['password' => true, 'emailLink' => false, 'social' => []], $row['signIn']);
        self::assertSame(0, $row['friendsCount']);

        $found = $this->expect(200, $this->admin, 'GET', '/api/admin/users?q=chlo');
        self::assertSame([$this->chloeId], array_column($found['items'], 'id'));
    }

    public function testAnEmailLinkAccountShowsItsLongSession(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/auth/email-sign-in', ['json' => ['email' => 'adm-link@example.com']]);
        self::assertResponseIsSuccessful();
        /** @var \Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $messages = array_values(array_filter(
            array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()),
            static fn ($message): bool => $message instanceof \App\Message\SendSignInEmail,
        ));
        $code = end($messages)->code;
        $token = static::createClient()->request('POST', '/api/auth/email-sign-in/verify', ['json' => ['email' => 'adm-link@example.com', 'code' => $code]])->toArray()['token'];

        $row = $this->adminRow($this->admin, $this->userId($token));
        self::assertSame(['password' => false, 'emailLink' => true, 'social' => []], $row['signIn']);
    }

    public function testAnIntroductionNeedsBothToAccept(): void
    {
        self::assertSame(['done' => 1, 'unavailable' => 0], $this->connect([$this->annaId, $this->brunoId], 'introduce'));

        $forAnna = $this->expect(200, $this->anna, 'GET', '/api/introductions');
        self::assertCount(1, $forAnna);
        self::assertSame('Bruno', $forAnna[0]['user']['displayName']);
        self::assertContains('introduction_received', $this->typesOf($this->anna));
        self::assertContains('introduction_received', $this->typesOf($this->bruno));
        self::assertSame('introduced', $this->adminRow($this->admin, $this->brunoId, $this->annaId)['relation']);

        self::assertSame('accepted', $this->expect(200, $this->anna, 'POST', "/api/introductions/{$forAnna[0]['id']}/accept")['status']);
        self::assertSame([], $this->expect(200, $this->anna, 'GET', '/api/introductions'));
        self::assertSame([], $this->expect(200, $this->anna, 'GET', '/api/friendships'));

        $forBruno = $this->expect(200, $this->bruno, 'GET', '/api/introductions')[0];
        self::assertSame('friends', $this->expect(200, $this->bruno, 'POST', "/api/introductions/{$forBruno['id']}/accept")['status']);

        $friends = $this->expect(200, $this->anna, 'GET', '/api/friendships');
        self::assertSame([$this->brunoId], array_map(static fn ($f) => $f['user']['id'], $friends));
        self::assertSame('introduction', $friends[0]['origin']);
        self::assertContains('introduction_accepted', $this->typesOf($this->anna));
        self::assertNotContains('introduction_accepted', $this->typesOf($this->bruno));
        self::assertSame('friends', $this->adminRow($this->admin, $this->brunoId, $this->annaId)['relation']);
    }

    public function testAnIgnoredIntroductionBlocksThePair(): void
    {
        $this->connect([$this->annaId, $this->brunoId], 'introduce');
        $id = $this->expect(200, $this->anna, 'GET', '/api/introductions')[0]['id'];
        $this->expect(204, $this->anna, 'POST', "/api/introductions/{$id}/ignore");

        self::assertSame('unavailable', $this->adminRow($this->admin, $this->brunoId, $this->annaId)['relation']);
        self::assertSame(['done' => 0, 'unavailable' => 1], $this->connect([$this->annaId, $this->brunoId], 'force'));
        self::assertSame([], $this->expect(200, $this->anna, 'GET', '/api/friendships'));
    }

    public function testAForcedFriendshipIsSilentAndLooksOrdinary(): void
    {
        self::assertSame(['done' => 1, 'unavailable' => 0], $this->connect([$this->annaId, $this->brunoId], 'force'));

        foreach ([[$this->anna, $this->brunoId], [$this->bruno, $this->annaId]] as [$token, $otherId]) {
            $friends = $this->expect(200, $token, 'GET', '/api/friendships');
            self::assertSame([$otherId], array_map(static fn ($f) => $f['user']['id'], $friends));
            self::assertSame('request', $friends[0]['origin']);
            self::assertSame([], $this->notificationsOf($token));
        }
        // The administration alone sees how it came about.
        $show = $this->expect(200, $this->admin, 'GET', "/api/admin/users/{$this->annaId}");
        self::assertSame('admin', $show['friends'][0]['origin']);
        self::assertSame(1, $show['friendsCount']);
    }

    public function testForcingAcceptsAPendingRequestSilently(): void
    {
        $this->expect(200, $this->anna, 'POST', '/api/friendships', ['email' => 'adm-bruno@example.com']);
        self::assertSame('request_pending', $this->adminRow($this->admin, $this->brunoId, $this->annaId)['relation']);

        $this->connect([$this->annaId, $this->brunoId], 'force');

        self::assertCount(1, $this->expect(200, $this->anna, 'GET', '/api/friendships'));
        self::assertNotContains('friend_request_accepted', $this->typesOf($this->anna));
    }

    public function testNeverPastADeclineOrARemoval(): void
    {
        $this->expect(200, $this->anna, 'POST', '/api/friendships', ['email' => 'adm-bruno@example.com']);
        $request = $this->expect(200, $this->bruno, 'GET', '/api/friendships/incoming')[0];
        $this->expect(200, $this->bruno, 'POST', "/api/friendships/{$request['id']}/decline");

        $this->befriend($this->anna, 'adm-anna@example.com', $this->chloe, 'adm-chloe@example.com');
        $friendship = $this->expect(200, $this->chloe, 'GET', '/api/friendships')[0];
        $this->expect(200, $this->chloe, 'DELETE', "/api/friendships/{$friendship['id']}");

        self::assertSame('unavailable', $this->adminRow($this->admin, $this->brunoId, $this->annaId)['relation']);
        self::assertSame('unavailable', $this->adminRow($this->admin, $this->chloeId, $this->annaId)['relation']);
        // Only Bruno and Chloé can be introduced.
        self::assertSame(['done' => 1, 'unavailable' => 2], $this->connect([$this->annaId, $this->brunoId, $this->chloeId], 'introduce'));
        // Forcing turns that introduction into a friendship; the other pairs stay out.
        self::assertSame(['done' => 1, 'unavailable' => 2], $this->connect([$this->annaId, $this->brunoId, $this->chloeId], 'force'));
        self::assertSame([], $this->expect(200, $this->chloe, 'GET', '/api/introductions'));
    }

    public function testConnectingSeveralPeopleAtOnce(): void
    {
        self::assertSame(['done' => 3, 'unavailable' => 0], $this->connect([$this->annaId, $this->brunoId, $this->chloeId], 'force'));
        self::assertCount(2, $this->expect(200, $this->chloe, 'GET', '/api/friendships'));
        self::assertSame(['done' => 0, 'unavailable' => 3], $this->connect([$this->annaId, $this->brunoId, $this->chloeId], 'force'));
    }

    public function testRemovingIsSilentAndLeavesThePairAvailable(): void
    {
        $this->befriend($this->anna, 'adm-anna@example.com', $this->bruno, 'adm-bruno@example.com');
        $this->deliver();
        $before = \count($this->notificationsOf($this->anna));
        $friendshipId = $this->expect(200, $this->admin, 'GET', "/api/admin/users/{$this->annaId}")['friends'][0]['friendshipId'];

        $this->expect(204, $this->admin, 'DELETE', "/api/admin/friendships/{$friendshipId}");

        self::assertSame([], $this->expect(200, $this->anna, 'GET', '/api/friendships'));
        self::assertCount($before, $this->notificationsOf($this->anna));
        self::assertSame('available', $this->adminRow($this->admin, $this->brunoId, $this->annaId)['relation']);
        // No cooldown: Bruno may ask again at once.
        $this->expect(200, $this->bruno, 'POST', '/api/friendships', ['email' => 'adm-anna@example.com']);
        self::assertCount(1, $this->expect(200, $this->anna, 'GET', '/api/friendships/incoming'));
    }

    public function testCancellingAnIntroduction(): void
    {
        $this->connect([$this->annaId, $this->brunoId], 'introduce');
        $id = $this->expect(200, $this->admin, 'GET', "/api/admin/users/{$this->annaId}")['introductions'][0]['id'];

        $this->expect(204, $this->admin, 'DELETE', "/api/admin/introductions/{$id}");

        self::assertSame([], $this->expect(200, $this->anna, 'GET', '/api/introductions'));
        self::assertSame([], $this->expect(200, $this->bruno, 'GET', '/api/introductions'));
        $this->expect(404, $this->admin, 'DELETE', "/api/admin/introductions/{$id}");
    }

    public function testChildrenAreNeverConnected(): void
    {
        $child = $this->expect(201, $this->anna, 'POST', '/api/managed-profiles', ['displayName' => 'Léo', 'parentalConsent' => true])['id'];

        $row = $this->adminRow($this->admin, $child, $this->brunoId);
        self::assertSame('managed', $row['type']);
        self::assertSame('Anna', $row['managers'][0]['displayName']);
        self::assertSame('unavailable', $row['relation']);
        self::assertSame(['done' => 0, 'unavailable' => 1], $this->connect([$child, $this->brunoId], 'force'));
    }

    public function testInvalidRequests(): void
    {
        $this->expect(422, $this->admin, 'POST', '/api/admin/connections', ['users' => [$this->annaId], 'mode' => 'force']);
        $this->expect(422, $this->admin, 'POST', '/api/admin/connections', ['users' => [$this->annaId, $this->brunoId], 'mode' => 'magic']);
    }

    /**
     * @param string[] $users
     *
     * @return array{done: int, unavailable: int}
     */
    private function connect(array $users, string $mode): array
    {
        return $this->expect(200, $this->admin, 'POST', '/api/admin/connections', ['users' => $users, 'mode' => $mode]);
    }
}
