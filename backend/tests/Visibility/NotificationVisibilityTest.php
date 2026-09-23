<?php

declare(strict_types=1);

namespace App\Tests\Visibility;

use App\Scheduler\BirthdayReminderTask;
use App\Tests\Functional\ApiRequestTrait;
use App\Tests\Functional\AuthTestCase;
use App\Tests\Functional\NotificationTestTrait;
use Doctrine\DBAL\Connection;

/**
 * Lot 5 cases of the visibility suite (spec §4 "Conséquences" 3–4,
 * §5.11 "aucune notification ne doit révéler au propriétaire une
 * activité sur ses idées"): through every channel — every email or push
 * hangs off a `notification` row, so the table is the reference — the
 * owner is never told anything about suggestions, reservations,
 * comments, contributions or likes on their list; no notification ever
 * carries an amount (règle 3); private ideas notify nobody (règle 2);
 * a child's notifications reach its manager without opening the
 * child's list to them.
 */
final class NotificationVisibilityTest extends AuthTestCase
{
    use ApiRequestTrait;
    use NotificationTestTrait;

    private const array FRIENDSHIP_TYPES = ['friend_request_received', 'friend_request_accepted'];

    private string $owner;
    private string $hugo;
    private string $lea;
    private string $ownerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->registerVerifyAndLogin('nv-owner@example.com');
        $this->hugo = $this->registerVerifyAndLogin('nv-hugo@example.com');
        $this->lea = $this->registerVerifyAndLogin('nv-lea@example.com');
        foreach (['owner' => $this->owner, 'hugo' => $this->hugo, 'lea' => $this->lea] as $name => $token) {
            $this->expect(200, $token, 'PATCH', '/api/users/me', ['displayName' => ucfirst($name)]);
            // Every channel open: nothing may slip through any of them.
            $this->consentToEverything($token, "push-{$name}");
        }
        $this->befriend($this->owner, 'nv-owner@example.com', $this->hugo, 'nv-hugo@example.com');
        $this->befriend($this->owner, 'nv-owner@example.com', $this->lea, 'nv-lea@example.com');
        $this->ownerId = $this->userId($this->owner);
    }

    public function testTheOwnerIsNeverNotifiedAboutAnythingOnTheirList(): void
    {
        $personal = $this->expect(201, $this->owner, 'POST', '/api/ideas', ['title' => 'Casque', 'priceAmount' => '100']);
        $suggestion = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Surprise', 'ownerId' => $this->ownerId, 'priceAmount' => '50']);
        $second = $this->expect(201, $this->lea, 'POST', '/api/ideas', ['title' => 'Autre', 'ownerId' => $this->ownerId]);

        $this->expect(201, $this->lea, 'POST', '/api/reservations', ['ideaId' => $personal['id']]);
        $this->expect(201, $this->hugo, 'POST', '/api/comments', ['ideaId' => $personal['id'], 'body' => 'Top']);
        $this->expect(201, $this->lea, 'POST', '/api/comments', ['ideaId' => $personal['id'], 'body' => 'Oui']);
        $this->expect(200, $this->lea, 'PUT', "/api/ideas/{$personal['id']}/reaction");
        $pool = $this->expect(201, $this->hugo, 'POST', '/api/contributions', ['ideaId' => $suggestion['id']]);
        $this->expect(200, $this->lea, 'PUT', "/api/contributions/{$pool['id']}/pledge", ['amount' => '30']);
        $this->expect(200, $this->hugo, 'PUT', "/api/contributions/{$pool['id']}/pledge", ['amount' => '20']);
        $this->expect(201, $this->hugo, 'POST', '/api/reservations', ['ideaId' => $second['id']]);
        $this->expect(200, $this->lea, 'POST', "/api/ideas/{$second['id']}/unpublish");
        $this->expect(200, $this->hugo, 'POST', "/api/ideas/{$suggestion['id']}/archive", ['kind' => 'gifted']);

        // The table, not just the in-app list: every channel hangs off a row.
        $ownerTypes = self::getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT DISTINCT type FROM notification WHERE user_id = :owner',
            ['owner' => $this->ownerId],
        );
        self::assertSame([], array_diff($ownerTypes, self::FRIENDSHIP_TYPES), 'the owner may only hear about their friendships');

        // Meanwhile the people involved were told.
        $leaTypes = $this->typesOf($this->lea);
        foreach (['suggestion_published', 'comment_added', 'pledge_added', 'contribution_goal_reached', 'suggestion_gifted'] as $expected) {
            self::assertContains($expected, $leaTypes);
        }
        self::assertNotContains('contribution_opened', $leaTypes, 'Léa had no part in that suggestion when it opened');
        self::assertContains('idea_reserved', $leaTypes, 'Hugo reserved Léa\'s suggestion: its author is told');
        self::assertContains('idea_unpublished', $this->typesOf($this->hugo), 'the reserver learns the idea went private');
    }

    public function testNoNotificationEverCarriesAnAmount(): void
    {
        $idea = $this->expect(201, $this->owner, 'POST', '/api/ideas', ['title' => 'Casque', 'priceAmount' => '180']);
        $pool = $this->expect(201, $this->hugo, 'POST', '/api/contributions', ['ideaId' => $idea['id'], 'targetAmount' => '77.77']);
        $this->expect(200, $this->lea, 'PUT', "/api/contributions/{$pool['id']}/pledge", ['amount' => '43.21']);
        $delivered = $this->deliver();
        self::assertNotEmpty($delivered['pushes'], 'the pledge was pushed to Hugo');

        $payloads = self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT payload FROM notification');
        $outgoing = [...$payloads, ...array_column($delivered['pushes'], 'body'), ...array_map(static fn ($e) => (string) $e->getHtmlBody(), $delivered['emails'])];
        foreach ($outgoing as $text) {
            foreach (['43.21', '43,21', '77.77', '77,77'] as $amount) {
                self::assertStringNotContainsString($amount, (string) $text, 'no pledge or target amount (règle 3)');
            }
            self::assertStringNotContainsStringIgnoringCase('amount', (string) $text);
        }
    }

    public function testPrivateIdeasNotifyNobodyAndPublishingNotifiesOnce(): void
    {
        $draft = $this->expect(201, $this->owner, 'POST', '/api/ideas', ['title' => 'Draft', 'visibility' => 'private']);
        $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Hugo draft', 'ownerId' => $this->ownerId, 'visibility' => 'private']);
        foreach ([$this->hugo, $this->lea] as $token) {
            self::assertSame([], array_values(array_diff($this->typesOf($token), self::FRIENDSHIP_TYPES)));
        }

        $this->expect(200, $this->owner, 'POST', "/api/ideas/{$draft['id']}/publish");
        $this->expect(200, $this->owner, 'POST', "/api/ideas/{$draft['id']}/unpublish");
        $this->expect(200, $this->owner, 'POST', "/api/ideas/{$draft['id']}/publish");
        self::assertSame(1, \count(array_keys($this->typesOf($this->lea), 'idea_published', true)), 'once per idea, however often republished');
    }

    public function testAChildsNotificationsReachItsManagerWithoutOpeningItsList(): void
    {
        $jules = $this->expect(201, $this->owner, 'POST', '/api/managed-profiles', ['displayName' => 'Jules', 'parentalConsent' => true])['id'];
        $this->expect(200, $this->owner, 'POST', '/api/friendships', ['email' => 'nv-hugo@example.com'], $jules);
        $request = array_values(array_filter($this->expect(200, $this->hugo, 'GET', '/api/friendships/incoming'), static fn ($f) => 'Jules' === $f['user']['displayName']))[0];
        $this->expect(200, $this->hugo, 'POST', "/api/friendships/{$request['id']}/accept");

        $accepted = array_values(array_filter($this->notificationsOf($this->owner), static fn ($n) => 'friend_request_accepted' === $n['type'] && isset($n['payload']['subject'])));
        self::assertCount(1, $accepted);
        self::assertSame('Jules', $accepted[0]['payload']['subject']['displayName'], '« Pour Jules »');

        // Activity on the child's list is the child's (owner) business: not relayed to the manager.
        $idea = $this->expect(201, $this->owner, 'POST', '/api/ideas', ['title' => 'Lego'], $jules);
        $this->expect(201, $this->hugo, 'POST', '/api/reservations', ['ideaId' => $idea['id']]);
        $this->expect(201, $this->hugo, 'POST', '/api/comments', ['ideaId' => $idea['id'], 'body' => 'Je prends']);
        self::assertSame([], array_values(array_diff($this->typesOf($this->owner), self::FRIENDSHIP_TYPES)));

        // …unless the manager took part in their own name, like anyone else.
        $this->expect(201, $this->owner, 'POST', '/api/comments', ['ideaId' => $idea['id'], 'body' => 'Merci Hugo']);
        $this->expect(201, $this->hugo, 'POST', '/api/comments', ['ideaId' => $idea['id'], 'body' => 'Avec plaisir']);
        self::assertContains('comment_added', $this->typesOf($this->owner));
    }

    public function testABirthdayIsNeverAnnouncedToThePersonThemself(): void
    {
        $this->expect(200, $this->owner, 'PATCH', '/api/users/me', ['birthDay' => 7, 'birthMonth' => 10]);

        // 2026-09-23 09:00 in Paris: 14 days before 7 October.
        $sent = (self::getContainer()->get(BirthdayReminderTask::class))(new \DateTimeImmutable('2026-09-23 09:00', new \DateTimeZone('Europe/Paris')));

        self::assertSame(2, $sent, 'Hugo and Léa');
        self::assertContains('birthday_reminder', $this->typesOf($this->hugo));
        self::assertNotContains('birthday_reminder', $this->typesOf($this->owner));
    }
}
