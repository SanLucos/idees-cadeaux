<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Spec §5.15, §11 décision 60: a second manager, invited among the
 * manager's friends, accepted with the parental consent; two at most,
 * same rights, the last one stays. What each may see: tests/Visibility.
 */
final class ChildManagersTest extends AuthTestCase
{
    use ApiRequestTrait;

    private string $luc;
    private string $claire;
    private string $stranger;
    private string $jules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->luc = $this->registerVerifyAndLogin('cm-luc@example.com');
        $this->claire = $this->registerVerifyAndLogin('cm-claire@example.com');
        $this->stranger = $this->registerVerifyAndLogin('cm-stranger@example.com');
        $this->expect(200, $this->luc, 'PATCH', '/api/users/me', ['displayName' => 'Luc']);
        $this->expect(200, $this->claire, 'PATCH', '/api/users/me', ['displayName' => 'Claire']);
        $this->befriend($this->luc, 'cm-luc@example.com', $this->claire, 'cm-claire@example.com');
        $this->jules = $this->expect(201, $this->luc, 'POST', '/api/managed-profiles', ['displayName' => 'Jules', 'parentalConsent' => true])['id'];
    }

    public function testInvitedAcceptedThenBothManageTheChild(): void
    {
        $child = $this->expect(201, $this->luc, 'POST', "/api/managed-profiles/{$this->jules}/manager-invitation", ['userId' => $this->userId($this->claire)]);
        self::assertSame('Claire', $child['managerInvitation']['user']['displayName']);
        self::assertSame(['Luc'], array_column($child['managers'], 'displayName'));

        $invitation = $this->expect(200, $this->claire, 'GET', '/api/manager-invitations')[0];
        self::assertSame(['Luc', 'Jules'], [$invitation['invitedBy']['displayName'], $invitation['child']['displayName']]);
        self::assertSame('manager_invitation_received', $this->expect(200, $this->claire, 'GET', '/api/notifications')['member'][0]['type'] ?? null);

        self::assertSame('managed_profile.consent_required', $this->expect(422, $this->claire, 'POST', "/api/manager-invitations/{$invitation['id']}/accept", [])['code']);
        $accepted = $this->expect(200, $this->claire, 'POST', "/api/manager-invitations/{$invitation['id']}/accept", ['parentalConsent' => true]);
        self::assertSame(['Luc', 'Claire'], array_column($accepted['managers'], 'displayName'));
        self::assertNull($accepted['managerInvitation']);

        self::assertSame(['Jules'], array_column($this->expect(200, $this->claire, 'GET', '/api/managed-profiles'), 'displayName'));
        $this->expect(201, $this->claire, 'POST', '/api/ideas', ['title' => 'Lego'], $this->jules);
        self::assertSame('Luc & Claire', $this->expect(200, $this->luc, 'GET', '/api/managed-profiles')[0]['managedBy']['displayName']);
        self::assertSame('manager_invitation_accepted', $this->expect(200, $this->luc, 'GET', '/api/notifications')['member'][0]['type'] ?? null);
    }

    public function testOnlyAFriendTwoManagersAtMostOneInvitationAtATime(): void
    {
        self::assertSame('managed_profile.manager_not_a_friend', $this->expect(422, $this->luc, 'POST', "/api/managed-profiles/{$this->jules}/manager-invitation", ['userId' => $this->userId($this->stranger)])['code']);
        self::assertSame('managed_profile.manager_not_a_friend', $this->expect(422, $this->luc, 'POST', "/api/managed-profiles/{$this->jules}/manager-invitation", ['userId' => $this->userId($this->luc)])['code']);

        $this->expect(201, $this->luc, 'POST', "/api/managed-profiles/{$this->jules}/manager-invitation", ['userId' => $this->userId($this->claire)]);
        self::assertSame('managed_profile.manager_invitation_pending', $this->expect(422, $this->luc, 'POST', "/api/managed-profiles/{$this->jules}/manager-invitation", ['userId' => $this->userId($this->claire)])['code']);
        $this->accept();

        $this->befriend($this->claire, 'cm-claire@example.com', $this->stranger, 'cm-stranger@example.com');
        self::assertSame('managed_profile.managers_full', $this->expect(422, $this->claire, 'POST', "/api/managed-profiles/{$this->jules}/manager-invitation", ['userId' => $this->userId($this->stranger)])['code']);
    }

    public function testADeclineLooksLikeNothingToTheInviter(): void
    {
        $this->expect(201, $this->luc, 'POST', "/api/managed-profiles/{$this->jules}/manager-invitation", ['userId' => $this->userId($this->claire)]);
        $invitation = $this->expect(200, $this->claire, 'GET', '/api/manager-invitations')[0];
        $this->expect(204, $this->claire, 'POST', "/api/manager-invitations/{$invitation['id']}/decline");

        $child = $this->expect(200, $this->luc, 'GET', '/api/managed-profiles')[0];
        self::assertNull($child['managerInvitation'], 'gone, as an expired one');
        self::assertSame(1, \count($child['managers']));
        $this->expect(404, $this->claire, 'POST', "/api/manager-invitations/{$invitation['id']}/accept", ['parentalConsent' => true]);
    }

    public function testEitherRemovesTheOtherOrLeavesButNeverTheLastOne(): void
    {
        $this->expect(201, $this->luc, 'POST', "/api/managed-profiles/{$this->jules}/manager-invitation", ['userId' => $this->userId($this->claire)]);
        $this->accept();

        // The second manager removes the first one: same rights.
        $child = $this->expect(200, $this->claire, 'DELETE', "/api/managed-profiles/{$this->jules}/managers/{$this->userId($this->luc)}");
        self::assertSame(['Claire'], array_column($child['managers'], 'displayName'));
        $this->expect(404, $this->luc, 'GET', "/api/users/{$this->jules}/ideas");

        self::assertSame('managed_profile.last_manager', $this->expect(422, $this->claire, 'DELETE', "/api/managed-profiles/{$this->jules}/managers/{$this->userId($this->claire)}")['code']);
    }

    private function accept(): void
    {
        $invitation = $this->expect(200, $this->claire, 'GET', '/api/manager-invitations')[0];
        $this->expect(200, $this->claire, 'POST', "/api/manager-invitations/{$invitation['id']}/accept", ['parentalConsent' => true]);
    }
}
