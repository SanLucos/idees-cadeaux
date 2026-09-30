<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;

/**
 * Spec §5.2, §11 décision 51: every value a size has had is kept, with
 * its date, until the owner removes it from the history. Who may read
 * or remove it: tests/Visibility.
 */
final class ProfileSizeHistoryTest extends AuthTestCase
{
    use ApiRequestTrait;

    private string $camille;

    protected function setUp(): void
    {
        parent::setUp();
        $this->camille = $this->registerVerifyAndLogin('psh-camille@example.com');
    }

    public function testEachNewValueIsKeptWithItsDate(): void
    {
        $size = $this->expect(201, $this->camille, 'POST', '/api/profile_sizes', ['label' => 'Pointure', 'value' => '36']);
        self::assertSame(['36'], array_column($size['history'], 'value'), 'the history starts with the first value');

        // The first value dates from last year; the next ones follow at once: all are kept.
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE profile_size_history SET created_at = created_at - INTERVAL '1 year'");
        $this->expect(200, $this->camille, 'PATCH', $size['@id'], ['value' => '37']);
        $history = $this->expect(200, $this->camille, 'PATCH', $size['@id'], ['value' => '38'])['history'];

        self::assertSame(['36', '37', '38'], array_column($history, 'value'), 'oldest first, the current value last');
        self::assertLessThan(new \DateTimeImmutable($history[1]['since']), new \DateTimeImmutable($history[0]['since']));
        self::assertEqualsWithDelta(time(), (new \DateTimeImmutable($history[2]['since']))->getTimestamp(), 60);
    }

    public function testOnlyAChangeOfValueAddsToTheHistory(): void
    {
        $size = $this->expect(201, $this->camille, 'POST', '/api/profile_sizes', ['label' => 'Pointure', 'value' => '36']);

        $patched = $this->expect(200, $this->camille, 'PATCH', $size['@id'], ['label' => 'Chaussures', 'note' => 'Large', 'sortOrder' => 3, 'value' => '36']);

        self::assertSame('Chaussures', $patched['label']);
        self::assertSame(['36'], array_column($patched['history'], 'value'));
    }

    public function testAPastValueIsRemovedFromTheHistoryButNeverTheCurrentOne(): void
    {
        $size = $this->expect(201, $this->camille, 'POST', '/api/profile_sizes', ['label' => 'Pointure', 'value' => '36']);
        $this->expect(200, $this->camille, 'PATCH', $size['@id'], ['value' => '73']);
        [$first, $typo, $current] = $this->expect(200, $this->camille, 'PATCH', $size['@id'], ['value' => '37'])['history'];

        $this->expect(204, $this->camille, 'DELETE', "{$size['@id']}/history/{$typo['id']}");
        $after = $this->expect(200, $this->camille, 'GET', $size['@id']);
        self::assertSame([$first, $current], $after['history']);
        self::assertSame('37', $after['value'], 'the size itself is untouched');

        // Replayed (spec §8), or an id that was never there: same answer, nothing else removed.
        $this->expect(204, $this->camille, 'DELETE', "{$size['@id']}/history/{$typo['id']}");
        $this->expect(204, $this->camille, 'DELETE', "{$size['@id']}/history/0190a1b2-0000-7000-8000-00000000ffff");

        self::assertSame('profile_size.history_current', $this->expect(422, $this->camille, 'DELETE', "{$size['@id']}/history/{$current['id']}")['code']);
        self::assertSame([$first, $current], $this->expect(200, $this->camille, 'GET', $size['@id'])['history']);

        $this->expect(404, $this->camille, 'DELETE', "/api/profile_sizes/0190a1b2-0000-7000-8000-00000000ffff/history/{$first['id']}");
    }

    public function testRemovingFromTheHistoryReachesTheDevices(): void
    {
        $size = $this->expect(201, $this->camille, 'POST', '/api/profile_sizes', ['label' => 'Pointure', 'value' => '36']);
        $past = $this->expect(200, $this->camille, 'PATCH', $size['@id'], ['value' => '37'])['history'][0];
        $before = $this->expect(200, $this->camille, 'POST', '/api/sync', []);
        sleep(3); // Past the sync's resolution and overlap: only a version that moved is sent again.

        $this->expect(204, $this->camille, 'DELETE', "{$size['@id']}/history/{$past['id']}");

        $after = $this->expect(200, $this->camille, 'POST', '/api/sync', ['since' => $before['cursor'], 'hashes' => $before['hashes']]);
        self::assertSame(['37'], array_column($after['changes']['profile_size'][0]['history'], 'value'));
    }

    public function testTheHistoryGoesWithItsSize(): void
    {
        $size = $this->expect(201, $this->camille, 'POST', '/api/profile_sizes', ['label' => 'Pointure', 'value' => '36']);
        $this->expect(200, $this->camille, 'PATCH', $size['@id'], ['value' => '37']);

        $this->expect(204, $this->camille, 'DELETE', $size['@id']);

        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM profile_size_history'));
    }
}
