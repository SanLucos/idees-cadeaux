<?php

declare(strict_types=1);

namespace App\Tests\Visibility;

use App\Tests\Functional\ApiRequestTrait;
use App\Tests\Functional\AuthTestCase;
use App\Tests\Functional\DataExportTestTrait;
use App\Tests\Functional\NotificationTestTrait;

/**
 * Lot 14 cases of the visibility suite (spec §11 décision 65):
 *
 * - Bruno (B) learns nothing of a suggestion Anna (A) didn't follow;
 * - Anna learns only Bruno's pseudo and avatar;
 * - anyone else gets a 404;
 * - Luc, the author, never tells a refusal, « Ignorer », an expiry or a
 *   suggestion without object from one still waiting: always « en
 *   attente », and nothing but the success is ever notified to him.
 *
 * Cast: Luc; Anna and Bruno, his friends; Zoé, a friend of Luc too.
 */
final class FriendSuggestionVisibilityTest extends AuthTestCase
{
    use ApiRequestTrait;
    use DataExportTestTrait;
    use NotificationTestTrait;

    private string $luc;
    private string $anna;
    private string $bruno;
    private string $zoe;
    private string $annaId;
    private string $brunoId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->luc = $this->registerVerifyAndLogin('fsv-luc@example.com');
        $this->anna = $this->registerVerifyAndLogin('fsv-anna@example.com');
        $this->bruno = $this->registerVerifyAndLogin('fsv-bruno@example.com');
        $this->zoe = $this->registerVerifyAndLogin('fsv-zoe@example.com');
        foreach (['anna' => $this->anna, 'bruno' => $this->bruno, 'zoe' => $this->zoe] as $name => $token) {
            $this->befriend($this->luc, 'fsv-luc@example.com', $token, "fsv-{$name}@example.com");
        }
        $this->expect(200, $this->luc, 'PATCH', '/api/users/me', ['displayName' => 'Luc']);
        $this->expect(200, $this->anna, 'PATCH', '/api/users/me', ['displayName' => 'Anna']);
        $this->expect(200, $this->bruno, 'PATCH', '/api/users/me', ['displayName' => 'Bruno']);
        $this->expect(200, $this->zoe, 'PATCH', '/api/users/me', ['displayName' => 'Zoé']);
        $this->annaId = $this->userId($this->anna);
        $this->brunoId = $this->userId($this->bruno);
        $this->expect(200, $this->bruno, 'PATCH', '/api/users/me', ['birthDay' => 12, 'birthMonth' => 5]);
    }

    public function testBrunoSeesNothingOfASuggestionAnnaDidNotFollow(): void
    {
        $this->suggest();

        self::assertSame([], $this->expect(200, $this->bruno, 'GET', '/api/friendships/incoming'));
        self::assertSame([], $this->expect(200, $this->bruno, 'GET', '/api/friend-suggestions'));
        self::assertSame([], $this->expect(200, $this->bruno, 'GET', '/api/friend-suggestions?sent=1'));
        self::assertNotContains('friend_suggestion_received', $this->typesOf($this->bruno));
        $sync = json_encode($this->expect(200, $this->bruno, 'POST', '/api/sync', []));
        self::assertStringNotContainsString($this->annaId, (string) $sync);

        $id = $this->expect(200, $this->anna, 'GET', '/api/friend-suggestions')[0]['id'];
        $this->expect(404, $this->bruno, 'POST', "/api/friend-suggestions/{$id}/send-request");
        $this->expect(404, $this->bruno, 'POST', "/api/friend-suggestions/{$id}/dismiss");
    }

    public function testAnnaSeesOnlyBrunosPseudoAndAvatar(): void
    {
        $this->suggest();

        $received = $this->expect(200, $this->anna, 'GET', '/api/friend-suggestions')[0];
        self::assertSame(['id', 'displayName', 'avatarUrl'], array_keys($received['suggested']));
        self::assertSame(['id', 'displayName'], array_keys($received['suggestedBy'][0]));
        $notification = array_values(array_filter($this->notificationsOf($this->anna), static fn ($n) => 'friend_suggestion_received' === $n['type']))[0];
        self::assertSame(['id', 'displayName'], array_keys($notification['payload']['friend']));

        // Nothing else of Bruno's: neither his profile nor his list.
        $this->expect(404, $this->anna, 'GET', "/api/users/{$this->brunoId}");
        $this->expect(404, $this->anna, 'GET', "/api/users/{$this->brunoId}/ideas");
    }

    public function testAnyoneElseGetsA404(): void
    {
        $sentId = $this->suggest()[0]['id'];
        $receivedId = $this->expect(200, $this->anna, 'GET', '/api/friend-suggestions')[0]['id'];

        foreach ([$this->zoe, $this->bruno] as $other) {
            self::assertSame([], $this->expect(200, $other, 'GET', '/api/friend-suggestions'));
            $this->expect(404, $other, 'POST', "/api/friend-suggestions/{$receivedId}/send-request");
            $this->expect(404, $other, 'DELETE', "/api/friend-suggestions/{$sentId}");
        }
        $this->expect(404, $this->luc, 'POST', "/api/friend-suggestions/{$receivedId}/dismiss", null);
    }

    /**
     * Ignored, refused, without object (already friends, request pending
     * between them): Luc's view is the same as for one still waiting,
     * and he's never notified.
     */
    public function testTheAuthorNeverTellsWhatHappened(): void
    {
        $waiting = $this->authorView();

        // Ignored.
        $id = $this->expect(200, $this->anna, 'GET', '/api/friend-suggestions')[0]['id'];
        $this->expect(204, $this->anna, 'POST', "/api/friend-suggestions/{$id}/dismiss");
        self::assertSame($waiting, $this->authorView());

        // Refused: sent, then silently declined by Bruno.
        $this->expect(201, $this->luc, 'POST', '/api/friend-suggestions', ['suggested' => $this->annaId, 'recipients' => [$this->brunoId]]);
        $id = $this->expect(200, $this->bruno, 'GET', '/api/friend-suggestions')[0]['id'];
        $this->expect(200, $this->bruno, 'POST', "/api/friend-suggestions/{$id}/send-request");
        $request = $this->expect(200, $this->anna, 'GET', '/api/friendships/incoming')[0];
        $this->expect(200, $this->anna, 'POST', "/api/friendships/{$request['id']}/decline");
        $views = array_column($this->expect(200, $this->luc, 'GET', '/api/friend-suggestions?sent=1'), 'status');
        self::assertSame(['pending', 'pending'], $views);

        // Without object: Zoé and Anna already friends.
        $this->befriend($this->zoe, 'fsv-zoe@example.com', $this->anna, 'fsv-anna@example.com');
        $this->expect(201, $this->luc, 'POST', '/api/friend-suggestions', ['suggested' => $this->userId($this->zoe), 'recipients' => [$this->annaId]]);
        self::assertSame([], $this->expect(200, $this->anna, 'GET', '/api/friend-suggestions'), 'not shown to Anna');
        self::assertSame(['pending', 'pending', 'pending'], array_column($this->expect(200, $this->luc, 'GET', '/api/friend-suggestions?sent=1'), 'status'));
        foreach ($this->expect(200, $this->luc, 'GET', '/api/friend-suggestions?sent=1') as $item) {
            self::assertSame(['id', 'status', 'createdAt', 'recipient', 'suggested'], array_keys($item));
        }

        self::assertNotContains('friend_suggestion_accepted', $this->typesOf($this->luc));
    }

    public function testNoFriendSuggestionInAnExportButOneOwnSide(): void
    {
        $this->suggest();

        $this->expect(202, $this->bruno, 'POST', '/api/account/export', ['password' => 'correcthorsebattery']);
        $friends = json_decode($this->runExport()['files']['friends.json'], true);
        self::assertSame([], $friends['suggestionsMade']);
        self::assertSame([], $friends['suggestionsReceived']);
        self::assertStringNotContainsString('Anna', json_encode($friends) ?: '');

        $this->expect(202, $this->anna, 'POST', '/api/account/export', ['password' => 'correcthorsebattery']);
        $friends = json_decode($this->runExport()['files']['friends.json'], true);
        self::assertSame([['from' => 'Luc', 'suggested' => 'Bruno']], array_map(static fn ($s) => ['from' => $s['from'], 'suggested' => $s['suggested']], $friends['suggestionsReceived']));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function suggest(): array
    {
        return $this->expect(201, $this->luc, 'POST', '/api/friend-suggestions', ['suggested' => $this->brunoId, 'recipients' => [$this->annaId]]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function authorView(): array
    {
        if ([] === $this->expect(200, $this->luc, 'GET', '/api/friend-suggestions?sent=1')) {
            $this->suggest();
        }

        return $this->expect(200, $this->luc, 'GET', '/api/friend-suggestions?sent=1');
    }
}
