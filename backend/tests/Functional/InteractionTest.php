<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Lot 4 behaviour (spec §5.4 archivage, §5.7–5.10). Who-sees-what is
 * in tests/Visibility/InteractionVisibilityTest.
 */
final class InteractionTest extends AuthTestCase
{
    use ApiRequestTrait;

    private string $owner;
    private string $hugo;
    private string $lea;
    private string $ownerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->registerVerifyAndLogin('it-owner@example.com');
        $this->hugo = $this->registerVerifyAndLogin('it-hugo@example.com');
        $this->lea = $this->registerVerifyAndLogin('it-lea@example.com');
        $this->befriend($this->owner, 'it-owner@example.com', $this->hugo, 'it-hugo@example.com');
        $this->befriend($this->owner, 'it-owner@example.com', $this->lea, 'it-lea@example.com');
        $this->expect(200, $this->hugo, 'PATCH', '/api/users/me', ['displayName' => 'Hugo']);
        $this->ownerId = $this->userId($this->owner);
    }

    public function testReservationIsExclusiveIdempotentAndCancelledByItsHolderOnly(): void
    {
        $idea = $this->idea('Sac à dos', '120');
        $clientId = '0190a1b2-0000-7000-8000-00000000a001';

        $view = $this->expect(201, $this->hugo, 'POST', '/api/reservations', ['ideaId' => $idea, 'id' => $clientId]);
        self::assertSame($clientId, $view['reservation']['id']);
        self::assertSame('Hugo', $view['reservation']['user']['displayName']);
        $this->expect(200, $this->hugo, 'POST', '/api/reservations', ['ideaId' => $idea, 'id' => $clientId]);
        $this->expect(200, $this->hugo, 'POST', '/api/reservations', ['ideaId' => $idea]);

        // Spec §5.7: the second one learns who got there first.
        $conflict = $this->expect(409, $this->lea, 'POST', '/api/reservations', ['ideaId' => $idea]);
        self::assertSame('reservation.already_reserved', $conflict['code']);
        self::assertSame('Hugo', $conflict['reservedBy']);

        self::assertSame('reservation.not_yours', $this->expect(403, $this->lea, 'DELETE', "/api/reservations/{$clientId}")['code']);
        self::assertNull($this->expect(200, $this->hugo, 'DELETE', "/api/reservations/{$clientId}")['reservation']);
        $this->expect(201, $this->lea, 'POST', '/api/reservations', ['ideaId' => $idea]);
    }

    public function testReservationAndOpenContributionAreExclusiveAndAReservationConverts(): void
    {
        $idea = $this->idea('Casque', '180');

        $reservation = $this->expect(201, $this->hugo, 'POST', '/api/reservations', ['ideaId' => $idea])['reservation'];
        self::assertSame('contribution.idea_reserved', $this->expect(409, $this->lea, 'POST', '/api/contributions', ['ideaId' => $idea])['code']);

        $contribution = $this->expect(201, $this->hugo, 'POST', "/api/reservations/{$reservation['id']}/convert-to-contribution", []);
        self::assertTrue($contribution['isInitiator']);
        self::assertSame('180.00', $contribution['targetAmount'], 'the idea price by default (spec §5.10)');
        self::assertSame('open', $contribution['status']);
        self::assertNull($contribution['idea']['reservation'], 'the reservation is gone');

        $conflict = $this->expect(409, $this->lea, 'POST', '/api/reservations', ['ideaId' => $idea]);
        self::assertSame('reservation.contribution_open', $conflict['code']);
        $second = $this->expect(409, $this->lea, 'POST', '/api/contributions', ['ideaId' => $idea]);
        self::assertSame('contribution.already_open', $second['code']);
        self::assertSame($contribution['id'], $second['contributionId'], 'so the loser can pledge to it instead');
    }

    public function testContributionPledgesTotalsAndClosing(): void
    {
        $idea = $this->idea('Casque', '180');
        $c = $this->expect(201, $this->hugo, 'POST', '/api/contributions', ['ideaId' => $idea, 'targetAmount' => '150']);
        self::assertSame('150.00', $c['targetAmount']);

        $this->expect(200, $this->hugo, 'PUT', "/api/contributions/{$c['id']}/pledge", ['amount' => '100']);
        $this->expect(200, $this->lea, 'PUT', "/api/contributions/{$c['id']}/pledge", ['amount' => '30']);
        $updated = $this->expect(200, $this->lea, 'PUT', "/api/contributions/{$c['id']}/pledge", ['amount' => '50,5']);
        self::assertSame('50.50', $updated['myPledge'], 'one pledge per person, updated in place');
        self::assertSame('150.50', $updated['totalAmount']);
        self::assertSame('0.00', $updated['remainingAmount']);
        self::assertTrue($updated['goalReached']);

        self::assertSame('validation.pledge_amount_invalid', $this->expect(422, $this->lea, 'PUT', "/api/contributions/{$c['id']}/pledge", ['amount' => '0'])['code']);
        self::assertSame('contribution.not_initiator', $this->expect(403, $this->lea, 'PATCH', "/api/contributions/{$c['id']}", ['targetAmount' => '10'])['code']);
        self::assertSame('contribution.not_initiator', $this->expect(403, $this->lea, 'POST', "/api/contributions/{$c['id']}/close")['code']);

        $withdrawn = $this->expect(200, $this->lea, 'DELETE', "/api/contributions/{$c['id']}/pledge");
        self::assertNull($withdrawn['myPledge']);
        self::assertSame('100.00', $withdrawn['totalAmount']);

        self::assertSame('closed', $this->expect(200, $this->hugo, 'POST', "/api/contributions/{$c['id']}/close")['status']);
        // Spec §8: a pledge on a contribution closed in the meantime is refused with a message.
        self::assertSame('contribution.closed', $this->expect(422, $this->lea, 'PUT', "/api/contributions/{$c['id']}/pledge", ['amount' => '10'])['code']);
        // Closed ≠ exclusive: a reservation is possible again.
        $this->expect(201, $this->lea, 'POST', '/api/reservations', ['ideaId' => $idea]);
    }

    public function testMarkingASuggestionGiftedByReserverOrInitiatorAndArchivedIdeasTakeNoNewInteraction(): void
    {
        $reservedSuggestion = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'S1', 'ownerId' => $this->ownerId])['id'];
        $pooledSuggestion = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'S2', 'ownerId' => $this->ownerId])['id'];

        $view = $this->expect(201, $this->lea, 'POST', '/api/reservations', ['ideaId' => $reservedSuggestion]);
        self::assertTrue($view['canMarkGifted'], 'the reserver may mark it offert');
        $archived = $this->expect(200, $this->lea, 'POST', "/api/ideas/{$reservedSuggestion}/archive", ['kind' => 'gifted']);
        self::assertSame('gifted', $archived['archiveKind']);
        self::assertTrue($archived['canUnarchive'], 'only whoever archived can restore');

        $pool = $this->expect(201, $this->lea, 'POST', '/api/contributions', ['ideaId' => $pooledSuggestion]);
        self::assertTrue($pool['idea']['canMarkGifted'], 'the initiator may mark it offert');
        self::assertFalse($this->expect(200, $this->registerFriendOfOwner(), 'GET', "/api/ideas/{$pooledSuggestion}")['canMarkGifted'], 'an uninvolved friend may not');
        $this->expect(200, $this->lea, 'POST', "/api/ideas/{$pooledSuggestion}/archive", ['kind' => 'gifted']);
        self::assertSame('closed', $this->expect(200, $this->lea, 'GET', "/api/contributions/{$pool['id']}")['status'], 'archiving closes the open contribution (spec §5.4)');

        foreach ([
            ['POST', '/api/reservations', ['ideaId' => $pooledSuggestion]],
            ['POST', '/api/comments', ['ideaId' => $pooledSuggestion, 'body' => 'Trop tard']],
            ['POST', '/api/contributions', ['ideaId' => $pooledSuggestion]],
        ] as [$method, $path, $json]) {
            self::assertSame('idea.archived', $this->expect(422, $this->hugo, $method, $path, $json)['code'], $path);
        }
    }

    public function testCommentsThreadEditAndDelete(): void
    {
        $idea = $this->idea('Plaid', '75');

        $first = $this->expect(201, $this->lea, 'POST', '/api/comments', ['ideaId' => $idea, 'body' => '  Je passe samedi  ']);
        self::assertSame('Je passe samedi', $first['body']);
        $this->expect(201, $this->hugo, 'POST', '/api/comments', ['ideaId' => $idea, 'body' => 'Parfait']);

        $thread = $this->expect(200, $this->hugo, 'GET', "/api/ideas/{$idea}/comments");
        self::assertSame(['Je passe samedi', 'Parfait'], array_column($thread, 'body'), 'flat thread, oldest first');
        self::assertSame([false, true], array_column($thread, 'isMine'));

        self::assertSame('comment.not_yours', $this->expect(403, $this->hugo, 'PATCH', "/api/comments/{$first['id']}", ['body' => 'x'])['code']);
        $edited = $this->expect(200, $this->lea, 'PATCH', "/api/comments/{$first['id']}", ['body' => 'Samedi matin']);
        self::assertNotNull($edited['editedAt']);

        foreach (['', str_repeat('a', 1001)] as $body) {
            self::assertSame('validation.comment_body_invalid', $this->expect(422, $this->lea, 'POST', '/api/comments', ['ideaId' => $idea, 'body' => $body])['code']);
        }

        $this->expect(204, $this->lea, 'DELETE', "/api/comments/{$first['id']}");
        self::assertSame(1, $this->expect(200, $this->hugo, 'GET', "/api/ideas/{$idea}")['commentCount']);
    }

    public function testLikeIsAnIdempotentToggleAndNeverOnYourOwnIdea(): void
    {
        $idea = $this->idea('Plaid', '75');

        $this->expect(200, $this->hugo, 'PUT', "/api/ideas/{$idea}/reaction");
        $liked = $this->expect(200, $this->hugo, 'PUT', "/api/ideas/{$idea}/reaction");
        self::assertSame(['count' => 1, 'likedByMe' => true], $liked['reactions']);
        self::assertSame(['count' => 1, 'likedByMe' => false], $this->expect(200, $this->lea, 'GET', "/api/ideas/{$idea}")['reactions']);

        $this->expect(200, $this->hugo, 'DELETE', "/api/ideas/{$idea}/reaction");
        self::assertSame(0, $this->expect(200, $this->hugo, 'DELETE', "/api/ideas/{$idea}/reaction")['reactions']['count']);

        $suggestion = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Mine', 'ownerId' => $this->ownerId]);
        self::assertFalse($suggestion['canReact']);
        self::assertSame('reaction.own_idea', $this->expect(422, $this->hugo, 'PUT', "/api/ideas/{$suggestion['id']}/reaction")['code']);
    }

    private function idea(string $title, string $price): string
    {
        return $this->expect(201, $this->owner, 'POST', '/api/ideas', ['title' => $title, 'priceAmount' => $price])['id'];
    }

    private function registerFriendOfOwner(): string
    {
        $token = $this->registerVerifyAndLogin('it-third@example.com');
        $this->befriend($this->owner, 'it-owner@example.com', $token, 'it-third@example.com');

        return $token;
    }
}
