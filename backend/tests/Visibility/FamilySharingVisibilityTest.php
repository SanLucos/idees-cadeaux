<?php

declare(strict_types=1);

namespace App\Tests\Visibility;

use App\Tests\Functional\ApiRequestTrait;
use App\Tests\Functional\AuthTestCase;

/**
 * Lot 12 cases of the visibility suite (spec §5.15, §11 décision 59):
 * « Partager avec mes amis » gives a manager's friends a friend's view
 * of the child — never the manager view, nor the size history — and to
 * nobody else; it ends with the option, or with the friendship with the
 * manager, and a friend who removes the child keeps it hidden.
 *
 * Cast: Manager, their child Jules; Marc, friend of the manager; Nina,
 * friend of Marc only; Stranger.
 */
final class FamilySharingVisibilityTest extends AuthTestCase
{
    use ApiRequestTrait;

    private string $manager;
    private string $marc;
    private string $nina;
    private string $stranger;
    private string $jules;
    /** @var array<string, mixed> */
    private array $idea;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->registerVerifyAndLogin('fs-manager@example.com');
        $this->marc = $this->registerVerifyAndLogin('fs-marc@example.com');
        $this->nina = $this->registerVerifyAndLogin('fs-nina@example.com');
        $this->stranger = $this->registerVerifyAndLogin('fs-stranger@example.com');
        $this->expect(200, $this->manager, 'PATCH', '/api/users/me', ['displayName' => 'Luc']);
        $this->befriend($this->manager, 'fs-manager@example.com', $this->marc, 'fs-marc@example.com');
        $this->befriend($this->marc, 'fs-marc@example.com', $this->nina, 'fs-nina@example.com');

        $this->jules = $this->expect(201, $this->manager, 'POST', '/api/managed-profiles', ['displayName' => 'Jules', 'parentalConsent' => true])['id'];
        $this->idea = $this->expect(201, $this->manager, 'POST', '/api/ideas', ['title' => 'Lego'], $this->jules);
        $size = $this->expect(201, $this->manager, 'POST', '/api/profile_sizes', ['label' => 'Pointure', 'value' => '30'], $this->jules);
        $this->expect(200, $this->manager, 'PATCH', $size['@id'], ['value' => '31'], $this->jules);
    }

    public function testOffByDefaultNoFriendOfTheManagerSeesTheChild(): void
    {
        self::assertFalse($this->expect(200, $this->manager, 'GET', '/api/managed-profiles')[0]['shareWithManagerFriends']);
        $this->assertHidden($this->marc);
    }

    public function testSharedTheManagersFriendsGetAFriendsViewAndNobodyElse(): void
    {
        $this->share(true);

        $list = $this->expect(200, $this->marc, 'GET', "/api/users/{$this->jules}/ideas");
        self::assertSame(['Lego'], array_column($list['member'], 'title'));
        self::assertSame('friend', $list['member'][0]['view'], 'never the manager view');

        $size = $this->expect(200, $this->marc, 'GET', "/api/profile_sizes?userId={$this->jules}")['member'][0];
        self::assertSame('31', $size['value']);
        self::assertArrayNotHasKey('history', $size, 'never the size history');

        $friend = $this->friendOf($this->marc, $this->jules);
        self::assertSame('family', $friend['origin']);
        self::assertSame('Luc', $friend['user']['managedBy']['displayName'], 'shown as the manager\'s child');

        $sync = $this->expect(200, $this->marc, 'POST', '/api/sync', []);
        self::assertContains($this->jules, array_column($sync['changes']['user'], 'id'));

        foreach ([$this->nina, $this->stranger] as $token) {
            $this->assertHidden($token);
        }
    }

    public function testTurnedOffOrNoLongerFriendsWithTheManagerItEnds(): void
    {
        $this->share(true);
        $this->expect(201, $this->marc, 'POST', '/api/reservations', ['ideaId' => $this->idea['id']]);
        $this->share(false);
        $this->assertHidden($this->marc);

        $this->share(true);
        $this->expect(200, $this->marc, 'GET', "/api/users/{$this->jules}/ideas");
        self::assertNull($this->expect(200, $this->manager, 'GET', "/api/ideas/{$this->idea['id']}")['reservation'] ?? null, 'what a removed friend did goes, as for any removal (§5.3)');

        $managerFriendship = $this->friendOf($this->marc, $this->userId($this->manager));
        $this->expect(200, $this->marc, 'DELETE', "/api/friendships/{$managerFriendship['id']}");
        $this->assertHidden($this->marc);
    }

    public function testANewFriendOfTheManagerGetsItTooAndAFriendWhoRemovesTheChildKeepsItHidden(): void
    {
        $this->share(true);
        $this->befriend($this->manager, 'fs-manager@example.com', $this->stranger, 'fs-stranger@example.com');
        $this->expect(200, $this->stranger, 'GET', "/api/users/{$this->jules}/ideas");

        $this->expect(200, $this->marc, 'DELETE', '/api/friendships/'.$this->friendOf($this->marc, $this->jules)['id']);
        $this->assertHidden($this->marc);
        $this->share(false);
        $this->share(true);
        $this->assertHidden($this->marc);
    }

    public function testTheSecondManagersFriendsTooUntilTheyNoLongerManageTheChild(): void
    {
        $this->share(true);
        $claire = $this->registerVerifyAndLogin('fs-claire@example.com');
        $this->befriend($this->manager, 'fs-manager@example.com', $claire, 'fs-claire@example.com');
        $this->befriend($claire, 'fs-claire@example.com', $this->stranger, 'fs-stranger@example.com');
        $this->expect(201, $this->manager, 'POST', "/api/managed-profiles/{$this->jules}/manager-invitation", ['userId' => $this->userId($claire)]);
        $invitation = $this->expect(200, $claire, 'GET', '/api/manager-invitations')[0];
        $this->expect(200, $claire, 'POST', "/api/manager-invitations/{$invitation['id']}/accept", ['parentalConsent' => true]);

        $this->expect(200, $this->stranger, 'GET', "/api/users/{$this->jules}/ideas");
        self::assertSame('manager', $this->expect(200, $claire, 'GET', "/api/users/{$this->jules}/ideas")['member'][0]['view'], 'a manager is no family friend');

        $this->expect(200, $this->manager, 'DELETE', "/api/managed-profiles/{$this->jules}/managers/{$this->userId($claire)}");
        $this->assertHidden($this->stranger);
        self::assertSame('friend', $this->expect(200, $claire, 'GET', "/api/users/{$this->jules}/ideas")['member'][0]['view'], 'now a friend of the manager like any other');
    }

    public function testTheChildsSideCantRemoveThemOneByOne(): void
    {
        $this->share(true);
        $id = $this->friendOf($this->marc, $this->jules)['id'];

        self::assertSame('friendship.family_managed', $this->expect(422, $this->manager, 'DELETE', "/api/friendships/{$id}", null, $this->jules)['code']);
    }

    private function share(bool $on): void
    {
        self::assertSame($on, $this->expect(200, $this->manager, 'PATCH', "/api/managed-profiles/{$this->jules}", ['shareWithManagerFriends' => $on])['shareWithManagerFriends']);
    }

    private function assertHidden(string $token): void
    {
        self::assertSame('resource.not_found', $this->expect(404, $token, 'GET', "/api/users/{$this->jules}/ideas")['code']);
        self::assertSame('resource.not_found', $this->expect(404, $token, 'GET', "/api/ideas/{$this->idea['id']}")['code']);
        self::assertSame([], $this->expect(200, $token, 'GET', "/api/profile_sizes?userId={$this->jules}")['member']);
        self::assertNull($this->friendOf($token, $this->jules, false));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function friendOf(string $token, string $userId, bool $required = true): ?array
    {
        foreach ($this->expect(200, $token, 'GET', '/api/friendships') as $friendship) {
            if ($friendship['user']['id'] === $userId) {
                return $friendship;
            }
        }
        if ($required) {
            self::fail("no friendship with {$userId}");
        }

        return null;
    }
}
