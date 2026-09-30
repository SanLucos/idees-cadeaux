<?php

declare(strict_types=1);

namespace App\Tests\Visibility;

use App\Tests\Functional\ApiRequestTrait;
use App\Tests\Functional\AuthTestCase;
use App\Tests\Functional\DataExportTestTrait;

/**
 * Size history (spec §5.2, §11 décision 51): a private part of a
 * resource friends otherwise read. The past values of a size reach its
 * owner alone — by the API, /sync and the export — and never a friend,
 * who keeps reading the current value, nor anyone else. Removing a
 * past value is the owner's alone too.
 * The child-profile cases are in ManagedProfileVisibilityTest.
 *
 * Cast: Camille (owner); Hugo, her friend; Stranger.
 */
final class ProfileSizeHistoryVisibilityTest extends AuthTestCase
{
    use ApiRequestTrait;
    use DataExportTestTrait;

    /** A value only the history holds: finding it anywhere is a leak. */
    private const string PAST_VALUE = 'PAST-36';

    private string $camille;
    private string $hugo;
    private string $stranger;
    private string $camilleId;

    /** @var array<string, mixed> */
    private array $size;

    protected function setUp(): void
    {
        parent::setUp();

        $this->camille = $this->registerVerifyAndLogin('sh-camille@example.com');
        $this->hugo = $this->registerVerifyAndLogin('sh-hugo@example.com');
        $this->stranger = $this->registerVerifyAndLogin('sh-stranger@example.com');
        $this->befriend($this->camille, 'sh-camille@example.com', $this->hugo, 'sh-hugo@example.com');
        $this->camilleId = $this->userId($this->camille);

        $this->size = $this->expect(201, $this->camille, 'POST', '/api/profile_sizes', ['label' => 'Pointure', 'value' => self::PAST_VALUE]);
        $this->expect(200, $this->camille, 'PATCH', $this->size['@id'], ['value' => '38']);
    }

    public function testTheOwnerReadsTheirHistoryByTheApiAndTheSync(): void
    {
        $item = $this->expect(200, $this->camille, 'GET', $this->size['@id']);
        self::assertSame([self::PAST_VALUE, '38'], array_column($item['history'], 'value'));

        $collection = $this->expect(200, $this->camille, 'GET', '/api/profile_sizes');
        self::assertSame($item['history'], $collection['member'][0]['history']);

        $synced = $this->expect(200, $this->camille, 'POST', '/api/sync', [])['changes']['profile_size'][0];
        self::assertSame($item['history'], $synced['history'], 'the synced document is the API\'s view');
    }

    public function testAFriendReadsTheCurrentValueAndNeverThePastOnes(): void
    {
        $item = $this->expect(200, $this->hugo, 'GET', $this->size['@id']);
        self::assertSame('38', $item['value']);
        self::assertArrayNotHasKey('history', $item);

        $collection = $this->expect(200, $this->hugo, 'GET', "/api/profile_sizes?userId={$this->camilleId}");
        self::assertSame(1, $collection['totalItems']);
        self::assertArrayNotHasKey('history', $collection['member'][0]);
        self::assertStringNotContainsString(self::PAST_VALUE, json_encode($collection));

        $sync = $this->expect(200, $this->hugo, 'POST', '/api/sync', []);
        self::assertSame('38', $sync['changes']['profile_size'][0]['value']);
        self::assertArrayNotHasKey('history', $sync['changes']['profile_size'][0]);
        self::assertStringNotContainsString(self::PAST_VALUE, json_encode($sync));

        $fetched = $this->expect(200, $this->hugo, 'POST', '/api/sync/fetch', ['type' => 'profile_size', 'ids' => [basename($this->size['@id'])]]);
        self::assertCount(1, $fetched['documents']);
        self::assertStringNotContainsString(self::PAST_VALUE, json_encode($fetched));
    }

    public function testAStrangerReadsNothingAtAll(): void
    {
        $this->expect(404, $this->stranger, 'GET', $this->size['@id']);
        self::assertSame(0, $this->expect(200, $this->stranger, 'GET', "/api/profile_sizes?userId={$this->camilleId}")['totalItems']);
        self::assertStringNotContainsString(self::PAST_VALUE, json_encode($this->expect(200, $this->stranger, 'POST', '/api/sync', [])));
    }

    public function testOnlyTheOwnerRemovesFromTheHistory(): void
    {
        $past = $this->expect(200, $this->camille, 'GET', $this->size['@id'])['history'][0];

        // A 404, not a 403, even for a friend who reads the size: the history doesn't exist for them.
        foreach ([$this->hugo, $this->stranger] as $token) {
            self::assertSame('resource.not_found', $this->expect(404, $token, 'DELETE', "{$this->size['@id']}/history/{$past['id']}")['code']);
        }
        self::assertSame([self::PAST_VALUE, '38'], array_column($this->expect(200, $this->camille, 'GET', $this->size['@id'])['history'], 'value'));

        $this->expect(204, $this->camille, 'DELETE', "{$this->size['@id']}/history/{$past['id']}");
        self::assertSame(['38'], array_column($this->expect(200, $this->camille, 'GET', $this->size['@id'])['history'], 'value'));
    }

    public function testTheHistoryIsInItsOwnersExport(): void
    {
        $this->expect(202, $this->camille, 'POST', '/api/account/export', ['password' => 'correcthorsebattery']);
        $sizes = json_decode($this->runExport()['files']['sizes.json'], true);

        self::assertSame([self::PAST_VALUE, '38'], array_column($sizes[0]['history'], 'value'));
    }

    public function testTheHistoryIsNotInAFriendsExport(): void
    {
        $this->expect(202, $this->hugo, 'POST', '/api/account/export', ['password' => 'correcthorsebattery']);

        self::assertStringNotContainsString(self::PAST_VALUE, implode("\n", $this->runExport()['files']));
    }
}
