<?php

declare(strict_types=1);

namespace App\Tests\Visibility;

use App\Entity\Enum\NotificationType;
use App\Tests\Functional\ApiRequestTrait;
use App\Tests\Functional\AuthTestCase;
use App\Tests\Functional\DataExportTestTrait;
use App\Tests\Functional\NotificationTestTrait;

/**
 * Lot 15 cases of the visibility suite (spec §11 décision 69): an idea
 * for several people is one idea, every recipient is its owner. None of
 * them ever sees a suggestion made for them, or the interactions on it —
 * not from another recipient's list, a counter, a notification, /sync,
 * the export or the guest view; a friend of a single recipient sees it.
 *
 * Cast: Anne and Luc (friends, the recipients); Paul, friend of both;
 * Marc, Anne's friend only; Zoé, Luc's friend only; Stranger.
 */
final class SharedIdeaVisibilityTest extends AuthTestCase
{
    use ApiRequestTrait;
    use DataExportTestTrait;
    use NotificationTestTrait;

    private string $anne;
    private string $luc;
    private string $paul;
    private string $marc;
    private string $zoe;
    private string $stranger;
    private string $anneId;
    private string $lucId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['anne', 'luc', 'paul', 'marc', 'zoe', 'stranger'] as $name) {
            $this->{$name} = $this->registerVerifyAndLogin("sh-{$name}@example.com");
            $this->expect(200, $this->{$name}, 'PATCH', '/api/users/me', ['displayName' => ucfirst($name)]);
        }
        foreach ([['anne', 'luc'], ['anne', 'paul'], ['luc', 'paul'], ['anne', 'marc'], ['luc', 'zoe']] as [$a, $b]) {
            $this->befriend($this->{$a}, "sh-{$a}@example.com", $this->{$b}, "sh-{$b}@example.com");
        }
        $this->anneId = $this->userId($this->anne);
        $this->lucId = $this->userId($this->luc);
    }

    public function testASharedSuggestionIsHiddenFromEveryRecipientEverywhere(): void
    {
        $suggestion = $this->expect(201, $this->paul, 'POST', '/api/ideas', ['title' => 'SECRET airfryer', 'recipientIds' => [$this->anneId, $this->lucId]]);
        self::assertTrue($suggestion['isSuggestion']);
        self::assertEqualsCanonicalizing([$this->anneId, $this->lucId], array_column($suggestion['recipients'], 'id'));
        $id = $suggestion['id'];
        $reservation = $this->expect(201, $this->marc, 'POST', '/api/reservations', ['ideaId' => $id])['reservation'];
        $this->expect(201, $this->zoe, 'POST', '/api/comments', ['ideaId' => $id, 'body' => 'SECRET comment']);

        foreach ([$this->anne, $this->luc] as $recipient) {
            $this->expect(404, $recipient, 'GET', "/api/ideas/{$id}");
            $this->expect(404, $recipient, 'GET', "/api/ideas/{$id}/comments");
            $this->expect(404, $recipient, 'POST', '/api/reservations', ['ideaId' => $id]);
            $this->expect(404, $recipient, 'DELETE', "/api/reservations/{$reservation['id']}");
            self::assertSame(0, $this->expect(200, $recipient, 'GET', '/api/users/me/ideas')['totalItems']);
            $sync = json_encode($this->expect(200, $recipient, 'POST', '/api/sync', []));
            self::assertStringNotContainsString($id, (string) $sync);
            self::assertStringNotContainsString('SECRET', (string) $sync);
        }

        // Each recipient reading the other's list as a friend: still nothing, counter included.
        self::assertSame(0, $this->expect(200, $this->luc, 'GET', "/api/users/{$this->anneId}/ideas")['totalItems']);
        self::assertSame(0, $this->expect(200, $this->anne, 'GET', "/api/users/{$this->lucId}/ideas?kind=suggestion")['totalItems']);
        foreach ($this->expect(200, $this->luc, 'GET', '/api/friendships') as $friendship) {
            self::assertSame(0, $friendship['ideaCount'], 'no suggestion counted for a co-recipient');
        }

        // A friend of a single recipient sees it, on that recipient's list.
        self::assertSame([$id], array_column($this->expect(200, $this->marc, 'GET', "/api/users/{$this->anneId}/ideas")['member'], 'id'));
        self::assertSame([$id], array_column($this->expect(200, $this->zoe, 'GET', "/api/users/{$this->lucId}/ideas")['member'], 'id'));
        $marcView = $this->expect(200, $this->marc, 'GET', "/api/ideas/{$id}");
        self::assertSame('friend', $marcView['view']);
        self::assertSame($reservation['id'], $marcView['reservation']['id']);
        self::assertSame(1, $this->expect(200, $this->zoe, 'GET', "/api/ideas/{$id}")['commentCount']);
        $this->expect(404, $this->stranger, 'GET', "/api/ideas/{$id}");
    }

    public function testNewSuggestionNotifiesFriendsOfAnyRecipientOnceAndNeverARecipient(): void
    {
        $this->expect(201, $this->paul, 'POST', '/api/ideas', ['title' => 'SECRET airfryer', 'recipientIds' => [$this->anneId, $this->lucId]]);
        $this->deliver();

        $published = NotificationType::SuggestionPublished->value;
        foreach ([$this->anne, $this->luc, $this->paul, $this->stranger] as $token) {
            self::assertNotContains($published, $this->typesOf($token));
        }
        foreach ([$this->marc, $this->zoe] as $token) {
            $notifications = array_values(array_filter($this->notificationsOf($token), static fn (array $n) => $published === $n['type']));
            self::assertCount(1, $notifications);
            self::assertEqualsCanonicalizing(['Anne', 'Luc'], array_column($notifications[0]['payload']['recipients'], 'displayName'));
        }
    }

    public function testASharedPersonalIdeaIsEachRecipientsOwnWithNoTraceOfInteractions(): void
    {
        $idea = $this->expect(201, $this->anne, 'POST', '/api/ideas', ['title' => 'Airfryer', 'recipientIds' => [$this->anneId, $this->lucId]]);
        $id = $idea['id'];
        self::assertSame('owner', $idea['view']);
        $this->deliver();
        $published = NotificationType::IdeaPublished->value;
        self::assertCount(1, array_filter($this->typesOf($this->paul), static fn (string $t) => $published === $t), 'one notification for a friend of both');
        self::assertNotContains($published, $this->typesOf($this->luc));

        $this->expect(201, $this->marc, 'POST', '/api/reservations', ['ideaId' => $id]);
        $this->expect(200, $this->zoe, 'PUT', "/api/ideas/{$id}/reaction");
        $this->expect(201, $this->paul, 'POST', '/api/comments', ['ideaId' => $id, 'body' => 'SECRET comment']);

        $lucOwn = $this->expect(200, $this->luc, 'GET', '/api/users/me/ideas');
        self::assertSame([$id], array_column($lucOwn['member'], 'id'));
        self::assertSame(1, $lucOwn['counts']['published']);
        $views = [
            $lucOwn['member'][0],
            $this->expect(200, $this->luc, 'GET', "/api/ideas/{$id}"),
            // Luc reading Anne's list: the common idea is his own there too.
            $this->expect(200, $this->luc, 'GET', "/api/users/{$this->anneId}/ideas")['member'][0],
            $this->expect(200, $this->anne, 'GET', "/api/users/{$this->lucId}/ideas")['member'][0],
        ];
        foreach ($views as $view) {
            self::assertSame('owner', $view['view']);
            foreach (['reservation', 'contribution', 'reactions', 'commentCount', 'author', 'isSuggestion'] as $field) {
                self::assertArrayNotHasKey($field, $view);
            }
        }
        self::assertFalse($views[1]['isMine']);
        self::assertTrue($views[1]['canEdit']);

        foreach ([$this->anne, $this->luc] as $recipient) {
            $this->expect(404, $recipient, 'GET', "/api/ideas/{$id}/comments");
            $this->expect(404, $recipient, 'PUT', "/api/ideas/{$id}/reaction");
            $sync = (string) json_encode($this->expect(200, $recipient, 'POST', '/api/sync', []));
            self::assertStringNotContainsString('SECRET', $sync);
            self::assertStringNotContainsString('"reservation"', $sync);
        }

        // Friends of one recipient only: the friend view, with every recipient named.
        $zoeView = $this->expect(200, $this->zoe, 'GET', "/api/ideas/{$id}");
        self::assertSame('friend', $zoeView['view']);
        self::assertNotNull($zoeView['reservation']);
        self::assertEqualsCanonicalizing(['Anne', 'Luc'], array_column($zoeView['recipients'], 'displayName'));
    }

    public function testEveryRecipientEditsAndMarksReceivedButOnlyTheAuthorPublishes(): void
    {
        $id = $this->expect(201, $this->anne, 'POST', '/api/ideas', ['title' => 'Airfryer', 'recipientIds' => [$this->anneId, $this->lucId]])['id'];

        self::assertSame('Airfryer XL', $this->expect(200, $this->luc, 'PATCH', "/api/ideas/{$id}", ['title' => 'Airfryer XL'])['title']);
        $this->expect(403, $this->luc, 'POST', "/api/ideas/{$id}/unpublish");
        $this->expect(403, $this->marc, 'PATCH', "/api/ideas/{$id}", ['title' => 'Nope']);

        // Recipients are frozen once published, and only ever the author's.
        $this->expect(403, $this->luc, 'PATCH', "/api/ideas/{$id}", ['recipientIds' => [$this->lucId]]);
        self::assertSame('idea.recipients_locked', $this->expect(422, $this->anne, 'PATCH', "/api/ideas/{$id}", ['recipientIds' => [$this->anneId, $this->lucId, $this->userId($this->paul)]])['code']);
        $this->expect(200, $this->anne, 'POST', "/api/ideas/{$id}/unpublish");
        $this->expect(404, $this->luc, 'GET', "/api/ideas/{$id}");
        $draft = $this->expect(200, $this->anne, 'PATCH', "/api/ideas/{$id}", ['recipientIds' => [$this->anneId, $this->lucId, $this->userId($this->paul)]]);
        self::assertCount(3, $draft['recipients']);
        $this->expect(200, $this->anne, 'POST', "/api/ideas/{$id}/publish");

        // "Reçue" by one recipient is for all; only they can undo it.
        $this->expect(200, $this->luc, 'POST', "/api/ideas/{$id}/archive", ['kind' => 'received']);
        $anneView = $this->expect(200, $this->anne, 'GET', "/api/ideas/{$id}");
        self::assertSame('archived', $anneView['status']);
        self::assertFalse($anneView['canUnarchive']);
        $this->expect(403, $this->anne, 'POST', "/api/ideas/{$id}/unarchive");
        $this->expect(200, $this->luc, 'POST', "/api/ideas/{$id}/unarchive");

        $this->expect(204, $this->luc, 'DELETE', "/api/ideas/{$id}");
        $this->expect(404, $this->anne, 'GET', "/api/ideas/{$id}");
    }

    public function testOnlyFriendsAndChildrenCanBeNamedRecipients(): void
    {
        $zoeId = $this->userId($this->zoe);
        $this->expect(404, $this->anne, 'POST', '/api/ideas', ['title' => 'Nope', 'recipientIds' => [$this->anneId, $zoeId]]);
        $this->expect(404, $this->anne, 'POST', '/api/ideas', ['title' => 'Nope', 'recipientIds' => [$this->anneId, '0190a1b2-0000-7000-8000-00000000ffff']]);
        $this->expect(422, $this->anne, 'POST', '/api/ideas', ['title' => 'Nope', 'recipientIds' => []]);

        // Publishing needs a friendship with each recipient, still.
        $draft = $this->expect(201, $this->paul, 'POST', '/api/ideas', ['title' => 'Draft', 'visibility' => 'private', 'recipientIds' => [$this->anneId, $this->lucId]]);
        $friendship = array_values(array_filter($this->expect(200, $this->paul, 'GET', '/api/friendships'), fn (array $f) => $f['user']['id'] === $this->lucId))[0];
        $this->expect(200, $this->paul, 'DELETE', "/api/friendships/{$friendship['id']}");
        self::assertSame('idea.friendship_required', $this->expect(422, $this->paul, 'POST', "/api/ideas/{$draft['id']}/publish")['code']);
    }

    public function testFriendRemovalCancelsActionsOnlyWithoutAnyRemainingAccess(): void
    {
        $id = $this->expect(201, $this->anne, 'POST', '/api/ideas', ['title' => 'Airfryer', 'recipientIds' => [$this->anneId, $this->lucId]])['id'];
        $other = $this->expect(201, $this->anne, 'POST', '/api/ideas', ['title' => 'Lamp', 'recipientIds' => [$this->anneId, $this->lucId]])['id'];
        $this->expect(201, $this->paul, 'POST', '/api/reservations', ['ideaId' => $id]);
        $this->expect(201, $this->marc, 'POST', '/api/reservations', ['ideaId' => $other]);

        // Paul leaves Anne but stays Luc's friend: his reservation stands.
        $paulAnne = array_values(array_filter($this->expect(200, $this->paul, 'GET', '/api/friendships'), fn (array $f) => $f['user']['id'] === $this->anneId))[0];
        $this->expect(200, $this->paul, 'DELETE', "/api/friendships/{$paulAnne['id']}");
        self::assertNotNull($this->expect(200, $this->paul, 'GET', "/api/ideas/{$id}")['reservation']);

        // Marc was Anne's friend only: his goes.
        $marcAnne = $this->expect(200, $this->marc, 'GET', '/api/friendships')[0];
        $this->expect(200, $this->marc, 'DELETE', "/api/friendships/{$marcAnne['id']}");
        self::assertNull($this->expect(200, $this->paul, 'GET', "/api/ideas/{$other}")['reservation']);
    }

    public function testAManagerWhoIsARecipientNeverGetsTheManagerView(): void
    {
        // Paul is Anne's friend: « Partager avec mes amis » makes him Léa's too.
        $lea = $this->expect(201, $this->anne, 'POST', '/api/managed-profiles', ['displayName' => 'Léa', 'parentalConsent' => true])['id'];
        $this->expect(200, $this->anne, 'PATCH', "/api/managed-profiles/{$lea}", ['shareWithManagerFriends' => true]);
        $forBoth = $this->expect(201, $this->paul, 'POST', '/api/ideas', ['title' => 'SECRET bike', 'recipientIds' => [$lea, $this->anneId]])['id'];
        $forLea = $this->expect(201, $this->paul, 'POST', '/api/ideas', ['title' => 'Kite', 'ownerId' => $lea])['id'];

        $this->expect(404, $this->anne, 'GET', "/api/ideas/{$forBoth}");
        $this->expect(404, $this->anne, 'GET', "/api/ideas/{$forBoth}", null, $lea);
        self::assertSame('manager', $this->expect(200, $this->anne, 'GET', "/api/ideas/{$forLea}")['view']);
        foreach ([[null, "/api/users/{$lea}/ideas"], [$lea, '/api/users/me/ideas']] as [$actingAs, $path]) {
            self::assertSame([$forLea], array_column($this->expect(200, $this->anne, 'GET', $path, null, $actingAs)['member'], 'id'));
        }
        $sync = (string) json_encode($this->expect(200, $this->anne, 'POST', '/api/sync', []));
        self::assertStringNotContainsString($forBoth, $sync);
        self::assertStringContainsString($forLea, $sync);

        // A personal idea for both of them: the owner view, not the manager's.
        $together = $this->expect(201, $this->anne, 'POST', '/api/ideas', ['title' => 'Zoo', 'recipientIds' => [$this->anneId, $lea]])['id'];
        $this->expect(201, $this->paul, 'POST', '/api/reservations', ['ideaId' => $together]);
        $view = $this->expect(200, $this->anne, 'GET', "/api/ideas/{$together}");
        self::assertSame('owner', $view['view']);
        self::assertArrayNotHasKey('reservation', $view);
    }

    public function testTheGuestViewListsSharedIdeasWithoutNamingTheOthers(): void
    {
        $this->expect(201, $this->luc, 'POST', '/api/ideas', ['title' => 'Airfryer', 'recipientIds' => [$this->anneId, $this->lucId]]);
        $this->expect(201, $this->paul, 'POST', '/api/ideas', ['title' => 'SECRET suggestion', 'recipientIds' => [$this->anneId, $this->lucId]]);
        $token = $this->expect(201, $this->anne, 'POST', '/api/share-link', ['confirmed' => true])['link']['token'];

        $response = static::createClient()->request('GET', "/api/share-links/{$token}");
        $guest = $response->toArray();
        self::assertSame(['Airfryer'], array_column($guest['member'], 'title'));
        $raw = $response->getContent();
        foreach (['Luc', $this->lucId, 'SECRET', 'recipients'] as $hidden) {
            self::assertStringNotContainsString($hidden, $raw);
        }
    }

    public function testTheExportHoldsTheSharedIdeaButNoInteraction(): void
    {
        $id = $this->expect(201, $this->luc, 'POST', '/api/ideas', ['title' => 'Airfryer', 'recipientIds' => [$this->anneId, $this->lucId]])['id'];
        $this->expect(201, $this->paul, 'POST', '/api/ideas', ['title' => 'SECRET suggestion', 'recipientIds' => [$this->anneId, $this->lucId]]);
        $this->expect(201, $this->marc, 'POST', '/api/reservations', ['ideaId' => $id]);
        $this->expect(201, $this->marc, 'POST', '/api/comments', ['ideaId' => $id, 'body' => 'SECRET comment']);

        $this->expect(202, $this->anne, 'POST', '/api/account/export', ['password' => 'correcthorsebattery']);
        $files = $this->runExport()['files'];
        self::assertStringNotContainsString('SECRET', implode("\n", $files));
        $ideas = json_decode($files['ideas.json'], true);
        self::assertSame(['Airfryer'], array_column($ideas, 'title'));
        self::assertSame('Anne, Luc', $ideas[0]['recipients']);
        self::assertSame([], json_decode($files['reservations.json'], true));
    }
}
