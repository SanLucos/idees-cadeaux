<?php

declare(strict_types=1);

namespace App\Tests\Visibility;

use App\Tests\Functional\ApiRequestTrait;
use App\Tests\Functional\AuthTestCase;
use App\Tests\Functional\DataExportTestTrait;

/**
 * Lot 8 cases of the visibility suite (spec §4.4 "ni via export", §5.13
 * "Exclut tout élément caché créé par des amis sur ses propres idées"):
 * an owner's export never holds what friends did on their ideas —
 * suggestions, reservations, comments, reactions, contributions — nor a
 * friend's private draft, another participant's amount (règle 3) or a
 * `declined` status (règle 4).
 *
 * Cast: Camille (exports); Hugo and Léa, her friends; Marc, who declined
 * Camille's friend request.
 */
final class DataExportVisibilityTest extends AuthTestCase
{
    use ApiRequestTrait;
    use DataExportTestTrait;

    public function testAnOwnersExportHoldsNothingHiddenFromThem(): void
    {
        $camille = $this->registerVerifyAndLogin('dxv-camille@example.com');
        $hugo = $this->registerVerifyAndLogin('dxv-hugo@example.com');
        $lea = $this->registerVerifyAndLogin('dxv-lea@example.com');
        $marc = $this->registerVerifyAndLogin('dxv-marc@example.com');
        $this->expect(200, $hugo, 'PATCH', '/api/users/me', ['displayName' => 'Hugo']);
        $this->expect(200, $lea, 'PATCH', '/api/users/me', ['displayName' => 'Lea']);
        $this->befriend($camille, 'dxv-camille@example.com', $hugo, 'dxv-hugo@example.com');
        $this->befriend($camille, 'dxv-camille@example.com', $lea, 'dxv-lea@example.com');
        $this->befriend($hugo, 'dxv-hugo@example.com', $lea, 'dxv-lea@example.com');
        $camilleId = $this->userId($camille);

        // Friends' secret activity on Camille's list.
        $mine = $this->expect(201, $camille, 'POST', '/api/ideas', ['title' => 'Camille idea']);
        $suggestion = $this->expect(201, $hugo, 'POST', '/api/ideas', ['title' => 'SECRET suggestion', 'ownerId' => $camilleId]);
        $this->expect(201, $lea, 'POST', '/api/ideas', ['title' => 'SECRET draft', 'ownerId' => $camilleId, 'visibility' => 'private']);
        $this->expect(201, $hugo, 'POST', '/api/reservations', ['ideaId' => $mine['id']]);
        $this->expect(201, $lea, 'POST', '/api/comments', ['ideaId' => $mine['id'], 'body' => 'SECRET comment']);
        $this->expect(200, $lea, 'PUT', "/api/ideas/{$mine['id']}/reaction");
        $pool = $this->expect(201, $lea, 'POST', '/api/contributions', ['ideaId' => $suggestion['id'], 'targetAmount' => '777']);
        $this->expect(200, $lea, 'PUT', "/api/contributions/{$pool['id']}/pledge", ['amount' => '311']);

        // Camille's own activity on Hugo's list, with Léa's amount next to hers.
        $vinyl = $this->expect(201, $hugo, 'POST', '/api/ideas', ['title' => 'Hugo vinyl']);
        $hugoPool = $this->expect(201, $lea, 'POST', '/api/contributions', ['ideaId' => $vinyl['id']]);
        $this->expect(200, $lea, 'PUT', "/api/contributions/{$hugoPool['id']}/pledge", ['amount' => '299']);
        $this->expect(200, $camille, 'PUT', "/api/contributions/{$hugoPool['id']}/pledge", ['amount' => '42']);

        // A request Marc declined: Camille must not learn it.
        $this->expect(200, $camille, 'POST', '/api/friendships', ['email' => 'dxv-marc@example.com']);
        $request = $this->expect(200, $marc, 'GET', '/api/friendships/incoming')[0];
        $this->expect(200, $marc, 'POST', "/api/friendships/{$request['id']}/decline");

        $this->expect(202, $camille, 'POST', '/api/account/export', ['password' => 'correcthorsebattery']);
        $files = $this->runExport()['files'];
        $everything = implode("\n", $files);

        foreach (['SECRET', '777', '311', '299', 'declined'] as $hidden) {
            self::assertStringNotContainsString($hidden, $everything, "« {$hidden} » leaked into the export");
        }
        self::assertSame([], json_decode($files['reservations.json'], true));
        self::assertSame([], json_decode($files['comments.json'], true));
        self::assertSame([], json_decode($files['reactions.json'], true));
        self::assertSame(['Camille idea'], array_column(json_decode($files['ideas.json'], true), 'title'));
        self::assertSame(['42.00'], array_column(json_decode($files['contributions.json'], true)['pledges'], 'amount'), 'only her own pledge');
        self::assertSame('pending', json_decode($files['friends.json'], true)['requestsSent'][0]['status']);
    }
}
