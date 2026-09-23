<?php

declare(strict_types=1);

namespace App\Tests\Visibility;

use App\Tests\Functional\ApiRequestTrait;
use App\Tests\Functional\AuthTestCase;

/**
 * Lot 4 cases of the visibility suite (CLAUDE.md règles 1, 2, 3, 5;
 * spec §4): reservations, contributions and pledges, comments and
 * reactions never reach the idea's owner — no field, no counter, no
 * endpoint, no telling error — pledge amounts reach only their author
 * and the initiator, private ideas take no interaction, and a friend
 * removal cancels/keeps exactly what spec §5.3 says. /sync,
 * notifications and export add their cases in lots 5, 6 and 8.
 *
 * Cast: Owner; Hugo, Léa, Marc: Owner's friends (not friends with each
 * other); Stranger.
 */
final class InteractionVisibilityTest extends AuthTestCase
{
    use ApiRequestTrait;

    private const string UNKNOWN_ID = '0190a1b2-0000-7000-8000-00000000ffff';

    private string $owner;
    private string $hugo;
    private string $lea;
    private string $marc;
    private string $stranger;
    private string $ownerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->registerVerifyAndLogin('xv-owner@example.com');
        $this->hugo = $this->registerVerifyAndLogin('xv-hugo@example.com');
        $this->lea = $this->registerVerifyAndLogin('xv-lea@example.com');
        $this->marc = $this->registerVerifyAndLogin('xv-marc@example.com');
        $this->stranger = $this->registerVerifyAndLogin('xv-stranger@example.com');
        foreach (['hugo', 'lea', 'marc'] as $name) {
            $this->befriend($this->owner, 'xv-owner@example.com', $this->{$name}, "xv-{$name}@example.com");
        }
        $this->ownerId = $this->userId($this->owner);
    }

    public function testTheOwnerSeesNoTraceOfAnyInteraction(): void
    {
        $reserved = $this->expect(201, $this->owner, 'POST', '/api/ideas', ['title' => 'Reserved', 'priceAmount' => '50']);
        $pooled = $this->expect(201, $this->owner, 'POST', '/api/ideas', ['title' => 'Pooled', 'priceAmount' => '180']);

        $before = $this->ownerSnapshot([$reserved['id'], $pooled['id']]);

        $reservation = $this->expect(201, $this->hugo, 'POST', '/api/reservations', ['ideaId' => $reserved['id']])['reservation'];
        $this->expect(200, $this->lea, 'PUT', "/api/ideas/{$reserved['id']}/reaction");
        $comment = $this->expect(201, $this->lea, 'POST', '/api/comments', ['ideaId' => $reserved['id'], 'body' => 'Je passe en boutique samedi']);
        $contribution = $this->expect(201, $this->hugo, 'POST', '/api/contributions', ['ideaId' => $pooled['id']]);
        $this->expect(200, $this->lea, 'PUT', "/api/contributions/{$contribution['id']}/pledge", ['amount' => '30']);

        // Nothing the owner can read changed: lists, items, counts, friend-list counter.
        self::assertSame($before, $this->ownerSnapshot([$reserved['id'], $pooled['id']]));
        foreach ([$reserved['id'], $pooled['id']] as $id) {
            $view = $this->expect(200, $this->owner, 'GET', "/api/ideas/{$id}");
            foreach (['reservation', 'contribution', 'reactions', 'commentCount', 'canReact', 'canMarkGifted'] as $hidden) {
                self::assertArrayNotHasKey($hidden, $view);
            }
        }

        // Every interaction endpoint: the same 404 as for an id that doesn't exist.
        $notFound = $this->notFoundBody();
        $attempts = [
            ['GET', "/api/ideas/{$reserved['id']}/comments", null],
            ['PUT', "/api/ideas/{$reserved['id']}/reaction", null],
            ['DELETE', "/api/ideas/{$reserved['id']}/reaction", null],
            ['POST', '/api/reservations', ['ideaId' => $reserved['id']]],
            ['POST', '/api/reservations', ['ideaId' => $reserved['id'], 'id' => $reservation['id']]],
            ['DELETE', "/api/reservations/{$reservation['id']}", null],
            ['POST', "/api/reservations/{$reservation['id']}/convert-to-contribution", []],
            ['POST', '/api/contributions', ['ideaId' => $pooled['id']]],
            ['GET', "/api/contributions/{$contribution['id']}", null],
            ['PATCH', "/api/contributions/{$contribution['id']}", ['targetAmount' => '1']],
            ['POST', "/api/contributions/{$contribution['id']}/close", null],
            ['PUT', "/api/contributions/{$contribution['id']}/pledge", ['amount' => '10']],
            ['DELETE', "/api/contributions/{$contribution['id']}/pledge", null],
            ['POST', '/api/comments', ['ideaId' => $reserved['id'], 'body' => 'x']],
            ['POST', '/api/comments', ['ideaId' => $reserved['id'], 'body' => 'x', 'id' => $comment['id']]],
            ['PATCH', "/api/comments/{$comment['id']}", ['body' => 'x']],
            ['DELETE', "/api/comments/{$comment['id']}", null],
        ];
        foreach ($attempts as [$method, $path, $json]) {
            self::assertSame($notFound, $this->expect(404, $this->owner, $method, $path, $json), "{$method} {$path}");
        }

        // And the interactions survived the owner's attempts.
        $hugoView = $this->expect(200, $this->hugo, 'GET', "/api/ideas/{$reserved['id']}");
        self::assertSame(1, $hugoView['commentCount']);
        self::assertSame(1, $hugoView['reactions']['count']);
        self::assertTrue($hugoView['reservation']['isMine']);
    }

    public function testFriendsSeeInteractionsOnSuggestionsTooButTheOwnerNeverSeesTheSuggestion(): void
    {
        $suggestion = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Surprise', 'ownerId' => $this->ownerId]);
        $this->expect(201, $this->lea, 'POST', '/api/reservations', ['ideaId' => $suggestion['id']]);

        $marcView = $this->expect(200, $this->marc, 'GET', "/api/users/{$this->ownerId}/ideas")['member'][0];
        self::assertSame('Surprise', $marcView['title']);
        self::assertFalse($marcView['reservation']['isMine']);

        $this->expect(404, $this->owner, 'GET', "/api/ideas/{$suggestion['id']}/comments");
        self::assertSame(0, $this->expect(200, $this->owner, 'GET', '/api/users/me/ideas')['totalItems']);
    }

    public function testPledgeAmountsReachOnlyTheirAuthorAndTheInitiator(): void
    {
        $idea = $this->expect(201, $this->owner, 'POST', '/api/ideas', ['title' => 'Casque', 'priceAmount' => '180']);
        $contribution = $this->expect(201, $this->hugo, 'POST', '/api/contributions', ['ideaId' => $idea['id']]);
        $this->expect(200, $this->lea, 'PUT', "/api/contributions/{$contribution['id']}/pledge", ['amount' => '30']);
        $this->expect(200, $this->marc, 'PUT', "/api/contributions/{$contribution['id']}/pledge", ['amount' => '80']);

        $amountsSeenBy = function (string $token) use ($contribution, $idea): array {
            $fromItem = $this->expect(200, $token, 'GET', "/api/contributions/{$contribution['id']}");
            $fromIdea = $this->expect(200, $token, 'GET', "/api/ideas/{$idea['id']}")['contribution'];
            $fromList = $this->expect(200, $token, 'GET', "/api/users/{$this->ownerId}/ideas")['member'][0]['contribution'];
            foreach ([$fromItem, $fromIdea, $fromList] as $view) {
                // Everyone sees the total, the remainder and the names (spec §5.10).
                self::assertSame('110.00', $view['totalAmount']);
                self::assertSame('70.00', $view['remainingAmount']);
                self::assertSame(2, $view['participantCount']);
            }
            self::assertSame($fromItem['participants'], $fromIdea['participants']);
            self::assertSame($fromItem['participants'], $fromList['participants']);

            $amounts = [];
            foreach ($fromItem['participants'] as $p) {
                $amounts[$p['user']['id']] = $p['amount'] ?? null;
            }

            return $amounts;
        };

        $leaId = $this->userId($this->lea);
        $marcId = $this->userId($this->marc);

        self::assertSame([$leaId => '30.00', $marcId => '80.00'], $amountsSeenBy($this->hugo), 'the initiator sees every amount');
        self::assertSame([$leaId => '30.00', $marcId => null], $amountsSeenBy($this->lea), 'a participant sees only their own');
        self::assertSame([$leaId => null, $marcId => '80.00'], $amountsSeenBy($this->marc));

        // A friend who didn't pledge sees names only.
        $viewer = $this->registerVerifyAndLogin('xv-viewer@example.com');
        $this->befriend($this->owner, 'xv-owner@example.com', $viewer, 'xv-viewer@example.com');
        foreach ($this->expect(200, $viewer, 'GET', "/api/contributions/{$contribution['id']}")['participants'] as $p) {
            self::assertArrayNotHasKey('amount', $p);
        }
    }

    public function testStrangersAndPrivateIdeasHaveNoInteractions(): void
    {
        $idea = $this->expect(201, $this->owner, 'POST', '/api/ideas', ['title' => 'Public']);
        $reservation = $this->expect(201, $this->hugo, 'POST', '/api/reservations', ['ideaId' => $idea['id']])['reservation'];

        $this->expect(404, $this->stranger, 'GET', "/api/ideas/{$idea['id']}/comments");
        $this->expect(404, $this->stranger, 'POST', '/api/reservations', ['ideaId' => $idea['id']]);
        $this->expect(404, $this->stranger, 'DELETE', "/api/reservations/{$reservation['id']}");
        $this->expect(404, $this->stranger, 'PUT', "/api/ideas/{$idea['id']}/reaction");

        // A friend's private draft: nobody else can reach it, its author gets a plain refusal.
        $draft = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Draft', 'ownerId' => $this->ownerId, 'visibility' => 'private']);
        foreach ([$this->lea, $this->owner] as $token) {
            $this->expect(404, $token, 'POST', '/api/reservations', ['ideaId' => $draft['id']]);
            $this->expect(404, $token, 'POST', '/api/comments', ['ideaId' => $draft['id'], 'body' => 'x']);
        }
        self::assertSame('idea.not_published', $this->expect(422, $this->hugo, 'POST', '/api/reservations', ['ideaId' => $draft['id']])['code']);
        $hugoDraftView = $this->expect(200, $this->hugo, 'GET', "/api/ideas/{$draft['id']}");
        self::assertArrayNotHasKey('reservation', $hugoDraftView);
    }

    public function testUnpublishingPurgesEveryInteractionForGood(): void
    {
        $suggestion = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Surprise', 'ownerId' => $this->ownerId]);
        $this->expect(201, $this->lea, 'POST', '/api/reservations', ['ideaId' => $suggestion['id']]);
        $this->expect(201, $this->marc, 'POST', '/api/comments', ['ideaId' => $suggestion['id'], 'body' => 'Top']);
        $this->expect(200, $this->marc, 'PUT', "/api/ideas/{$suggestion['id']}/reaction");

        $this->expect(200, $this->hugo, 'POST', "/api/ideas/{$suggestion['id']}/unpublish");
        $this->expect(200, $this->hugo, 'POST', "/api/ideas/{$suggestion['id']}/publish");

        $view = $this->expect(200, $this->lea, 'GET', "/api/ideas/{$suggestion['id']}");
        self::assertNull($view['reservation']);
        self::assertSame(0, $view['commentCount']);
        self::assertSame(0, $view['reactions']['count']);
        self::assertSame([], $this->expect(200, $this->lea, 'GET', "/api/ideas/{$suggestion['id']}/comments"));
    }

    public function testRemovingAFriendCancelsTheirActionsButKeepsTheirContent(): void
    {
        $reservedByHugo = $this->expect(201, $this->owner, 'POST', '/api/ideas', ['title' => 'A', 'priceAmount' => '100']);
        $pooledByLea = $this->expect(201, $this->owner, 'POST', '/api/ideas', ['title' => 'B', 'priceAmount' => '100']);
        $pooledByHugo = $this->expect(201, $this->owner, 'POST', '/api/ideas', ['title' => 'C', 'priceAmount' => '100']);

        $this->expect(201, $this->hugo, 'POST', '/api/reservations', ['ideaId' => $reservedByHugo['id']]);
        $this->expect(200, $this->hugo, 'PUT', "/api/ideas/{$reservedByHugo['id']}/reaction");
        $this->expect(201, $this->hugo, 'POST', '/api/comments', ['ideaId' => $reservedByHugo['id'], 'body' => 'Hugo was here']);
        $leaPool = $this->expect(201, $this->lea, 'POST', '/api/contributions', ['ideaId' => $pooledByLea['id']]);
        $this->expect(200, $this->lea, 'PUT', "/api/contributions/{$leaPool['id']}/pledge", ['amount' => '20']);
        $this->expect(200, $this->hugo, 'PUT', "/api/contributions/{$leaPool['id']}/pledge", ['amount' => '50']);
        $hugoPool = $this->expect(201, $this->hugo, 'POST', '/api/contributions', ['ideaId' => $pooledByHugo['id']]);
        $this->expect(200, $this->lea, 'PUT', "/api/contributions/{$hugoPool['id']}/pledge", ['amount' => '40']);
        $suggestion = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'From Hugo', 'ownerId' => $this->ownerId]);

        $hugoId = $this->userId($this->hugo);
        foreach ($this->expect(200, $this->owner, 'GET', '/api/friendships') as $f) {
            if ($f['user']['id'] === $hugoId) {
                $this->expect(200, $this->owner, 'DELETE', "/api/friendships/{$f['id']}");
            }
        }

        // Actions cancelled: reservation, reaction, pledge (total recalculated).
        $a = $this->expect(200, $this->lea, 'GET', "/api/ideas/{$reservedByHugo['id']}");
        self::assertNull($a['reservation']);
        self::assertSame(0, $a['reactions']['count']);
        $b = $this->expect(200, $this->lea, 'GET', "/api/contributions/{$leaPool['id']}");
        self::assertSame('20.00', $b['totalAmount']);
        self::assertSame(1, $b['participantCount']);

        // Hugo's own contribution: closed, Léa's pledge kept.
        $c = $this->expect(200, $this->lea, 'GET', "/api/contributions/{$hugoPool['id']}");
        self::assertSame('closed', $c['status']);
        self::assertSame('40.00', $c['totalAmount']);
        // …so a new reservation is possible again (spec §5.7: only open contributions are exclusive).
        $this->expect(201, $this->lea, 'POST', '/api/reservations', ['ideaId' => $pooledByHugo['id']]);

        // Content kept: comment and suggestion, still hidden from the owner.
        self::assertSame(1, $this->expect(200, $this->lea, 'GET', "/api/ideas/{$reservedByHugo['id']}")['commentCount']);
        $this->expect(200, $this->lea, 'GET', "/api/ideas/{$suggestion['id']}");
        $this->expect(404, $this->owner, 'GET', "/api/ideas/{$suggestion['id']}");

        // Hugo has lost access to all of it.
        $this->expect(404, $this->hugo, 'GET', "/api/contributions/{$hugoPool['id']}");
        $this->expect(404, $this->hugo, 'GET', "/api/ideas/{$reservedByHugo['id']}/comments");
    }

    /**
     * Everything the owner can read about their own list.
     *
     * @param string[] $ideaIds
     *
     * @return array<mixed>
     */
    private function ownerSnapshot(array $ideaIds): array
    {
        $snapshot = ['list' => $this->expect(200, $this->owner, 'GET', '/api/users/me/ideas')];
        foreach ($ideaIds as $id) {
            $snapshot[$id] = $this->expect(200, $this->owner, 'GET', "/api/ideas/{$id}");
        }

        return $snapshot;
    }

    /**
     * @return array<mixed>
     */
    private function notFoundBody(): array
    {
        return $this->expect(404, $this->owner, 'GET', '/api/ideas/'.self::UNKNOWN_ID.'/comments');
    }
}
