<?php

declare(strict_types=1);

namespace App\Tests\Visibility;

use App\Tests\Functional\AdminTestTrait;
use App\Tests\Functional\ApiRequestTrait;
use App\Tests\Functional\AuthTestCase;
use App\Tests\Functional\DataExportTestTrait;
use App\Tests\Functional\NotificationTestTrait;

/**
 * Lot 16 cases of the visibility suite (spec §5.17, §11 décision 71):
 *
 * - every /api/admin endpoint is a 404 for anyone but the superadmin;
 * - the administration shows no idea, nor anything done on one;
 * - a forced friendship can't be told from an ordinary one (API,
 *   /sync, notifications, export);
 * - an introduction is visible to its two people only, and « Ignorer »
 *   is never revealed to the other;
 * - no child profile is ever introduced.
 *
 * Cast: Admin; Anna, Bruno, Zoé, adults.
 */
final class AdminVisibilityTest extends AuthTestCase
{
    use AdminTestTrait;
    use ApiRequestTrait;
    use DataExportTestTrait;
    use NotificationTestTrait;

    private string $admin;
    private string $anna;
    private string $bruno;
    private string $zoe;
    private string $annaId;
    private string $brunoId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->registerVerifyAndLogin('admv-admin@example.com');
        $this->grantSuperAdmin('admv-admin@example.com');
        $this->anna = $this->registerVerifyAndLogin('admv-anna@example.com');
        $this->bruno = $this->registerVerifyAndLogin('admv-bruno@example.com');
        $this->zoe = $this->registerVerifyAndLogin('admv-zoe@example.com');
        $this->expect(200, $this->anna, 'PATCH', '/api/users/me', ['displayName' => 'Anna']);
        $this->expect(200, $this->bruno, 'PATCH', '/api/users/me', ['displayName' => 'Bruno']);
        $this->annaId = $this->userId($this->anna);
        $this->brunoId = $this->userId($this->bruno);
    }

    public function testEveryAdminEndpointIsA404ForAnyoneElse(): void
    {
        $this->befriend($this->anna, 'admv-anna@example.com', $this->bruno, 'admv-bruno@example.com');
        $friendshipId = $this->expect(200, $this->anna, 'GET', '/api/friendships')[0]['id'];
        $this->connect([$this->annaId, $this->userId($this->zoe)], 'introduce');
        $introductionId = $this->expect(200, $this->admin, 'GET', "/api/admin/users/{$this->annaId}")['introductions'][0]['id'];

        foreach ([$this->anna, $this->zoe] as $token) {
            foreach ([
                ['GET', '/api/admin/users', null],
                ['GET', "/api/admin/users/{$this->annaId}", null],
                ['POST', '/api/admin/connections', ['users' => [$this->annaId, $this->brunoId], 'mode' => 'force']],
                ['DELETE', "/api/admin/friendships/{$friendshipId}", null],
                ['DELETE', "/api/admin/introductions/{$introductionId}", null],
            ] as [$method, $path, $body]) {
                $this->expect(404, $token, $method, $path, $body);
            }
        }
        self::assertCount(1, $this->expect(200, $this->anna, 'GET', '/api/friendships'), 'nothing was done');
    }

    public function testTheAdministrationShowsNoIdeaNorAnythingDoneOnOne(): void
    {
        $this->befriend($this->anna, 'admv-anna@example.com', $this->bruno, 'admv-bruno@example.com');
        $idea = $this->expect(201, $this->anna, 'POST', '/api/ideas', ['title' => 'Platine vinyle zx81', 'priceAmount' => '180']);
        $this->expect(201, $this->bruno, 'POST', '/api/reservations', ['ideaId' => $idea['id']]);
        $this->expect(201, $this->bruno, 'POST', '/api/comments', ['ideaId' => $idea['id'], 'body' => 'Commentaire qw42']);

        $responses = json_encode([
            $this->expect(200, $this->admin, 'GET', '/api/admin/users'),
            $this->expect(200, $this->admin, 'GET', "/api/admin/users/{$this->annaId}"),
            $this->expect(200, $this->admin, 'GET', "/api/admin/users/{$this->brunoId}"),
        ]) ?: '';
        foreach (['zx81', 'qw42', $idea['id'], 'idea', 'reserv', 'comment'] as $trace) {
            self::assertStringNotContainsStringIgnoringCase($trace, $responses);
        }
    }

    public function testAForcedFriendshipCannotBeToldFromAnOrdinaryOne(): void
    {
        $this->befriend($this->anna, 'admv-anna@example.com', $this->zoe, 'admv-zoe@example.com');
        $annaBefore = $this->notificationsOf($this->anna);
        $this->connect([$this->annaId, $this->brunoId], 'force');

        self::assertSame($annaBefore, $this->notificationsOf($this->anna));
        foreach ([$this->anna, $this->bruno] as $token) {
            $origins = array_unique(array_column($this->expect(200, $token, 'GET', '/api/friendships'), 'origin'));
            self::assertSame(['request'], array_values($origins));
            $sync = json_encode($this->expect(200, $token, 'POST', '/api/sync', [])) ?: '';
            self::assertStringNotContainsString('"admin"', $sync);
        }
        self::assertSame([], $this->notificationsOf($this->bruno));

        $this->expect(202, $this->bruno, 'POST', '/api/account/export', ['password' => 'correcthorsebattery']);
        $friends = json_decode($this->runExport()['files']['friends.json'], true);
        self::assertSame(['request'], array_column($friends['friends'], 'origin'));
        self::assertSame([], $friends['introductionsReceived']);
    }

    public function testAnIntroductionIsVisibleToItsTwoPeopleOnly(): void
    {
        $this->connect([$this->annaId, $this->brunoId], 'introduce');
        $annaSide = $this->expect(200, $this->anna, 'GET', '/api/introductions')[0];
        self::assertSame(['id', 'displayName', 'avatarUrl'], array_keys($annaSide['user']));

        self::assertSame([], $this->expect(200, $this->zoe, 'GET', '/api/introductions'));
        $this->expect(404, $this->zoe, 'POST', "/api/introductions/{$annaSide['id']}/accept");
        $this->expect(404, $this->zoe, 'POST', "/api/introductions/{$annaSide['id']}/ignore");
        self::assertNotContains('introduction_received', $this->typesOf($this->zoe));

        // The notification names the team, never the administrator.
        $notification = array_values(array_filter($this->notificationsOf($this->anna), static fn ($n) => 'introduction_received' === $n['type']))[0];
        self::assertArrayNotHasKey('actor', $notification['payload']);
        self::assertSame(['id', 'displayName'], array_keys($notification['payload']['friend']));
        self::assertStringNotContainsString('admv-admin', json_encode($notification) ?: '');

        $this->expect(202, $this->anna, 'POST', '/api/account/export', ['password' => 'correcthorsebattery']);
        $friends = json_decode($this->runExport()['files']['friends.json'], true);
        self::assertSame(['Bruno'], array_column($friends['introductionsReceived'], 'with'));
    }

    public function testIgnoringIsNeverRevealedToTheOther(): void
    {
        $this->connect([$this->annaId, $this->brunoId], 'introduce');
        $brunoBefore = $this->expect(200, $this->bruno, 'GET', '/api/introductions');
        $brunoTypes = $this->typesOf($this->bruno);

        $id = $this->expect(200, $this->anna, 'GET', '/api/introductions')[0]['id'];
        $this->expect(204, $this->anna, 'POST', "/api/introductions/{$id}/ignore");

        self::assertSame($brunoBefore, $this->expect(200, $this->bruno, 'GET', '/api/introductions'));
        self::assertSame($brunoTypes, $this->typesOf($this->bruno));
        // Accepting answers exactly as if Anna simply hadn't answered yet.
        self::assertSame('accepted', $this->expect(200, $this->bruno, 'POST', "/api/introductions/{$brunoBefore[0]['id']}/accept")['status']);
        self::assertSame([], $this->expect(200, $this->bruno, 'GET', '/api/friendships'));
        self::assertSame([], $this->expect(200, $this->bruno, 'GET', '/api/friendships/outgoing'));
    }

    public function testNoChildProfileIsEverIntroduced(): void
    {
        $child = $this->expect(201, $this->anna, 'POST', '/api/managed-profiles', ['displayName' => 'Léo', 'parentalConsent' => true])['id'];

        self::assertSame(['done' => 0, 'unavailable' => 1], $this->connect([$child, $this->brunoId], 'introduce'));
        self::assertSame([], $this->expect(200, $this->bruno, 'GET', '/api/introductions'));
        self::assertSame([], $this->expect(200, $this->anna, 'GET', '/api/introductions'));
        $this->expect(403, $this->anna, 'GET', '/api/introductions', null, $child);
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
