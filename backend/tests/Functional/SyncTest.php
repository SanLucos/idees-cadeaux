<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Lot 6 sync protocol (spec §8, §11 décision 32): first sync, quiet
 * resync, delta + inventory after changes, versions moved by
 * interactions (deletions included), fetch, errors. Who-sees-what is
 * tests/Visibility/SyncVisibilityTest.
 */
final class SyncTest extends AuthTestCase
{
    use ApiRequestTrait;

    public function testFirstSyncThenAQuietResync(): void
    {
        $alice = $this->registerVerifyAndLogin('sy-alice@example.com');
        $this->expect(201, $alice, 'POST', '/api/ideas', ['title' => 'Mine']);
        $this->expect(201, $alice, 'POST', '/api/profile_sizes', ['label' => 'Pointure', 'value' => '38']);
        // Changes within 2 s of a cursor are re-sent by design (1 s version resolution).
        sleep(3);

        $first = $this->expect(200, $alice, 'POST', '/api/sync', []);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $first['cursor']);
        self::assertCount(1, $first['changes']['idea']);
        self::assertSame('Pointure', $first['changes']['profile_size'][0]['label']);
        self::assertCount(14, $first['changes']['occasion']);
        self::assertSame('owner', $first['changes']['idea'][0]['view']);
        self::assertArrayHasKey('_version', $first['changes']['idea'][0]);
        self::assertArrayHasKey('idea', $first['manifests'], 'a device with no hashes gets every inventory');

        $again = $this->expect(200, $alice, 'POST', '/api/sync', ['since' => $first['cursor'], 'hashes' => $first['hashes']]);
        self::assertEquals([], (array) $again['changes'], 'nothing changed');
        self::assertEquals([], (array) $again['manifests'], 'hashes match: no inventory resent');
    }

    public function testInteractionsMoveTheIdeasVersionIncludingCancellations(): void
    {
        $owner = $this->registerVerifyAndLogin('sy-owner@example.com');
        $friend = $this->registerVerifyAndLogin('sy-friend@example.com');
        $this->befriend($owner, 'sy-owner@example.com', $friend, 'sy-friend@example.com');
        $idea = $this->expect(201, $owner, 'POST', '/api/ideas', ['title' => 'Casque'])['id'];

        $base = $this->expect(200, $friend, 'POST', '/api/sync', []);
        $version = array_column($base['changes']['idea'], '_version', 'id')[$idea];

        sleep(2);
        $reservation = $this->expect(201, $friend, 'POST', '/api/reservations', ['ideaId' => $idea])['reservation']['id'];
        $afterReserve = $this->expect(200, $friend, 'POST', '/api/sync', ['since' => $base['cursor'], 'hashes' => $base['hashes']]);
        $doc = array_column($afterReserve['changes']['idea'], null, 'id')[$idea];
        self::assertGreaterThan($version, $doc['_version']);
        self::assertTrue($doc['reservation']['isMine']);
        self::assertArrayHasKey('idea', $afterReserve['manifests'], 'the idea hash moved');

        sleep(2);
        $this->expect(200, $friend, 'DELETE', "/api/reservations/{$reservation}");
        $afterCancel = $this->expect(200, $friend, 'POST', '/api/sync', ['since' => $afterReserve['cursor'], 'hashes' => $afterReserve['hashes']]);
        $doc = array_column($afterCancel['changes']['idea'], null, 'id')[$idea];
        self::assertNull($doc['reservation'], 'a soft-deleted reservation still moves the version');
    }

    public function testFetchAndErrors(): void
    {
        $alice = $this->registerVerifyAndLogin('sy-fetch@example.com');
        $idea = $this->expect(201, $alice, 'POST', '/api/ideas', ['title' => 'Mine'])['id'];

        $fetched = $this->expect(200, $alice, 'POST', '/api/sync/fetch', ['type' => 'idea', 'ids' => [$idea, '0190a1b2-0000-7000-8000-00000000ffff']]);
        self::assertSame([$idea], array_column($fetched['documents'], 'id'));

        self::assertSame('sync.cursor_invalid', $this->expect(400, $alice, 'POST', '/api/sync', ['since' => 'yesterday'])['code']);
        self::assertSame('sync.fetch_invalid', $this->expect(400, $alice, 'POST', '/api/sync/fetch', ['type' => 'secret', 'ids' => []])['code']);

        $child = $this->expect(201, $alice, 'POST', '/api/managed-profiles', ['displayName' => 'Jules', 'parentalConsent' => true])['id'];
        self::assertSame('acting_as.not_allowed', $this->expect(403, $alice, 'POST', '/api/sync', [], $child)['code'], 'always the adult\'s sync');
    }
}
