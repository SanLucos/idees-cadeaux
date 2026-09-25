<?php

declare(strict_types=1);

namespace App\Tests\Visibility;

use App\Tests\Functional\ApiRequestTrait;
use App\Tests\Functional\AuthTestCase;

/**
 * Lot 7 bis cases of the visibility suite (spec §5.16, CLAUDE.md règle
 * 11): the guest view — API and web page — shows the owner's pseudo,
 * avatar and published personal ideas, and nothing else. It is the
 * owner view: an owner who opens their own link signed out learns
 * nothing about their friends' secret activity either. Every refusal
 * is the same answer, so a link's state can't be probed.
 *
 * Cast: Camille (owner, has a link); Hugo and Léa, her friends.
 */
final class ShareLinkVisibilityTest extends AuthTestCase
{
    use ApiRequestTrait;

    private const array FORBIDDEN_KEYS = [
        'reservation', 'reservations', 'comments', 'commentCount', 'contribution', 'lastContribution', 'reactions',
        'isSuggestion', 'author', 'isMine', 'status', 'visibility', 'archivedAt', 'archiveKind', 'counts',
        'birthDay', 'birthMonth', 'birthYear', 'sizes', 'preferences', 'friends', 'email', 'ownerId', 'id_owner',
    ];

    private string $camille;
    private string $hugo;
    private string $lea;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->camille = $this->registerVerifyAndLogin('slv-camille@example.com');
        $this->hugo = $this->registerVerifyAndLogin('slv-hugo@example.com');
        $this->lea = $this->registerVerifyAndLogin('slv-lea@example.com');
        $this->expect(200, $this->camille, 'PATCH', '/api/users/me', ['displayName' => 'Camille', 'birthDay' => 12, 'birthMonth' => 3]);
        $this->expect(200, $this->hugo, 'PATCH', '/api/users/me', ['displayName' => 'Hugo']);
        $this->befriend($this->camille, 'slv-camille@example.com', $this->hugo, 'slv-hugo@example.com');
        $this->befriend($this->camille, 'slv-camille@example.com', $this->lea, 'slv-lea@example.com');
        $this->token = $this->expect(201, $this->camille, 'POST', '/api/share-link', ['confirmed' => true])['link']['token'];
    }

    public function testTheGuestViewOnlyHasPublishedPersonalIdeasAndNoSecretActivity(): void
    {
        $camilleId = $this->userId($this->camille);
        $published = $this->expect(201, $this->camille, 'POST', '/api/ideas', ['title' => 'Published idea', 'note' => 'Taille M', 'occasion' => 'birthday']);
        $this->expect(201, $this->camille, 'POST', '/api/ideas', ['title' => 'Private draft', 'visibility' => 'private']);
        $archived = $this->expect(201, $this->camille, 'POST', '/api/ideas', ['title' => 'Archived idea']);
        $this->expect(200, $this->camille, 'POST', "/api/ideas/{$archived['id']}/archive", ['kind' => 'received']);
        $suggestion = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Secret suggestion', 'ownerId' => $camilleId, 'occasion' => 'christmas']);
        $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Hugo draft', 'ownerId' => $camilleId, 'visibility' => 'private']);
        $this->expect(201, $this->camille, 'POST', '/api/profile_sizes', ['label' => 'Chaussures', 'value' => '38']);

        $this->expect(201, $this->hugo, 'POST', '/api/reservations', ['ideaId' => $published['id']]);
        $this->expect(201, $this->lea, 'POST', '/api/comments', ['ideaId' => $published['id'], 'body' => 'Secret comment']);
        $this->expect(200, $this->lea, 'PUT', "/api/ideas/{$published['id']}/reaction");
        $pool = $this->expect(201, $this->lea, 'POST', '/api/contributions', ['ideaId' => $suggestion['id']]);
        $this->expect(200, $this->lea, 'PUT', "/api/contributions/{$pool['id']}/pledge", ['amount' => '30']);

        // The API guest view, signed out, and even with the owner's own token.
        foreach ([null, $this->camille] as $bearer) {
            $response = static::createClient()->request('GET', "/api/share-links/{$this->token}", null !== $bearer ? ['auth_bearer' => $bearer] : []);
            self::assertSame(200, $response->getStatusCode());
            $view = $response->toArray();

            self::assertSame(['Published idea'], array_column($view['member'], 'title'));
            self::assertSame(1, $view['totalItems']);
            self::assertSame(['birthday'], $view['occasions'], 'the suggestion\'s occasion doesn\'t leak through the chips');
            self::assertSame(['displayName', 'avatarUrl'], array_keys($view['owner']));
            self::assertSame('guest', $view['member'][0]['view']);
            $this->assertNoForbiddenKey($view);
            $raw = $response->getContent();
            foreach (['Secret suggestion', 'Hugo', 'Secret comment', 'Private draft', 'Hugo draft', 'Archived idea', 'Chaussures', 'slv-'] as $secret) {
                self::assertStringNotContainsString($secret, $raw);
            }
        }

        // Filters can't widen it.
        foreach (['?status=archived', '?visibility=private', '?kind=suggestion', '?occasion=christmas', '?q=Secret'] as $query) {
            $titles = array_column(static::createClient()->request('GET', "/api/share-links/{$this->token}{$query}")->toArray(false)['member'] ?? [], 'title');
            self::assertNotContains('Secret suggestion', $titles, $query);
            self::assertNotContains('Private draft', $titles, $query);
            self::assertNotContains('Archived idea', $titles, $query);
        }

        // The web page, same rules.
        $html = static::createClient()->request('GET', "/u/{$this->token}")->getContent();
        self::assertStringContainsString('Published idea', $html);
        foreach (['Secret suggestion', 'Hugo', 'Secret comment', 'Private draft', 'Hugo draft', 'Archived idea', 'Chaussures', 'Noël', 'Christmas'] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
    }

    public function testImagesOutsideTheGuestViewCannotBeFetchedEvenWithASignature(): void
    {
        $camilleId = $this->userId($this->camille);
        $suggestion = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Secret suggestion', 'ownerId' => $camilleId]);
        $draft = $this->expect(201, $this->camille, 'POST', '/api/ideas', ['title' => 'Private draft', 'visibility' => 'private']);

        /** @var \App\Service\GuestMediaUrls $media */
        $media = self::getContainer()->get(\App\Service\GuestMediaUrls::class);
        $link = self::getContainer()->get(\App\Repository\ShareLinkRepository::class)->findByToken($this->token);
        self::assertNotNull($link);

        foreach ([$suggestion['id'], $draft['id']] as $ideaId) {
            foreach (['image', 'thumb'] as $kind) {
                self::assertSame(404, static::createClient()->request('GET', $media->path($link, $kind, $ideaId))->getStatusCode());
            }
        }
        self::assertSame(404, static::createClient()->request('GET', $media->path($link, 'avatar', $this->userId($this->hugo)))->getStatusCode(), 'only the owner\'s avatar');
    }

    public function testEveryRefusalLooksTheSame(): void
    {
        $leaId = $this->userId($this->lea);
        $friendships = $this->expect(200, $this->camille, 'GET', '/api/friendships');
        $withLea = array_values(array_filter($friendships, static fn (array $f) => $leaId === $f['user']['id']))[0];
        $this->expect(200, $this->camille, 'DELETE', "/api/friendships/{$withLea['id']}");

        $unknown = 'AAAAAAAAAAAAAAAAAAAAAA';
        $answers = [
            'unknown token' => $this->answer($this->hugo, $unknown),
            'malformed token' => $this->answer($this->hugo, 'nope'),
            'removed by the owner' => $this->answer($this->lea, $this->token),
        ];
        $old = $this->token;
        $this->expect(200, $this->camille, 'POST', '/api/share-link/regenerate');
        $answers['regenerated'] = $this->answer($this->hugo, $old);

        foreach ($answers as $case => $answer) {
            self::assertSame($answers['unknown token'], $answer, $case);
        }
        self::assertSame(404, $answers['unknown token']['status']);
        self::assertSame('share_link.invalid', $answers['unknown token']['body']['code']);

        // The web page too: invalid, regenerated and disabled links render the same page.
        $page = static fn (string $t) => static::createClient()->request('GET', "/u/{$t}");
        $reference = $page($unknown);
        $this->expect(204, $this->camille, 'DELETE', '/api/share-link');
        foreach ([$old, 'nope'] as $token) {
            $response = $page($token);
            self::assertSame(404, $response->getStatusCode());
            self::assertSame(
                preg_replace('/nonce="[^"]+"/', '', $reference->getContent(false)),
                preg_replace('/nonce="[^"]+"/', '', $response->getContent(false)),
            );
        }
    }

    public function testJoiningNeverRevealsAFriendsDeclineOrTheOwnersFriends(): void
    {
        $stranger = $this->registerVerifyAndLogin('slv-stranger@example.com');
        $invitation = $this->expect(200, $stranger, 'GET', "/api/share-links/{$this->token}/invitation");
        self::assertSame(['relation', 'owner'], array_keys($invitation));
        self::assertSame(['id', 'displayName', 'avatarUrl', 'isManaged', 'managedBy'], array_keys($invitation['owner']));
        $this->assertNoForbiddenKey($invitation);
    }

    /**
     * @return array{status: int, body: array<mixed>}
     */
    private function answer(string $bearer, string $token): array
    {
        $answers = [];
        foreach ([['GET', "/api/share-links/{$token}", null], ['GET', "/api/share-links/{$token}/invitation", $bearer], ['POST', "/api/share-links/{$token}/join", $bearer]] as [$method, $path, $auth]) {
            $response = static::createClient()->request($method, $path, null !== $auth ? ['auth_bearer' => $auth] : []);
            $answers[] = ['status' => $response->getStatusCode(), 'body' => $response->toArray(false)];
        }
        // A removed friend still sees the public guest view; the join endpoints are what refuse them.
        return $answers[2] === $answers[1] ? $answers[1] : ['status' => 0, 'body' => ['mismatch' => $answers]];
    }

    /**
     * @param array<mixed> $data
     */
    private function assertNoForbiddenKey(array $data, string $path = ''): void
    {
        foreach ($data as $key => $value) {
            if (\is_string($key)) {
                self::assertNotContains($key, self::FORBIDDEN_KEYS, "forbidden key at {$path}.{$key}");
            }
            if (\is_array($value)) {
                $this->assertNoForbiddenKey($value, "{$path}.{$key}");
            }
        }
    }
}
