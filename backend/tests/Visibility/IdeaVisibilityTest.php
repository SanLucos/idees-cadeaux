<?php

declare(strict_types=1);

namespace App\Tests\Visibility;

use App\Tests\Functional\AuthTestCase;

/**
 * Lot 3 cases of the visibility suite (CLAUDE.md règles 1, 2 and 5,
 * spec §4): suggestions never reach their owner, private ideas never
 * reach anyone but their author, strangers see nothing — on every
 * endpoint that exists so far (lists, item, writes, counters). /sync,
 * notifications and export add their cases in lots 5, 6 and 8.
 *
 * Cast: Owner (the list), Friend (writes suggestions), Other (another
 * friend of Owner, not of Friend), Stranger (friend of nobody).
 */
final class IdeaVisibilityTest extends AuthTestCase
{
    private string $owner;
    private string $friend;
    private string $other;
    private string $stranger;
    private string $ownerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->registerVerifyAndLogin('iv-owner@example.com');
        $this->friend = $this->registerVerifyAndLogin('iv-friend@example.com');
        $this->other = $this->registerVerifyAndLogin('iv-other@example.com');
        $this->stranger = $this->registerVerifyAndLogin('iv-stranger@example.com');
        $this->befriend($this->owner, 'iv-owner@example.com', $this->friend, 'iv-friend@example.com');
        $this->befriend($this->owner, 'iv-owner@example.com', $this->other, 'iv-other@example.com');
        $this->ownerId = $this->userId($this->owner);
    }

    public function testASuggestionIsInvisibleToItsOwnerOnEveryEndpoint(): void
    {
        $suggestion = $this->createIdea($this->friend, ['title' => 'Surprise', 'ownerId' => $this->ownerId]);
        $id = $suggestion['id'];

        // Lists and their counters.
        $mine = $this->get($this->owner, '/api/users/me/ideas');
        self::assertSame(0, $mine['totalItems']);
        self::assertSame(['published' => 0, 'drafts' => 0, 'archived' => 0], $mine['counts']);
        self::assertSame(0, $this->get($this->owner, "/api/users/{$this->ownerId}/ideas")['totalItems'], 'my own id is my owner view too');
        foreach (['archived', 'active'] as $status) {
            foreach (['published', 'private'] as $visibility) {
                self::assertSame(0, $this->get($this->owner, "/api/users/me/ideas?status={$status}&visibility={$visibility}")['totalItems']);
            }
        }
        self::assertSame([], $this->get($this->owner, '/api/ideas/private'));

        // The item itself, by any verb: 404, never 403.
        $this->request($this->owner, 'GET', "/api/ideas/{$id}");
        self::assertResponseStatusCodeSame(404);
        $this->request($this->owner, 'PATCH', "/api/ideas/{$id}", ['title' => 'x'], 'application/merge-patch+json');
        self::assertResponseStatusCodeSame(404);
        $this->request($this->owner, 'DELETE', "/api/ideas/{$id}");
        self::assertResponseStatusCodeSame(404);
        foreach (['publish', 'unpublish', 'unarchive'] as $action) {
            $this->request($this->owner, 'POST', "/api/ideas/{$id}/{$action}");
            self::assertResponseStatusCodeSame(404, $action);
        }
        foreach (['received', 'gifted', 'nonsense'] as $kind) {
            $this->request($this->owner, 'POST', "/api/ideas/{$id}/archive", ['kind' => $kind]);
            self::assertResponseStatusCodeSame(404, "archive {$kind}");
        }
        $this->request($this->owner, 'DELETE', "/api/ideas/{$id}/image");
        self::assertResponseStatusCodeSame(404);

        // Replaying the create with the suggestion's id must not leak it either.
        $this->request($this->owner, 'POST', '/api/ideas', ['id' => $id, 'title' => 'Mine']);
        self::assertResponseStatusCodeSame(409);
        $conflict = $this->lastJson();
        self::assertSame('request.conflict', $conflict['code']);
        self::assertStringNotContainsString('Surprise', json_encode($conflict));
    }

    public function testASuggestionStaysHiddenFromItsOwnerOnceArchived(): void
    {
        $suggestion = $this->createIdea($this->friend, ['title' => 'Given', 'ownerId' => $this->ownerId]);
        $this->request($this->friend, 'POST', "/api/ideas/{$suggestion['id']}/archive", ['kind' => 'gifted']);
        self::assertResponseStatusCodeSame(200);

        self::assertSame(0, $this->get($this->owner, '/api/users/me/ideas?status=archived')['totalItems']);
        self::assertSame(0, $this->get($this->owner, '/api/users/me/ideas')['counts']['archived']);

        // Other friends still find it in the list's archives (spec §5.4).
        self::assertSame(1, $this->get($this->other, "/api/users/{$this->ownerId}/ideas?status=archived")['totalItems']);
    }

    public function testFriendsSeeSuggestionsButOwnerCountsNeverIncludeThem(): void
    {
        $this->createIdea($this->owner, ['title' => 'Personal']);
        $this->createIdea($this->friend, ['title' => 'Suggested', 'ownerId' => $this->ownerId]);

        $otherView = $this->get($this->other, "/api/users/{$this->ownerId}/ideas");
        self::assertSame(2, $otherView['totalItems']);
        $bySuggestion = array_column($otherView['member'], 'isSuggestion', 'title');
        self::assertSame(['Suggested' => true, 'Personal' => false], $bySuggestion);

        self::assertSame(1, $this->get($this->owner, '/api/users/me/ideas')['counts']['published']);

        // The friend list's counter is a friend-side view: fine to include
        // suggestions there, as the owner never sees their own count.
        $friendsOfOther = $this->get($this->other, '/api/friendships');
        self::assertSame(2, $friendsOfOther[0]['ideaCount']);
    }

    public function testTheOwnerViewNeverCarriesAuthorOrSuggestionFields(): void
    {
        $idea = $this->createIdea($this->owner, ['title' => 'Mine']);

        foreach ([$idea, $this->get($this->owner, "/api/ideas/{$idea['id']}"), $this->get($this->owner, '/api/users/me/ideas')['member'][0]] as $view) {
            self::assertSame('owner', $view['view']);
            self::assertArrayNotHasKey('author', $view);
            self::assertArrayNotHasKey('isSuggestion', $view);
        }
    }

    public function testAPrivateIdeaIsVisibleToItsAuthorOnly(): void
    {
        $personalDraft = $this->createIdea($this->owner, ['title' => 'My draft', 'visibility' => 'private']);
        $suggestionDraft = $this->createIdea($this->friend, ['title' => 'Friend draft', 'ownerId' => $this->ownerId, 'visibility' => 'private']);

        // The owner's personal draft: not on the friend's view of the list, nor by id, nor in the counter.
        self::assertSame(
            ['Friend draft'],
            array_column($this->get($this->friend, "/api/users/{$this->ownerId}/ideas")['member'], 'title'),
            'the friend sees only their own draft on that list',
        );
        $this->request($this->friend, 'GET', "/api/ideas/{$personalDraft['id']}");
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->get($this->other, '/api/friendships')[0]['ideaCount']);

        // A friend's draft for the owner: invisible to the owner and to every other friend.
        foreach ([$this->owner, $this->other] as $token) {
            $this->request($token, 'GET', "/api/ideas/{$suggestionDraft['id']}");
            self::assertResponseStatusCodeSame(404);
        }
        self::assertSame(0, $this->get($this->other, "/api/users/{$this->ownerId}/ideas")['totalItems']);

        // The author sees it on the list ("Mes brouillons pour X") and on « Privées ».
        $friendView = $this->get($this->friend, "/api/users/{$this->ownerId}/ideas?visibility=private");
        self::assertSame(['Friend draft'], array_column($friendView['member'], 'title'));
        self::assertTrue($friendView['member'][0]['isMine']);
        self::assertSame(['Friend draft'], array_column($this->get($this->friend, '/api/ideas/private'), 'title'));
        self::assertSame(['My draft'], array_column($this->get($this->owner, '/api/ideas/private'), 'title'));
    }

    public function testAStrangerSeesNothing(): void
    {
        $idea = $this->createIdea($this->owner, ['title' => 'Public to friends']);

        $this->request($this->stranger, 'GET', "/api/users/{$this->ownerId}/ideas");
        self::assertResponseStatusCodeSame(404);
        $this->request($this->stranger, 'GET', "/api/ideas/{$idea['id']}");
        self::assertResponseStatusCodeSame(404);
        $this->request($this->stranger, 'PATCH', "/api/ideas/{$idea['id']}", ['title' => 'x'], 'application/merge-patch+json');
        self::assertResponseStatusCodeSame(404);

        // Nor can they suggest: same 404 as a non-existent user.
        $this->request($this->stranger, 'POST', '/api/ideas', ['title' => 'Hi', 'ownerId' => $this->ownerId]);
        self::assertResponseStatusCodeSame(404);
        $this->request($this->stranger, 'POST', '/api/ideas', ['title' => 'Hi', 'ownerId' => '0190a1b2-0000-7000-8000-000000000001']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRemovingAFriendHidesTheirListAndTheirPublishedSuggestionsFromThem(): void
    {
        $personal = $this->createIdea($this->owner, ['title' => 'Personal']);
        $suggestion = $this->createIdea($this->friend, ['title' => 'Suggested', 'ownerId' => $this->ownerId]);
        $draft = $this->createIdea($this->friend, ['title' => 'Draft', 'ownerId' => $this->ownerId, 'visibility' => 'private']);

        $friendId = $this->userId($this->friend);
        foreach ($this->get($this->owner, '/api/friendships') as $f) {
            if ($f['user']['id'] === $friendId) {
                $this->request($this->owner, 'DELETE', "/api/friendships/{$f['id']}");
            }
        }

        $this->request($this->friend, 'GET', "/api/users/{$this->ownerId}/ideas");
        self::assertResponseStatusCodeSame(404);
        foreach ([$personal, $suggestion] as $idea) {
            $this->request($this->friend, 'GET', "/api/ideas/{$idea['id']}");
            self::assertResponseStatusCodeSame(404);
        }

        // Spec §5.3: their suggestion stays for the remaining friends, still hidden from the owner.
        self::assertSame(2, $this->get($this->other, "/api/users/{$this->ownerId}/ideas")['totalItems']);
        $this->request($this->owner, 'GET', "/api/ideas/{$suggestion['id']}");
        self::assertResponseStatusCodeSame(404);

        // Spec §5.4: their draft stays readable by them, but can't be published any more.
        self::assertSame(200, $this->request($this->friend, 'GET', "/api/ideas/{$draft['id']}"));
        $this->request($this->friend, 'POST', "/api/ideas/{$draft['id']}/publish");
        self::assertResponseStatusCodeSame(422);
        self::assertSame('idea.friendship_required', $this->lastJson()['code']);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function createIdea(string $token, array $body): array
    {
        $this->request($token, 'POST', '/api/ideas', $body);
        self::assertResponseStatusCodeSame(201);

        return $this->lastJson();
    }

    /**
     * @return array<mixed>
     */
    private function get(string $token, string $path): array
    {
        $status = $this->request($token, 'GET', $path);
        self::assertSame(200, $status, $path);

        return $this->lastJson();
    }

    /** @var array<mixed> */
    private array $last = [];

    /**
     * @param array<string, mixed>|null $json
     */
    private function request(string $token, string $method, string $path, ?array $json = null, string $contentType = 'application/json'): int
    {
        $options = ['auth_bearer' => $token, 'headers' => ['Content-Type' => $contentType]];
        if (null !== $json) {
            $options['json'] = $json;
        }
        $response = static::createClient()->request($method, $path, $options);
        $content = $response->getContent(false);
        $this->last = '' === $content ? [] : (array) json_decode($content, true);

        return $response->getStatusCode();
    }

    /**
     * @return array<mixed>
     */
    private function lastJson(): array
    {
        return $this->last;
    }
}
