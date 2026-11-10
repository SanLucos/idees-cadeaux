<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FriendSuggestion;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Présenter à des amis » (spec §5.3, §11 décision 65).
 *
 * Cast: Luc, who introduces; Anna (A) and Bruno (B), two of his
 * friends who aren't friends together; Chloé, another friend of Luc.
 */
final class FriendSuggestionTest extends AuthTestCase
{
    use ApiRequestTrait;
    use NotificationTestTrait;

    private string $luc;
    private string $anna;
    private string $bruno;
    private string $chloe;
    private string $brunoId;
    private string $annaId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->luc = $this->registerVerifyAndLogin('fsg-luc@example.com');
        $this->anna = $this->registerVerifyAndLogin('fsg-anna@example.com');
        $this->bruno = $this->registerVerifyAndLogin('fsg-bruno@example.com');
        $this->chloe = $this->registerVerifyAndLogin('fsg-chloe@example.com');
        $this->befriend($this->luc, 'fsg-luc@example.com', $this->anna, 'fsg-anna@example.com');
        $this->befriend($this->luc, 'fsg-luc@example.com', $this->bruno, 'fsg-bruno@example.com');
        $this->befriend($this->luc, 'fsg-luc@example.com', $this->chloe, 'fsg-chloe@example.com');
        $this->expect(200, $this->luc, 'PATCH', '/api/users/me', ['displayName' => 'Luc']);
        $this->expect(200, $this->anna, 'PATCH', '/api/users/me', ['displayName' => 'Anna']);
        $this->expect(200, $this->bruno, 'PATCH', '/api/users/me', ['displayName' => 'Bruno']);
        $this->expect(200, $this->chloe, 'PATCH', '/api/users/me', ['displayName' => 'Chloé']);
        $this->annaId = $this->userId($this->anna);
        $this->brunoId = $this->userId($this->bruno);
    }

    public function testASuggestionFollowedByAnAcceptedRequestEndsInTheSuccessNotification(): void
    {
        $sent = $this->suggestBrunoTo([$this->annaId]);
        self::assertCount(1, $sent);
        self::assertSame('pending', $sent[0]['status']);
        self::assertSame($this->annaId, $sent[0]['recipient']['id']);
        self::assertSame($this->brunoId, $sent[0]['suggested']['id']);
        self::assertContains('friend_suggestion_received', $this->typesOf($this->anna));

        $received = $this->expect(200, $this->anna, 'GET', '/api/friend-suggestions');
        self::assertCount(1, $received);
        self::assertSame($this->brunoId, $received[0]['suggested']['id']);
        self::assertSame(['Luc'], array_column($received[0]['suggestedBy'], 'displayName'));

        $this->expect(200, $this->anna, 'POST', "/api/friend-suggestions/{$received[0]['id']}/send-request");
        self::assertSame([], $this->expect(200, $this->anna, 'GET', '/api/friend-suggestions'));

        $request = $this->expect(200, $this->bruno, 'GET', '/api/friendships/incoming')[0];
        self::assertSame($this->annaId, $request['user']['id']);
        self::assertSame('Luc', $request['suggestedBy']['displayName']);
        self::assertNull($this->expect(200, $this->anna, 'GET', '/api/friendships/outgoing')[0]['suggestedBy'], 'shown to the one asked only');
        self::assertCount(1, $this->expect(200, $this->luc, 'GET', '/api/friend-suggestions?sent=1'), 'still « en attente »');
        self::assertNotContains('friend_suggestion_accepted', $this->typesOf($this->luc));

        $this->expect(200, $this->bruno, 'POST', "/api/friendships/{$request['id']}/accept");

        $success = array_values(array_filter($this->notificationsOf($this->luc), static fn ($n) => 'friend_suggestion_accepted' === $n['type']));
        self::assertCount(1, $success);
        self::assertSame('Anna', $success[0]['payload']['actor']['displayName']);
        self::assertSame('Bruno', $success[0]['payload']['friend']['displayName']);
        self::assertSame([], $this->expect(200, $this->luc, 'GET', '/api/friend-suggestions?sent=1'));
    }

    public function testDismissingLeavesNoTraceForBrunoAndStaysPendingForLuc(): void
    {
        $this->suggestBrunoTo([$this->annaId]);
        $id = $this->expect(200, $this->anna, 'GET', '/api/friend-suggestions')[0]['id'];

        $this->expect(204, $this->anna, 'POST', "/api/friend-suggestions/{$id}/dismiss");

        self::assertSame([], $this->expect(200, $this->anna, 'GET', '/api/friend-suggestions'));
        self::assertSame([], $this->expect(200, $this->bruno, 'GET', '/api/friendships/incoming'));
        self::assertSame('pending', $this->expect(200, $this->luc, 'GET', '/api/friend-suggestions?sent=1')[0]['status']);
        // Answered: no second answer.
        $this->expect(404, $this->anna, 'POST', "/api/friend-suggestions/{$id}/send-request");
    }

    public function testTwoFriendsSuggestingTheSamePersonShowOnceWithOneNotification(): void
    {
        $this->befriend($this->chloe, 'fsg-chloe@example.com', $this->anna, 'fsg-anna@example.com');
        $this->befriend($this->chloe, 'fsg-chloe@example.com', $this->bruno, 'fsg-bruno@example.com');

        $this->suggestBrunoTo([$this->annaId]);
        $this->expect(201, $this->chloe, 'POST', '/api/friend-suggestions', ['suggested' => $this->brunoId, 'recipients' => [$this->annaId]]);
        // The same suggestion again: left as is.
        $this->suggestBrunoTo([$this->annaId]);

        $received = $this->expect(200, $this->anna, 'GET', '/api/friend-suggestions');
        self::assertCount(1, $received);
        self::assertSame(['Luc', 'Chloé'], array_column($received[0]['suggestedBy'], 'displayName'));
        self::assertCount(1, array_keys($this->typesOf($this->anna), 'friend_suggestion_received'));
        self::assertCount(1, $this->expect(200, $this->luc, 'GET', '/api/friend-suggestions?sent=1'));

        $this->expect(200, $this->anna, 'POST', "/api/friend-suggestions/{$received[0]['id']}/send-request");
        $request = $this->expect(200, $this->bruno, 'GET', '/api/friendships/incoming')[0];
        self::assertSame('Luc', $request['suggestedBy']['displayName'], 'the first one');
        $this->expect(200, $this->bruno, 'POST', "/api/friendships/{$request['id']}/accept");

        self::assertContains('friend_suggestion_accepted', $this->typesOf($this->luc));
        self::assertContains('friend_suggestion_accepted', $this->typesOf($this->chloe));
    }

    public function testSeveralRecipientsAtOnce(): void
    {
        $sent = $this->suggestBrunoTo([$this->annaId, $this->userId($this->chloe)]);

        self::assertCount(2, $sent);
        self::assertCount(1, $this->expect(200, $this->chloe, 'GET', '/api/friend-suggestions'));
    }

    public function testOnlyAdultFriendsCanBeIntroduced(): void
    {
        $stranger = $this->registerVerifyAndLogin('fsg-stranger@example.com');
        $strangerId = $this->userId($stranger);
        $child = $this->expect(201, $this->luc, 'POST', '/api/managed-profiles', ['displayName' => 'Jules', 'parentalConsent' => true])['id'];
        $this->expect(200, $this->luc, 'POST', '/api/friendships', ['email' => 'fsg-anna@example.com'], $child);
        $request = $this->expect(200, $this->anna, 'GET', '/api/friendships/incoming')[0];
        $this->expect(200, $this->anna, 'POST', "/api/friendships/{$request['id']}/accept");

        foreach ([
            ['suggested' => $strangerId, 'recipients' => [$this->annaId]],
            ['suggested' => $this->brunoId, 'recipients' => [$strangerId]],
            ['suggested' => $child, 'recipients' => [$this->annaId]],
            ['suggested' => $this->brunoId, 'recipients' => [$child]],
            ['suggested' => $this->userId($this->luc), 'recipients' => [$this->annaId]],
        ] as $body) {
            self::assertSame('friend_suggestion.not_a_friend', $this->expect(422, $this->luc, 'POST', '/api/friend-suggestions', $body)['code'], json_encode($body));
        }
        $this->expect(422, $this->luc, 'POST', '/api/friend-suggestions', ['suggested' => $this->brunoId, 'recipients' => [$this->brunoId]]);
        $this->expect(422, $this->luc, 'POST', '/api/friend-suggestions', ['suggested' => $this->brunoId, 'recipients' => []]);

        // Never on behalf of a child (spec §11 décision 25).
        self::assertSame('acting_as.not_allowed', $this->expect(403, $this->luc, 'POST', '/api/friend-suggestions', ['suggested' => $this->brunoId, 'recipients' => [$this->annaId]], $child)['code']);
        $this->expect(403, $this->luc, 'GET', '/api/friend-suggestions', null, $child);
        self::assertSame([], $this->expect(200, $this->anna, 'GET', '/api/friend-suggestions'));
    }

    public function testTheAuthorCanCancelNobodyElse(): void
    {
        $id = $this->suggestBrunoTo([$this->annaId])[0]['id'];

        $this->expect(404, $this->anna, 'DELETE', "/api/friend-suggestions/{$id}");
        $this->expect(404, $this->bruno, 'DELETE', "/api/friend-suggestions/{$id}");
        $this->expect(204, $this->luc, 'DELETE', "/api/friend-suggestions/{$id}");

        self::assertSame([], $this->expect(200, $this->anna, 'GET', '/api/friend-suggestions'));
        self::assertSame([], $this->expect(200, $this->luc, 'GET', '/api/friend-suggestions?sent=1'));
    }

    public function testAnExpiredSuggestionIsGoneForEveryone(): void
    {
        $this->suggestBrunoTo([$this->annaId]);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->createQuery('UPDATE '.FriendSuggestion::class.' s SET s.expiresAt = :past')
            ->setParameter('past', new \DateTimeImmutable('-1 minute'))
            ->execute();

        self::assertSame([], $this->expect(200, $this->anna, 'GET', '/api/friend-suggestions'));
        self::assertSame([], $this->expect(200, $this->luc, 'GET', '/api/friend-suggestions?sent=1'));
    }

    public function testItDisappearsWhenTheAuthorIsNoLongerFriendsWithEither(): void
    {
        $this->suggestBrunoTo([$this->annaId]);
        $friendship = array_values(array_filter(
            $this->expect(200, $this->luc, 'GET', '/api/friendships'),
            fn ($f) => $f['user']['id'] === $this->brunoId,
        ))[0];

        $this->expect(200, $this->bruno, 'DELETE', "/api/friendships/{$friendship['id']}");

        self::assertSame([], $this->expect(200, $this->anna, 'GET', '/api/friend-suggestions'));
        self::assertSame([], $this->expect(200, $this->luc, 'GET', '/api/friend-suggestions?sent=1'));
    }

    public function testRateLimited(): void
    {
        $ids = [];
        for ($i = 0; $i < 30; ++$i) {
            $ids[] = $this->annaId;
        }
        // Duplicates count once; 30 distinct people would be the cap.
        $this->suggestBrunoTo($ids);
        for ($i = 0; $i < 29; ++$i) {
            $this->expect(201, $this->luc, 'POST', '/api/friend-suggestions', ['suggested' => $this->brunoId, 'recipients' => [$this->annaId]]);
        }
        self::assertSame('request.rate_limited', $this->expect(429, $this->luc, 'POST', '/api/friend-suggestions', ['suggested' => $this->brunoId, 'recipients' => [$this->annaId]])['code']);
    }

    /**
     * @param string[] $recipients
     *
     * @return list<array<string, mixed>>
     */
    private function suggestBrunoTo(array $recipients): array
    {
        return $this->expect(201, $this->luc, 'POST', '/api/friend-suggestions', ['suggested' => $this->brunoId, 'recipients' => $recipients]);
    }
}
