<?php

declare(strict_types=1);

namespace App\Tests\Visibility;

use App\Tests\Functional\ApiRequestTrait;
use App\Tests\Functional\AuthTestCase;

/**
 * Lot 6 cases of the visibility suite (spec §4 "Conséquences" 3–4:
 * "le même filtrage s'applique au endpoint de synchronisation"): /sync
 * and /sync/fetch never name — in a document, a manifest or anywhere in
 * the payload — anything the API hides from that user; each synced
 * document is exactly the API's view of it; an item that becomes
 * invisible leaves the inventory (so devices purge it).
 */
final class SyncVisibilityTest extends AuthTestCase
{
    use ApiRequestTrait;

    private string $owner;
    private string $hugo;
    private string $lea;
    private string $stranger;
    private string $ownerId;

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->registerVerifyAndLogin('sv-owner@example.com');
        $this->hugo = $this->registerVerifyAndLogin('sv-hugo@example.com');
        $this->lea = $this->registerVerifyAndLogin('sv-lea@example.com');
        $this->stranger = $this->registerVerifyAndLogin('sv-stranger@example.com');
        $this->befriend($this->owner, 'sv-owner@example.com', $this->hugo, 'sv-hugo@example.com');
        $this->befriend($this->owner, 'sv-owner@example.com', $this->lea, 'sv-lea@example.com');
        $this->ownerId = $this->userId($this->owner);

        // The owner's list, with everything hidden from them on it.
        $this->ids['personal'] = $this->expect(201, $this->owner, 'POST', '/api/ideas', ['title' => 'Casque', 'priceAmount' => '100'])['id'];
        $this->ids['ownerDraft'] = $this->expect(201, $this->owner, 'POST', '/api/ideas', ['title' => 'Draft', 'visibility' => 'private'])['id'];
        $this->ids['reservation'] = $this->expect(201, $this->lea, 'POST', '/api/reservations', ['ideaId' => $this->ids['personal']])['reservation']['id'];
        $this->ids['comment'] = $this->expect(201, $this->hugo, 'POST', '/api/comments', ['ideaId' => $this->ids['personal'], 'body' => 'Top'])['id'];
        $this->expect(200, $this->hugo, 'PUT', "/api/ideas/{$this->ids['personal']}/reaction");
        $this->ids['suggestion'] = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Surprise', 'ownerId' => $this->ownerId, 'priceAmount' => '60'])['id'];
        $this->ids['contribution'] = $this->expect(201, $this->hugo, 'POST', '/api/contributions', ['ideaId' => $this->ids['suggestion']])['id'];
        $this->expect(200, $this->lea, 'PUT', "/api/contributions/{$this->ids['contribution']}/pledge", ['amount' => '25']);
        $this->ids['hugoDraft'] = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Hugo draft', 'ownerId' => $this->ownerId, 'visibility' => 'private'])['id'];
        $this->ids['leaPrivate'] = $this->expect(201, $this->lea, 'POST', '/api/ideas', ['title' => 'Léa private', 'visibility' => 'private'])['id'];
    }

    public function testTheOwnersSyncNamesNothingHiddenFromThem(): void
    {
        $sync = $this->expect(200, $this->owner, 'POST', '/api/sync', []);
        $raw = json_encode($sync);

        foreach (['suggestion', 'hugoDraft', 'leaPrivate', 'reservation', 'comment', 'contribution'] as $hidden) {
            self::assertStringNotContainsString($this->ids[$hidden], $raw, "{$hidden} must not appear anywhere in the owner's sync");
        }
        $own = [$this->ids['personal'], $this->ids['ownerDraft']];
        sort($own);
        self::assertSame($own, $this->sortedIds($sync['changes']['idea']), 'own ideas only');
        self::assertArrayNotHasKey('comment', $sync['changes']);
        foreach ($sync['changes']['idea'] as $doc) {
            self::assertSame('owner', $doc['view']);
            foreach (['reservation', 'contribution', 'reactions', 'commentCount', 'author', 'isSuggestion'] as $field) {
                self::assertArrayNotHasKey($field, $doc);
            }
        }

        // Asking for hidden ids by name returns nothing.
        foreach (['idea' => ['suggestion', 'hugoDraft', 'leaPrivate'], 'comment' => ['comment']] as $type => $names) {
            $fetched = $this->expect(200, $this->owner, 'POST', '/api/sync/fetch', ['type' => $type, 'ids' => array_map(fn ($n) => $this->ids[$n], $names)]);
            self::assertSame([], $fetched['documents']);
        }
    }

    public function testEachSyncedDocumentIsExactlyTheApisViewForThatUser(): void
    {
        foreach (['hugo' => $this->hugo, 'lea' => $this->lea, 'owner' => $this->owner] as $name => $token) {
            $sync = $this->expect(200, $token, 'POST', '/api/sync', []);
            foreach ($sync['changes']['idea'] ?? [] as $doc) {
                unset($doc['_version']);
                self::assertEquals($this->expect(200, $token, 'GET', "/api/ideas/{$doc['id']}"), $doc, "{$name}: idea {$doc['title']}");
            }
            foreach ($sync['changes']['comment'] ?? [] as $doc) {
                $thread = $this->expect(200, $token, 'GET', "/api/ideas/{$doc['ideaId']}/comments");
                unset($doc['_version']);
                self::assertContainsEquals($doc, $thread);
            }
        }

        // Friends' drafts stay their author's (règle 2): Léa never gets Hugo's, Hugo never Léa's.
        $leaIdeas = $this->sortedIds($this->expect(200, $this->lea, 'POST', '/api/sync', [])['changes']['idea']);
        self::assertNotContains($this->ids['hugoDraft'], $leaIdeas);
        self::assertContains($this->ids['leaPrivate'], $leaIdeas);
        $hugoIdeas = $this->sortedIds($this->expect(200, $this->hugo, 'POST', '/api/sync', [])['changes']['idea']);
        self::assertNotContains($this->ids['leaPrivate'], $hugoIdeas);
        self::assertContains($this->ids['hugoDraft'], $hugoIdeas);

        // Pledge amounts in the sync follow règle 3 like the API: Hugo (initiator) sees Léa's, the stranger nothing.
        $suggestion = array_values(array_filter($this->expect(200, $this->hugo, 'POST', '/api/sync', [])['changes']['idea'], fn ($d) => $d['id'] === $this->ids['suggestion']))[0];
        self::assertSame('25.00', $suggestion['contribution']['participants'][0]['amount']);
        self::assertStringNotContainsString($this->ids['personal'], json_encode($this->expect(200, $this->stranger, 'POST', '/api/sync', [])));
    }

    public function testWhatBecomesInvisibleLeavesTheInventory(): void
    {
        $before = $this->expect(200, $this->hugo, 'POST', '/api/sync', []);
        self::assertContains($this->ids['personal'], $this->manifestIds($before, 'idea'));

        // Léa's personal idea goes private: it leaves Hugo's inventory, and is never named to him.
        $leaIdea = $this->expect(201, $this->lea, 'POST', '/api/ideas', ['title' => 'Soon private'])['id'];
        $this->befriend($this->hugo, 'sv-hugo@example.com', $this->lea, 'sv-lea@example.com');
        $mid = $this->expect(200, $this->hugo, 'POST', '/api/sync', ['since' => $before['cursor'], 'hashes' => $before['hashes']]);
        self::assertContains($leaIdea, $this->manifestIds($mid, 'idea'));
        $this->expect(200, $this->lea, 'POST', "/api/ideas/{$leaIdea}/unpublish");
        $after = $this->expect(200, $this->hugo, 'POST', '/api/sync', ['since' => $mid['cursor'], 'hashes' => $mid['hashes']]);
        self::assertNotContains($leaIdea, $this->manifestIds($after, 'idea'), 'purged from Hugo\'s devices');
        self::assertStringNotContainsString($leaIdea, json_encode($after['changes']));

        // Friend removal: the owner's whole list and profile leave Hugo's inventory.
        foreach ($this->expect(200, $this->owner, 'GET', '/api/friendships') as $f) {
            if ($f['user']['id'] === $this->userId($this->hugo)) {
                $this->expect(200, $this->owner, 'DELETE', "/api/friendships/{$f['id']}");
            }
        }
        $removed = $this->expect(200, $this->hugo, 'POST', '/api/sync', ['since' => $after['cursor'], 'hashes' => $after['hashes']]);
        self::assertNotContains($this->ids['personal'], $this->manifestIds($removed, 'idea'));
        self::assertNotContains($this->ids['comment'], $this->manifestIds($removed, 'comment'));
        self::assertNotContains($this->ownerId, $this->manifestIds($removed, 'user'));
    }

    public function testTheManagerSyncsTheChildsListAndNothingElseOfItsFriends(): void
    {
        $jules = $this->expect(201, $this->owner, 'POST', '/api/managed-profiles', ['displayName' => 'Jules', 'parentalConsent' => true])['id'];
        $this->expect(200, $this->owner, 'POST', '/api/friendships', ['email' => 'sv-hugo@example.com'], $jules);
        $request = $this->expect(200, $this->hugo, 'GET', '/api/friendships/incoming')[0];
        $this->expect(200, $this->hugo, 'POST', "/api/friendships/{$request['id']}/accept");
        $childIdea = $this->expect(201, $this->owner, 'POST', '/api/ideas', ['title' => 'Lego'], $jules)['id'];
        $forJules = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Vélo', 'ownerId' => $jules])['id'];
        $hugoDraftForJules = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Hugo draft for Jules', 'ownerId' => $jules, 'visibility' => 'private'])['id'];

        $sync = $this->expect(200, $this->owner, 'POST', '/api/sync', []);
        $ideas = array_column($sync['changes']['idea'], null, 'id');
        self::assertSame('manager', $ideas[$childIdea]['view']);
        self::assertSame('manager', $ideas[$forJules]['view'], 'the manager view shows friends\' suggestions');
        self::assertStringNotContainsString($hugoDraftForJules, json_encode($sync), 'never a friend\'s draft (règle 2)');
        self::assertContains($jules, array_column($sync['changes']['friendship'], 'profileId'), 'the child\'s friendships, tagged with the child');
        self::assertContains($jules, array_column($sync['changes']['managed_profile'], 'id'));

        // Hugo syncs Jules's list as a friend, never as a manager.
        $hugoDocs = array_column($this->expect(200, $this->hugo, 'POST', '/api/sync', [])['changes']['idea'], null, 'id');
        self::assertSame('friend', $hugoDocs[$childIdea]['view']);
    }

    /**
     * @param list<array<string, mixed>> $docs
     *
     * @return string[]
     */
    private function sortedIds(array $docs): array
    {
        $ids = array_column($docs, 'id');
        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, mixed> $sync
     *
     * @return string[]
     */
    private function manifestIds(array $sync, string $type): array
    {
        // Present whenever the device's hash is stale — always the case in these tests.
        self::assertArrayHasKey($type, $sync['manifests'], "{$type} inventory expected");

        return array_column($sync['manifests'][$type], 0);
    }
}
