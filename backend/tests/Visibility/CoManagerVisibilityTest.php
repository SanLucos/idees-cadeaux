<?php

declare(strict_types=1);

namespace App\Tests\Visibility;

use App\Tests\Functional\ApiRequestTrait;
use App\Tests\Functional\AuthTestCase;

/**
 * Lot 12 cases of the visibility suite (spec §5.15, §11 décision 60):
 * the second manager gets the full manager view — still without others'
 * pledge amounts (règle 3) — once they accepted, never before, and no
 * longer once removed. A child's notifications reach both managers.
 *
 * Cast: Luc, manager of Jules; Claire, friend of Luc, invited; Hugo,
 * adult friend of Jules.
 */
final class CoManagerVisibilityTest extends AuthTestCase
{
    use ApiRequestTrait;

    private string $luc;
    private string $claire;
    private string $hugo;
    private string $jules;
    /** @var array<string, mixed> */
    private array $idea;

    protected function setUp(): void
    {
        parent::setUp();

        $this->luc = $this->registerVerifyAndLogin('cv-luc@example.com');
        $this->claire = $this->registerVerifyAndLogin('cv-claire@example.com');
        $this->hugo = $this->registerVerifyAndLogin('cv-hugo@example.com');
        $this->befriend($this->luc, 'cv-luc@example.com', $this->claire, 'cv-claire@example.com');

        $this->jules = $this->expect(201, $this->luc, 'POST', '/api/managed-profiles', ['displayName' => 'Jules', 'parentalConsent' => true])['id'];
        $this->expect(200, $this->luc, 'POST', '/api/friendships', ['email' => 'cv-hugo@example.com'], $this->jules);
        $request = $this->expect(200, $this->hugo, 'GET', '/api/friendships/incoming')[0];
        $this->expect(200, $this->hugo, 'POST', "/api/friendships/{$request['id']}/accept");

        $this->idea = $this->expect(201, $this->luc, 'POST', '/api/ideas', ['title' => 'Lego'], $this->jules);
        $this->expect(201, $this->hugo, 'POST', '/api/reservations', ['ideaId' => $this->idea['id']]);
        $velo = $this->expect(201, $this->luc, 'POST', '/api/ideas', ['title' => 'Vélo'], $this->jules);
        $pool = $this->expect(201, $this->hugo, 'POST', '/api/contributions', ['ideaId' => $velo['id']]);
        $this->expect(200, $this->hugo, 'PUT', "/api/contributions/{$pool['id']}/pledge", ['amount' => '30']);
        $size = $this->expect(201, $this->luc, 'POST', '/api/profile_sizes', ['label' => 'Pointure', 'value' => '30'], $this->jules);
        $this->expect(200, $this->luc, 'PATCH', $size['@id'], ['value' => '31'], $this->jules);

        $this->expect(201, $this->luc, 'POST', "/api/managed-profiles/{$this->jules}/manager-invitation", ['userId' => $this->userId($this->claire)]);
    }

    public function testInvitedButNotYetAcceptedTheInviteeSeesNothing(): void
    {
        $this->assertNotManager($this->claire);
    }

    public function testTheSecondManagerGetsTheManagerViewButNotOthersAmounts(): void
    {
        $this->acceptInvitation();

        foreach ([null, $this->jules] as $actingAs) {
            $path = null === $actingAs ? "/api/users/{$this->jules}/ideas" : '/api/users/me/ideas';
            $ideas = array_column($this->expect(200, $this->claire, 'GET', $path, null, $actingAs)['member'], null, 'title');
            self::assertSame('manager', $ideas['Lego']['view']);
            self::assertSame($this->userId($this->hugo), $ideas['Lego']['reservation']['user']['id']);
            self::assertSame('30.00', $ideas['Vélo']['contribution']['totalAmount']);
            foreach ($ideas['Vélo']['contribution']['participants'] as $participant) {
                self::assertArrayNotHasKey('amount', $participant, 'règle 3: neither author nor initiator');
            }
        }

        $size = $this->expect(200, $this->claire, 'GET', "/api/profile_sizes?userId={$this->jules}")['member'][0];
        self::assertSame(['30', '31'], array_column($size['history'], 'value'), 'the size history, as the first manager');

        $sync = $this->expect(200, $this->claire, 'POST', '/api/sync', []);
        self::assertContains($this->jules, array_column($sync['changes']['managed_profile'] ?? [], 'id'));
    }

    public function testRemovedTheyLoseEverything(): void
    {
        $this->acceptInvitation();
        $this->expect(200, $this->luc, 'DELETE', "/api/managed-profiles/{$this->jules}/managers/{$this->userId($this->claire)}");

        $this->assertNotManager($this->claire);
        $sync = $this->expect(200, $this->claire, 'POST', '/api/sync', []);
        self::assertNotContains($this->jules, array_column($sync['changes']['managed_profile'] ?? [], 'id'));
        self::assertNotContains($this->jules, array_column($sync['changes']['user'] ?? [], 'id'));
    }

    public function testTheChildsNotificationsReachBothManagers(): void
    {
        $this->acceptInvitation();
        $lea = $this->registerVerifyAndLogin('cv-lea@example.com');
        $this->expect(200, $this->claire, 'POST', '/api/friendships', ['email' => 'cv-lea@example.com'], $this->jules);
        $request = $this->expect(200, $lea, 'GET', '/api/friendships/incoming')[0];
        $this->expect(200, $lea, 'POST', "/api/friendships/{$request['id']}/accept");

        foreach ([$this->luc, $this->claire] as $manager) {
            $types = array_column($this->expect(200, $manager, 'GET', '/api/notifications')['member'], 'type');
            self::assertContains('friend_request_accepted', $types);
        }
    }

    private function acceptInvitation(): void
    {
        $invitation = $this->expect(200, $this->claire, 'GET', '/api/manager-invitations')[0];
        $this->expect(200, $this->claire, 'POST', "/api/manager-invitations/{$invitation['id']}/accept", ['parentalConsent' => true]);
    }

    private function assertNotManager(string $token): void
    {
        self::assertSame([], $this->expect(200, $token, 'GET', '/api/managed-profiles'));
        self::assertSame('resource.not_found', $this->expect(404, $token, 'GET', "/api/users/{$this->jules}/ideas")['code']);
        self::assertSame('resource.not_found', $this->expect(404, $token, 'GET', "/api/ideas/{$this->idea['id']}")['code']);
        self::assertSame([], $this->expect(200, $token, 'GET', "/api/profile_sizes?userId={$this->jules}")['member']);
        self::assertSame('acting_as.forbidden', $this->expect(403, $token, 'GET', '/api/users/me/ideas', null, $this->jules)['code']);
        $this->expect(404, $token, 'PATCH', "/api/managed-profiles/{$this->jules}", ['displayName' => 'X']);
    }
}
