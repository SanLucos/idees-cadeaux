<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Scheduler\BirthdayReminderTask;
use Doctrine\DBAL\Connection;

/**
 * Lot 5 behaviour (spec §5.11): the in-app centre, consent and
 * preferences gating email and push, one-click unsubscribe, device
 * tokens, birthday reminders. What an owner never receives is in
 * tests/Visibility/NotificationVisibilityTest.
 */
final class NotificationTest extends AuthTestCase
{
    use ApiRequestTrait;
    use NotificationTestTrait;

    private string $alice;
    private string $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->registerVerifyAndLogin('nt-alice@example.com');
        $this->bob = $this->registerVerifyAndLogin('nt-bob@example.com');
        $this->expect(200, $this->alice, 'PATCH', '/api/users/me', ['displayName' => 'Alice']);
        $this->expect(200, $this->bob, 'PATCH', '/api/users/me', ['displayName' => 'Bob']);
    }

    public function testTheInAppCentreListsCountsAndMarksRead(): void
    {
        $this->expect(200, $this->alice, 'POST', '/api/friendships', ['email' => 'nt-bob@example.com']);

        $page = $this->expect(200, $this->bob, 'GET', '/api/notifications');
        self::assertSame(1, $page['unreadCount']);
        self::assertSame('friend_request_received', $page['member'][0]['type']);
        self::assertSame('Alice', $page['member'][0]['payload']['actor']['displayName']);
        self::assertArrayHasKey('id', $page['member'][0]['payload']['friendship'], 'for the deep link');

        $this->expect(404, $this->alice, 'POST', "/api/notifications/{$page['member'][0]['id']}/read", null);
        self::assertNotNull($this->expect(200, $this->bob, 'POST', "/api/notifications/{$page['member'][0]['id']}/read")['readAt']);
        self::assertSame(0, $this->expect(200, $this->bob, 'GET', '/api/notifications/unread-count')['unreadCount']);

        // Accepting notifies the requester; declining never would (règle 4).
        $this->expect(200, $this->bob, 'POST', "/api/friendships/{$page['member'][0]['payload']['friendship']['id']}/accept");
        self::assertSame(['friend_request_accepted'], $this->typesOf($this->alice));
        $this->expect(200, $this->alice, 'POST', '/api/notifications/read-all');
        self::assertSame(0, $this->expect(200, $this->alice, 'GET', '/api/notifications')['unreadCount']);
    }

    public function testEmailAndPushNeedConsentAndFollowPreferences(): void
    {
        // No consent yet: in-app only.
        $this->expect(201, $this->bob, 'POST', '/api/device-tokens', ['token' => 'push-bob', 'platform' => 'ios']);
        $this->expect(200, $this->alice, 'POST', '/api/friendships', ['email' => 'nt-bob@example.com']);
        self::assertSame(['emails' => [], 'pushes' => []], $this->deliver());

        $settings = $this->expect(200, $this->bob, 'PATCH', '/api/notification-settings', ['consents' => ['email' => true, 'push' => true]]);
        self::assertTrue($settings['consents']['email']['granted']);
        self::assertNotNull($settings['consents']['push']['consentedAt'], 'consent is timestamped per channel');

        $this->expect(200, $this->alice, 'POST', "/api/friendships/{$this->incomingId($this->bob)}/cancel");
        $this->cooldownBypass();
        $this->expect(200, $this->alice, 'POST', '/api/friendships', ['email' => 'nt-bob@example.com']);
        $delivered = $this->deliver();
        self::assertCount(1, $delivered['pushes']);
        self::assertSame('push-bob', $delivered['pushes'][0]['token']);
        self::assertSame('Alice vous a envoyé une demande d\'ami.', $delivered['pushes'][0]['body']);
        self::assertCount(1, $delivered['emails']);
        $email = $delivered['emails'][0];
        self::assertSame('nt-bob@example.com', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('/unsubscribe/', (string) $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
        self::assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());

        // Per type × channel.
        $this->expect(200, $this->bob, 'PATCH', '/api/notification-settings', ['preferences' => ['friend_request_received' => ['email' => false, 'push' => false]]]);
        $this->expect(200, $this->alice, 'POST', "/api/friendships/{$this->incomingId($this->bob)}/cancel");
        $this->cooldownBypass();
        $this->expect(200, $this->alice, 'POST', '/api/friendships', ['email' => 'nt-bob@example.com']);
        self::assertSame(['emails' => [], 'pushes' => []], $this->deliver());
        self::assertSame(3, \count($this->notificationsOf($this->bob)), 'still in-app, always on');
    }

    public function testOneClickUnsubscribe(): void
    {
        $this->consentToEverything($this->bob);
        $this->expect(200, $this->alice, 'POST', '/api/friendships', ['email' => 'nt-bob@example.com']);
        $url = (string) $this->deliver()['emails'][0]->getHeaders()->get('List-Unsubscribe')?->getBodyAsString();
        $path = parse_url(trim($url, '<>'), \PHP_URL_PATH);

        // Opening the link changes nothing (mail scanners follow links).
        static::createClient()->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->expect(200, $this->bob, 'GET', '/api/notification-settings')['preferences']['friend_request_received']['email']);

        static::createClient()->request('POST', $path, ['body' => 'List-Unsubscribe=One-Click', 'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']]);
        self::assertResponseIsSuccessful();
        $settings = $this->expect(200, $this->bob, 'GET', '/api/notification-settings');
        self::assertFalse($settings['preferences']['friend_request_received']['email'], 'that type only (spec §11 décision 30)');
        self::assertTrue($settings['consents']['email']['granted']);

        // A real form post (KernelBrowser parameters), as the page's button sends it.
        static::createClient()->getKernelBrowser()->request('POST', $path, ['scope' => 'all']);
        self::assertFalse($this->expect(200, $this->bob, 'GET', '/api/notification-settings')['consents']['email']['granted']);

        static::createClient()->request('GET', preg_replace('#/[^/]+$#', '/forged', $path));
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeviceTokensAreClaimedRemovedAndCleanedWhenInvalid(): void
    {
        $this->consentToEverything($this->bob);
        $this->expect(201, $this->alice, 'POST', '/api/device-tokens', ['token' => 'shared-device', 'platform' => 'android']);
        $this->expect(201, $this->bob, 'POST', '/api/device-tokens', ['token' => 'shared-device', 'platform' => 'android']);
        $this->expect(201, $this->bob, 'POST', '/api/device-tokens', ['token' => 'invalid-old-phone', 'platform' => 'ios']);
        self::assertSame('validation.device_token_invalid', $this->expect(422, $this->bob, 'POST', '/api/device-tokens', ['token' => 'x', 'platform' => 'windows'])['code']);

        $this->expect(200, $this->alice, 'POST', '/api/friendships', ['email' => 'nt-bob@example.com']);
        self::assertSame(['shared-device'], array_column($this->deliver()['pushes'], 'token'), 'the device now belongs to Bob');

        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM device_token WHERE token = 'invalid-old-phone'"), 'FCM said invalid: removed');

        $this->call($this->bob, 'DELETE', '/api/device-tokens', ['token' => 'shared-device']);
        self::assertSame(204, static::getClient()->getResponse()?->getStatusCode());
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM device_token'));
    }

    public function testNotificationsAreTheAdultsOwnNotAChilds(): void
    {
        $jules = $this->expect(201, $this->alice, 'POST', '/api/managed-profiles', ['displayName' => 'Jules', 'parentalConsent' => true])['id'];
        self::assertSame('acting_as.not_allowed', $this->expect(403, $this->alice, 'GET', '/api/notifications', null, $jules)['code']);

        $this->expect(200, $this->alice, 'POST', '/api/friendships', ['email' => 'nt-bob@example.com'], $jules);
        $received = $this->notificationsOf($this->bob)[0];
        self::assertSame('Jules', $received['payload']['actor']['displayName']);
        self::assertSame('Alice', $received['payload']['actor']['managedBy'], '« profil géré par Alice »');
    }

    public function testBirthdayRemindersFollowEachRecipientsDelaysAndTimezone(): void
    {
        $this->befriend($this->alice, 'nt-alice@example.com', $this->bob, 'nt-bob@example.com');
        $this->expect(200, $this->alice, 'PATCH', '/api/users/me', ['birthDay' => 7, 'birthMonth' => 10]);
        $task = self::getContainer()->get(BirthdayReminderTask::class);
        $paris = new \DateTimeZone('Europe/Paris');

        // J-14 at 09:00 Paris: Bob (default delays 14 and 2) is reminded, once.
        self::assertSame(0, $task(new \DateTimeImmutable('2026-09-23 08:00', $paris)), 'not before 9');
        self::assertSame(1, $task(new \DateTimeImmutable('2026-09-23 09:00', $paris)));
        self::assertSame(0, $task(new \DateTimeImmutable('2026-09-23 09:30', $paris)), 'deduplicated');
        self::assertSame(0, $task(new \DateTimeImmutable('2026-09-24 09:00', $paris)), 'J-13 is not a chosen delay');
        $reminder = $this->notificationsOf($this->bob)[0];
        self::assertSame(['type' => 'birthday_reminder', 'days' => 14, 'date' => '2026-10-07'], ['type' => $reminder['type'], 'days' => $reminder['payload']['days'], 'date' => $reminder['payload']['date']]);

        // Custom delays, validated.
        self::assertSame('validation.birthday_reminders_invalid', $this->expect(422, $this->bob, 'PATCH', '/api/notification-settings', ['birthdayReminderDays' => [1, 2, 3, 4]])['code']);
        self::assertSame('validation.birthday_reminders_invalid', $this->expect(422, $this->bob, 'PATCH', '/api/notification-settings', ['birthdayReminderDays' => [31]])['code']);
        self::assertSame([13, 0], $this->expect(200, $this->bob, 'PATCH', '/api/notification-settings', ['birthdayReminderDays' => [0, 13]])['birthdayReminderDays']);
        self::assertSame(1, $task(new \DateTimeImmutable('2026-09-24 09:00', $paris)));
        self::assertSame(1, $task(new \DateTimeImmutable('2026-10-07 09:00', $paris)), 'J-0, the day itself');

        // Off entirely.
        $this->expect(200, $this->bob, 'PATCH', '/api/notification-settings', ['birthdayReminderDays' => []]);
        self::assertSame(0, $task(new \DateTimeImmutable('2027-09-23 09:00', $paris)));
    }

    public function testRemindersUseTheRecipientsTimezoneAndTheLeapDayRule(): void
    {
        $this->befriend($this->alice, 'nt-alice@example.com', $this->bob, 'nt-bob@example.com');
        $this->expect(200, $this->alice, 'PATCH', '/api/users/me', ['birthDay' => 29, 'birthMonth' => 2]);
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE app_user SET timezone = 'America/New_York' WHERE email = 'nt-bob@example.com'",
        );
        $task = self::getContainer()->get(BirthdayReminderTask::class);

        // 2027 isn't a leap year: celebrated 28 Feb, so J-2 is 26 Feb — at 9:00 in New York.
        self::assertSame(0, $task(new \DateTimeImmutable('2027-02-26 09:00', new \DateTimeZone('Europe/Paris'))));
        self::assertSame(1, $task(new \DateTimeImmutable('2027-02-26 09:00', new \DateTimeZone('America/New_York'))));
        self::assertSame('2027-02-28', $this->notificationsOf($this->bob)[0]['payload']['date']);
    }

    private function incomingId(string $token): string
    {
        return $this->expect(200, $token, 'GET', '/api/friendships/incoming')[0]['id'];
    }

    /** A cancelled request starts the 30-day cooldown (spec §5.3): backdate it for the test. */
    private function cooldownBypass(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE friendship SET created_at = created_at - INTERVAL '31 days'");
    }
}
