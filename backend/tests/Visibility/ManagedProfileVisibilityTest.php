<?php

declare(strict_types=1);

namespace App\Tests\Visibility;

use App\Message\SendManagedProfileInvitationEmail;
use App\Tests\Functional\ApiRequestTrait;
use App\Tests\Functional\AuthTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Lot 4 bis cases of the visibility suite (spec §4 "Conséquences" 5,
 * §5.15): the manager view — the one exception to the règle d'or — is
 * granted to a managed profile's manager and to nobody else, still
 * hides friends' private drafts (règle 2) and others' pledge amounts
 * (règle 3), and ends with the conversion to an autonomous account.
 *
 * Cast: Manager, their child Jules; Hugo and Léa, adult friends of
 * Jules; Marc, friend of the manager only; Stranger.
 */
final class ManagedProfileVisibilityTest extends AuthTestCase
{
    use ApiRequestTrait;

    private string $manager;
    private string $hugo;
    private string $lea;
    private string $marc;
    private string $stranger;
    private string $jules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->registerVerifyAndLogin('mv-manager@example.com');
        $this->hugo = $this->registerVerifyAndLogin('mv-hugo@example.com');
        $this->lea = $this->registerVerifyAndLogin('mv-lea@example.com');
        $this->marc = $this->registerVerifyAndLogin('mv-marc@example.com');
        $this->stranger = $this->registerVerifyAndLogin('mv-stranger@example.com');
        $this->befriend($this->manager, 'mv-manager@example.com', $this->marc, 'mv-marc@example.com');

        $this->jules = $this->expect(201, $this->manager, 'POST', '/api/managed-profiles', ['displayName' => 'Jules', 'parentalConsent' => true])['id'];
        foreach (['hugo' => $this->hugo, 'lea' => $this->lea] as $name => $token) {
            $this->expect(200, $this->manager, 'POST', '/api/friendships', ['email' => "mv-{$name}@example.com"], $this->jules);
            $request = $this->expect(200, $token, 'GET', '/api/friendships/incoming')[0];
            $this->expect(200, $token, 'POST', "/api/friendships/{$request['id']}/accept");
        }
    }

    public function testTheManagerSeesEverythingOnTheChildsListButFriendsDraftsAndOthersAmounts(): void
    {
        $personal = $this->expect(201, $this->manager, 'POST', '/api/ideas', ['title' => 'Lego'], $this->jules);
        $draft = $this->expect(201, $this->manager, 'POST', '/api/ideas', ['title' => 'Draft', 'visibility' => 'private'], $this->jules);
        self::assertSame($this->jules, $personal['ownerId'], 'created on behalf of the child, attributed to the child');

        $suggestion = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Vélo', 'ownerId' => $this->jules]);
        $hugoDraft = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Hugo draft', 'ownerId' => $this->jules, 'visibility' => 'private']);
        $this->expect(201, $this->lea, 'POST', '/api/reservations', ['ideaId' => $personal['id']]);
        $this->expect(201, $this->lea, 'POST', '/api/comments', ['ideaId' => $personal['id'], 'body' => 'Je prends']);
        $this->expect(200, $this->lea, 'PUT', "/api/ideas/{$personal['id']}/reaction");
        $pool = $this->expect(201, $this->hugo, 'POST', '/api/contributions', ['ideaId' => $suggestion['id']]);
        $this->expect(200, $this->hugo, 'PUT', "/api/contributions/{$pool['id']}/pledge", ['amount' => '40']);
        $this->expect(200, $this->lea, 'PUT', "/api/contributions/{$pool['id']}/pledge", ['amount' => '20']);

        $leaId = $this->userId($this->lea);

        // Same full list, acting as the child or not.
        foreach ([null, $this->jules] as $actingAs) {
            $path = null === $actingAs ? "/api/users/{$this->jules}/ideas" : '/api/users/me/ideas';
            $list = $this->expect(200, $this->manager, 'GET', $path, null, $actingAs);
            $byTitle = array_column($list['member'], null, 'title');
            ksort($byTitle);
            self::assertSame(['Draft', 'Lego', 'Vélo'], array_keys($byTitle), 'friends\' private drafts are never shown (règle 2)');

            self::assertSame('manager', $byTitle['Lego']['view']);
            self::assertSame($leaId, $byTitle['Lego']['reservation']['user']['id']);
            self::assertSame(1, $byTitle['Lego']['commentCount']);
            self::assertSame(1, $byTitle['Lego']['reactions']['count']);

            $contribution = $byTitle['Vélo']['contribution'];
            self::assertSame('60.00', $contribution['totalAmount']);
            foreach ($contribution['participants'] as $p) {
                self::assertArrayNotHasKey('amount', $p, 'the manager is neither author nor initiator (règle 3)');
            }
        }

        $this->expect(200, $this->manager, 'GET', "/api/ideas/{$suggestion['id']}");
        $this->expect(200, $this->manager, 'GET', "/api/ideas/{$suggestion['id']}", null, $this->jules);
        $this->expect(200, $this->manager, 'GET', "/api/ideas/{$personal['id']}/comments");
        $this->expect(404, $this->manager, 'GET', "/api/ideas/{$hugoDraft['id']}");
        $this->expect(404, $this->manager, 'GET', "/api/ideas/{$hugoDraft['id']}", null, $this->jules);

        // The child's draft stays out of friends' reach.
        $this->expect(404, $this->hugo, 'GET', "/api/ideas/{$draft['id']}");
    }

    public function testTheManagerViewIsGrantedToTheManagerAlone(): void
    {
        $this->expect(201, $this->manager, 'POST', '/api/ideas', ['title' => 'Draft', 'visibility' => 'private'], $this->jules);
        $this->expect(201, $this->manager, 'POST', '/api/ideas', ['title' => 'Lego'], $this->jules);

        $hugoView = $this->expect(200, $this->hugo, 'GET', "/api/users/{$this->jules}/ideas");
        self::assertSame(['Lego'], array_column($hugoView['member'], 'title'));
        self::assertSame('friend', $hugoView['member'][0]['view']);

        foreach ([$this->marc, $this->stranger] as $token) {
            $this->expect(404, $token, 'GET', "/api/users/{$this->jules}/ideas");
            $this->expect(404, $token, 'GET', "/api/users/{$this->jules}");
        }

        // X-Acting-As is checked server-side, one answer for every refusal.
        $managerId = $this->userId($this->manager);
        self::assertSame('acting_as.forbidden', $this->expect(403, $this->hugo, 'GET', '/api/users/me/ideas', null, $this->jules)['code']);
        self::assertSame('acting_as.forbidden', $this->expect(403, $this->hugo, 'GET', '/api/users/me', null, $managerId)['code']);
        self::assertSame('acting_as.forbidden', $this->expect(403, $this->manager, 'GET', '/api/users/me', null, $this->userId($this->hugo))['code']);
        self::assertSame('acting_as.forbidden', $this->expect(403, $this->manager, 'GET', '/api/users/me', null, '0190a1b2-0000-7000-8000-00000000ffff')['code']);
        self::assertSame('acting_as.invalid', $this->expect(400, $this->manager, 'GET', '/api/users/me', null, 'not-a-uuid')['code']);
    }

    public function testActingCoversTheChildsListProfileAndFriendsOnly(): void
    {
        $hugoIdea = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Hugo\'s']);

        // Allowed: reading a friend's list as the child…
        $this->expect(200, $this->manager, 'GET', '/api/users/'.$this->userId($this->hugo).'/ideas', null, $this->jules);
        // …but no interaction, suggestion or management on its behalf (spec §11 décision 25).
        foreach ([
            ['POST', '/api/reservations', ['ideaId' => $hugoIdea['id']]],
            ['POST', '/api/contributions', ['ideaId' => $hugoIdea['id']]],
            ['POST', '/api/comments', ['ideaId' => $hugoIdea['id'], 'body' => 'x']],
            ['PUT', "/api/ideas/{$hugoIdea['id']}/reaction", null],
            ['GET', "/api/ideas/{$hugoIdea['id']}/comments", null],
            ['POST', '/api/ideas', ['title' => 'For Hugo', 'ownerId' => $this->userId($this->hugo)]],
            ['GET', '/api/managed-profiles', null],
            ['POST', '/api/managed-profiles', ['displayName' => 'Nested', 'parentalConsent' => true]],
        ] as [$method, $path, $json]) {
            self::assertSame('acting_as.not_allowed', $this->expect(403, $this->manager, $method, $path, $json, $this->jules)['code'], "{$method} {$path}");
        }

        // Profile details written as the child belong to the child, readable by its manager.
        $size = $this->expect(201, $this->manager, 'POST', '/api/profile_sizes', ['label' => 'Pointure', 'value' => '31'], $this->jules);
        self::assertSame(1, $this->expect(200, $this->manager, 'GET', "/api/profile_sizes?userId={$this->jules}")['totalItems']);
        self::assertSame(0, $this->expect(200, $this->manager, 'GET', '/api/profile_sizes')['totalItems'], 'not the manager\'s own sizes');
        $this->expect(200, $this->manager, 'PATCH', $size['@id'], ['value' => '32'], $this->jules);
        self::assertSame(1, $this->expect(200, $this->hugo, 'GET', "/api/profile_sizes?userId={$this->jules}")['totalItems'], 'visible to the child\'s friends');
    }

    public function testChildrenCannotBeReachedByEmailContactsOrId(): void
    {
        $this->expect(200, $this->stranger, 'POST', '/api/friendships', ['userId' => $this->jules]);
        self::assertSame([], $this->expect(200, $this->manager, 'GET', '/api/friendships/incoming', null, $this->jules));
        self::assertSame([], $this->expect(200, $this->stranger, 'GET', '/api/friendships/outgoing'));
    }

    public function testConvertingToAnAutonomousAccountEndsTheManagerViewAndAppliesTheGoldenRule(): void
    {
        $this->expect(201, $this->manager, 'POST', '/api/ideas', ['title' => 'Lego'], $this->jules);
        $draft = $this->expect(201, $this->manager, 'POST', '/api/ideas', ['title' => 'Draft', 'visibility' => 'private'], $this->jules);
        $suggestion = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Vélo', 'ownerId' => $this->jules]);
        $this->expect(201, $this->lea, 'POST', '/api/reservations', ['ideaId' => $suggestion['id']]);

        $this->expect(202, $this->manager, 'POST', "/api/managed-profiles/{$this->jules}/attach-email", ['email' => 'mv-jules@example.com']);
        $tokens = $this->expect(200, $this->stranger, 'POST', '/api/auth/managed-invitation/accept', [
            'email' => 'mv-jules@example.com',
            'code' => $this->invitationCode('mv-jules@example.com'),
            'password' => 'correcthorsebattery',
        ]);
        $jules = $tokens['token'];

        // Jules is now an owner like any other: the suggestion and its reservation vanish for him.
        $mine = $this->expect(200, $jules, 'GET', '/api/users/me/ideas');
        self::assertSame(['Draft', 'Lego'], array_column($mine['member'], 'title'), 'his own ideas, drafts included — never the suggestion');
        self::assertSame('owner', $mine['member'][0]['view']);
        $this->expect(404, $jules, 'GET', "/api/ideas/{$suggestion['id']}");
        $this->expect(200, $jules, 'GET', "/api/ideas/{$draft['id']}", null);

        // The former manager is a friend now, no more: friend view, no drafts, no acting.
        $friendView = $this->expect(200, $this->manager, 'GET', "/api/users/{$this->jules}/ideas");
        self::assertSame(['Vélo', 'Lego'], array_column($friendView['member'], 'title'));
        self::assertSame('friend', $friendView['member'][0]['view']);
        $this->expect(404, $this->manager, 'GET', "/api/ideas/{$draft['id']}");
        self::assertSame('acting_as.forbidden', $this->expect(403, $this->manager, 'GET', '/api/users/me', null, $this->jules)['code']);
        self::assertSame([], $this->expect(200, $this->manager, 'GET', '/api/managed-profiles'));

        self::assertContains($this->jules, array_map(static fn ($f) => $f['user']['id'], $this->expect(200, $this->manager, 'GET', '/api/friendships')));
        self::assertSame('conversion', array_values(array_filter($this->expect(200, $jules, 'GET', '/api/friendships'), static fn ($f) => 'conversion' === $f['origin']))[0]['origin']);
        $this->login('mv-jules@example.com');
    }

    /** Spec §11 décision 51: a child's size history is its manager's alone — never its friends'. */
    public function testAChildsSizeHistoryIsReadByItsManagerAlone(): void
    {
        $size = $this->childSizeWithHistory();

        foreach ([$this->jules => '/api/profile_sizes', null => "/api/profile_sizes?userId={$this->jules}"] as $actingAs => $path) {
            $list = $this->expect(200, $this->manager, 'GET', $path, null, '' === $actingAs ? null : $actingAs);
            self::assertSame(['PAST-31', '32'], array_column($list['member'][0]['history'], 'value'), 'acting as the child or not');
        }
        $synced = array_column($this->expect(200, $this->manager, 'POST', '/api/sync', [])['changes']['profile_size'], null, 'id');
        self::assertSame(['PAST-31', '32'], array_column($synced[$size]['history'], 'value'));

        // The child's friends read the current value, nothing more.
        $hugoView = $this->expect(200, $this->hugo, 'GET', "/api/profile_sizes?userId={$this->jules}");
        self::assertSame('32', $hugoView['member'][0]['value']);
        self::assertArrayNotHasKey('history', $hugoView['member'][0]);
        self::assertArrayNotHasKey('history', $this->expect(200, $this->hugo, 'GET', "/api/profile_sizes/{$size}"));
        self::assertStringNotContainsString('PAST-31', json_encode($this->expect(200, $this->hugo, 'POST', '/api/sync', [])));

        // The manager's own friends, and strangers, don't even see the size.
        foreach ([$this->marc, $this->stranger] as $token) {
            $this->expect(404, $token, 'GET', "/api/profile_sizes/{$size}");
            self::assertStringNotContainsString('PAST-31', json_encode($this->expect(200, $token, 'POST', '/api/sync', [])));
        }

        // Removing a past value: the manager's alone, acting as the child like every write on its profile.
        $past = $synced[$size]['history'][0]['id'];
        foreach ([$this->hugo, $this->marc, $this->stranger, $this->manager] as $token) {
            $this->expect(404, $token, 'DELETE', "/api/profile_sizes/{$size}/history/{$past}");
        }
        self::assertSame('acting_as.forbidden', $this->expect(403, $this->hugo, 'DELETE', "/api/profile_sizes/{$size}/history/{$past}", null, $this->jules)['code']);
        $this->expect(204, $this->manager, 'DELETE', "/api/profile_sizes/{$size}/history/{$past}", null, $this->jules);
        self::assertSame(['32'], array_column($this->expect(200, $this->manager, 'GET', "/api/profile_sizes/{$size}", null, $this->jules)['history'], 'value'));
    }

    public function testConvertingToAnAutonomousAccountTakesTheSizeHistoryFromTheManager(): void
    {
        $size = $this->childSizeWithHistory();
        $before = $this->expect(200, $this->manager, 'POST', '/api/sync', []);
        sleep(1); // Sync versions have a 1 s resolution.

        $this->expect(202, $this->manager, 'POST', "/api/managed-profiles/{$this->jules}/attach-email", ['email' => 'mv-jules@example.com']);
        $jules = $this->expect(200, $this->stranger, 'POST', '/api/auth/managed-invitation/accept', [
            'email' => 'mv-jules@example.com',
            'code' => $this->invitationCode('mv-jules@example.com'),
            'password' => 'correcthorsebattery',
        ])['token'];

        // The history follows the profile: it is Jules's own now.
        self::assertSame(['PAST-31', '32'], array_column($this->expect(200, $jules, 'GET', "/api/profile_sizes/{$size}")['history'], 'value'));

        // The former manager is a friend like any other…
        self::assertArrayNotHasKey('history', $this->expect(200, $this->manager, 'GET', "/api/profile_sizes/{$size}"));
        // …and their devices replace the document that held it.
        $after = $this->expect(200, $this->manager, 'POST', '/api/sync', ['since' => $before['cursor'], 'hashes' => $before['hashes']]);
        $synced = array_column($after['changes']['profile_size'], null, 'id');
        self::assertArrayHasKey($size, $synced, 'the size is sent again');
        self::assertArrayNotHasKey('history', $synced[$size]);
        self::assertStringNotContainsString('PAST-31', json_encode($after));
    }

    /** A size of Jules's whose value changed once: PAST-31, then 32. */
    private function childSizeWithHistory(): string
    {
        $size = $this->expect(201, $this->manager, 'POST', '/api/profile_sizes', ['label' => 'Pointure', 'value' => 'PAST-31'], $this->jules);
        $this->expect(200, $this->manager, 'PATCH', $size['@id'], ['value' => '32'], $this->jules);

        return basename($size['@id']);
    }

    private function invitationCode(string $email): string
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        foreach (array_reverse($transport->getSent()) as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SendManagedProfileInvitationEmail && $message->email === $email) {
                return $message->code;
            }
        }

        self::fail("No invitation sent to {$email}.");
    }
}
