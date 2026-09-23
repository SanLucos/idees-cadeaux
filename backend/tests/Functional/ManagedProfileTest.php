<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Message\SendManagedProfileInvitationEmail;
use App\Scheduler\DeleteScheduledProfilesTask;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Lot 4 bis behaviour (spec §5.15): managed profiles, acting on their
 * behalf, friendships in their name, email attachment, deletion with
 * grace. Who-sees-what is in tests/Visibility/ManagedProfileVisibilityTest.
 */
final class ManagedProfileTest extends AuthTestCase
{
    use ApiRequestTrait;

    private string $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->registerVerifyAndLogin('mp-manager@example.com');
        $this->expect(200, $this->manager, 'PATCH', '/api/users/me', ['displayName' => 'Luc']);
    }

    public function testCreationNeedsConsentAndIsCappedAtTen(): void
    {
        self::assertSame('managed_profile.consent_required', $this->expect(422, $this->manager, 'POST', '/api/managed-profiles', ['displayName' => 'Jules'])['code']);
        self::assertSame('validation.display_name_invalid', $this->expect(422, $this->manager, 'POST', '/api/managed-profiles', ['displayName' => 'J', 'parentalConsent' => true])['code']);

        $jules = $this->expect(201, $this->manager, 'POST', '/api/managed-profiles', [
            'id' => '0190a1b2-0000-7000-8000-0000000c0001',
            'displayName' => 'Jules',
            'birthDay' => 3,
            'birthMonth' => 5,
            'parentalConsent' => true,
        ]);
        self::assertSame('Jules', $jules['displayName']);
        self::assertSame(3, $jules['birthDay']);
        self::assertNotNull($jules['parentalConsentAt'], 'consent is timestamped (spec §5.13)');
        self::assertSame('Luc', $jules['managedBy']['displayName']);
        $this->expect(200, $this->manager, 'POST', '/api/managed-profiles', ['id' => $jules['id'], 'displayName' => 'Jules', 'parentalConsent' => true]);

        for ($i = 2; $i <= 10; ++$i) {
            $this->expect(201, $this->manager, 'POST', '/api/managed-profiles', ['displayName' => "Enfant {$i}", 'parentalConsent' => true]);
        }
        self::assertSame('managed_profile.limit_reached', $this->expect(422, $this->manager, 'POST', '/api/managed-profiles', ['displayName' => 'Onze', 'parentalConsent' => true])['code']);
        self::assertCount(10, $this->expect(200, $this->manager, 'GET', '/api/managed-profiles'));

        $other = $this->registerVerifyAndLogin('mp-other@example.com');
        $this->expect(404, $other, 'PATCH', "/api/managed-profiles/{$jules['id']}", ['displayName' => 'Hacked']);
        self::assertSame([], $this->expect(200, $other, 'GET', '/api/managed-profiles'));
    }

    public function testActingAsTheChildAttributesEverythingToIt(): void
    {
        $jules = $this->child('Jules');

        $me = $this->expect(200, $this->manager, 'GET', '/api/users/me', null, $jules);
        self::assertSame($jules, $me['id']);
        self::assertSame('managed', $me['type']);
        self::assertNull($me['email']);

        $this->expect(200, $this->manager, 'PATCH', '/api/users/me', ['displayName' => 'Julot'], $jules);
        self::assertSame('Julot', $this->expect(200, $this->manager, 'GET', '/api/managed-profiles')[0]['displayName']);

        $idea = $this->expect(201, $this->manager, 'POST', '/api/ideas', ['title' => 'Lego'], $jules);
        self::assertSame($jules, $idea['ownerId']);
        self::assertTrue($idea['canEdit']);
        $this->expect(200, $this->manager, 'POST', "/api/ideas/{$idea['id']}/archive", ['kind' => 'received'], $jules);
        self::assertSame(1, $this->expect(200, $this->manager, 'GET', '/api/users/me/ideas', null, $jules)['counts']['archived']);

        // Not acting, the manager reads it but it isn't theirs to edit.
        self::assertFalse($this->expect(200, $this->manager, 'GET', "/api/ideas/{$idea['id']}")['canEdit']);
        self::assertSame(0, $this->expect(200, $this->manager, 'GET', '/api/users/me/ideas')['totalItems'], 'the manager\'s own list is untouched');
    }

    public function testTheManagerMaySuggestAndInteractOnTheChildsListInTheirOwnName(): void
    {
        $jules = $this->child('Jules');
        $idea = $this->expect(201, $this->manager, 'POST', '/api/ideas', ['title' => 'Lego'], $jules);

        $suggestion = $this->expect(201, $this->manager, 'POST', '/api/ideas', ['title' => 'Vélo', 'ownerId' => $jules]);
        self::assertSame('manager', $suggestion['view']);
        self::assertTrue($suggestion['isMine']);

        $view = $this->expect(201, $this->manager, 'POST', '/api/reservations', ['ideaId' => $idea['id']]);
        self::assertTrue($view['reservation']['isMine']);
        self::assertSame('manager', $view['view']);
    }

    public function testFriendRequestsOnBehalfOfTheChildShowItsManager(): void
    {
        $jules = $this->child('Jules');
        $hugo = $this->registerVerifyAndLogin('mp-hugo@example.com');

        $this->expect(200, $this->manager, 'POST', '/api/friendships', ['email' => 'mp-hugo@example.com'], $jules);
        $incoming = $this->expect(200, $hugo, 'GET', '/api/friendships/incoming');
        self::assertSame('Jules', $incoming[0]['user']['displayName']);
        self::assertSame('Luc', $incoming[0]['user']['managedBy']['displayName'], '« profil géré par Luc »');

        self::assertCount(1, $this->expect(200, $this->manager, 'GET', '/api/friendships/outgoing', null, $jules));
        self::assertSame([], $this->expect(200, $this->manager, 'GET', '/api/friendships/outgoing'), 'the request is the child\'s, not the manager\'s');

        $this->expect(200, $hugo, 'POST', "/api/friendships/{$incoming[0]['id']}/accept");
        self::assertCount(1, $this->expect(200, $this->manager, 'GET', '/api/friendships', null, $jules));
    }

    public function testAttachingAnEmailConvertsTheProfileWithACode(): void
    {
        $jules = $this->child('Jules');
        $this->registerVerifyAndLogin('mp-taken@example.com');

        // Same answer whether or not the email already has an account.
        $pending = $this->expect(202, $this->manager, 'POST', "/api/managed-profiles/{$jules}/attach-email", ['email' => 'mp-taken@example.com']);
        self::assertSame('mp-taken@example.com', $pending['invitation']['email']);
        $code = $this->code('mp-taken@example.com');
        self::assertSame('auth.email_already_registered', $this->expect(409, $this->manager, 'POST', '/api/auth/managed-invitation/accept', [
            'email' => 'mp-taken@example.com', 'code' => $code, 'password' => 'correcthorsebattery',
        ])['code']);

        // A new invitation replaces the previous one. (Read the code right away:
        // the in-memory transport is reset with each request's kernel.)
        $this->expect(202, $this->manager, 'POST', "/api/managed-profiles/{$jules}/attach-email", ['email' => 'mp-jules@example.com']);
        $code = $this->code('mp-jules@example.com');
        self::assertSame('invitation.invalid', $this->expect(422, $this->manager, 'POST', '/api/auth/managed-invitation/accept', [
            'email' => 'mp-jules@example.com', 'code' => '000000', 'password' => 'correcthorsebattery',
        ])['code']);
        self::assertSame('validation.password_too_short', $this->expect(422, $this->manager, 'POST', '/api/auth/managed-invitation/accept', [
            'email' => 'mp-jules@example.com', 'code' => $code, 'password' => 'short',
        ])['code']);

        $tokens = static::createClient()->request('POST', '/api/auth/managed-invitation/accept', ['json' => [
            'email' => 'mp-jules@example.com', 'code' => $code, 'password' => 'correcthorsebattery',
        ]])->toArray();
        self::assertArrayHasKey('refresh_token', $tokens);

        $me = $this->expect(200, $tokens['token'], 'GET', '/api/users/me');
        self::assertSame($jules, $me['id'], 'same profile: ideas, friends and sizes are kept');
        self::assertSame('regular', $me['type']);
        self::assertTrue($me['emailVerified']);
        $this->login('mp-jules@example.com');
    }

    public function testDeletionHasFourteenDaysOfGraceThenErasesTheProfile(): void
    {
        $jules = $this->child('Jules');
        $this->expect(201, $this->manager, 'POST', '/api/ideas', ['title' => 'Lego'], $jules);

        $scheduled = $this->expect(200, $this->manager, 'DELETE', "/api/managed-profiles/{$jules}");
        self::assertEqualsWithDelta((new \DateTimeImmutable('+14 days'))->getTimestamp(), (new \DateTimeImmutable($scheduled['deletionScheduledAt']))->getTimestamp(), 60);
        self::assertSame('acting_as.forbidden', $this->expect(403, $this->manager, 'GET', '/api/users/me', null, $jules)['code'], 'suspended during the grace period');

        $this->expect(200, $this->manager, 'POST', "/api/managed-profiles/{$jules}/cancel-deletion");
        $this->expect(200, $this->manager, 'GET', '/api/users/me', null, $jules);

        $this->expect(200, $this->manager, 'DELETE', "/api/managed-profiles/{$jules}");
        $task = self::getContainer()->get(DeleteScheduledProfilesTask::class);
        self::assertSame(0, $task(), 'nothing before the grace period ends');

        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE app_user SET deletion_scheduled_at = NOW() - INTERVAL '1 minute' WHERE id = :id",
            ['id' => $jules],
        );
        self::assertSame(1, $task());
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_user WHERE id = :id', ['id' => $jules]));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM idea WHERE owner_id = :id', ['id' => $jules]), 'its ideas go with it');
        self::assertSame([], $this->expect(200, $this->manager, 'GET', '/api/managed-profiles'));
    }

    private function child(string $name): string
    {
        return $this->expect(201, $this->manager, 'POST', '/api/managed-profiles', ['displayName' => $name, 'parentalConsent' => true])['id'];
    }

    private function code(string $email): string
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
