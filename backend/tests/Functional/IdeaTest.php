<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Lot 3 behaviour (spec §5.4): CRUD, validation codes, idempotent
 * create, publish/unpublish, archive/unarchive, filters and sorting,
 * occasions, image upload. Who-sees-what lives in
 * tests/Visibility/IdeaVisibilityTest.
 */
final class IdeaTest extends AuthTestCase
{
    public function testOccasionsAreTheFourteenPredefinedOnesInOrder(): void
    {
        $token = $this->registerVerifyAndLogin('occ@example.com');
        $occasions = static::createClient()->request('GET', '/api/occasions', ['auth_bearer' => $token])->toArray();

        self::assertCount(14, $occasions);
        self::assertSame('birthday', $occasions[0]['code']);
        self::assertSame('thanks', $occasions[13]['code']);
        self::assertSame('occasions.christmas', $occasions[1]['translationKey']);
    }

    public function testIdeasRequireAuthentication(): void
    {
        static::createClient()->request('GET', '/api/users/me/ideas');
        self::assertResponseStatusCodeSame(401);
    }

    public function testCreateWithDefaultsAndNormalisedPrice(): void
    {
        $token = $this->registerVerifyAndLogin('create@example.com');

        $idea = $this->post($token, '/api/ideas', [
            'title' => '  Carnet de voyage  ',
            'url' => 'https://boutique.example/carnet',
            'priceAmount' => '38,5',
            'note' => 'Cuir marron',
            'occasion' => 'birthday',
        ]);
        self::assertResponseStatusCodeSame(201);

        self::assertSame('Carnet de voyage', $idea['title']);
        self::assertSame('38.50', $idea['priceAmount']);
        self::assertSame('EUR', $idea['priceCurrency'], 'EUR by default (spec §5.4)');
        self::assertSame('published', $idea['visibility'], 'published by default (spec §5.4)');
        self::assertNotNull($idea['publishedAt']);
        self::assertSame('birthday', $idea['occasion']);
        self::assertSame('active', $idea['status']);
        self::assertSame(1, static::createClient()->request('GET', '/api/users/me/ideas', ['auth_bearer' => $token])->toArray()['counts']['published']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'no title' => [['title' => '   '], 'validation.idea_title_invalid'];
        yield 'title too long' => [['title' => str_repeat('a', 121)], 'validation.idea_title_invalid'];
        yield 'ftp url' => [['title' => 'x', 'url' => 'ftp://example.com/file'], 'validation.idea_url_invalid'];
        yield 'not a url' => [['title' => 'x', 'url' => 'boutique'], 'validation.idea_url_invalid'];
        yield 'negative price' => [['title' => 'x', 'priceAmount' => '-3'], 'validation.idea_price_invalid'];
        yield 'three decimals' => [['title' => 'x', 'priceAmount' => '1.234'], 'validation.idea_price_invalid'];
        yield 'bad currency' => [['title' => 'x', 'priceCurrency' => 'euro'], 'validation.idea_currency_invalid'];
        yield 'note too long' => [['title' => 'x', 'note' => str_repeat('a', 2001)], 'validation.idea_note_invalid'];
        yield 'unknown occasion' => [['title' => 'x', 'occasion' => 'other'], 'validation.idea_occasion_invalid'];
        yield 'bad visibility' => [['title' => 'x', 'visibility' => 'friends'], 'validation.idea_visibility_invalid'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBodies')]
    public function testCreateValidation(array $body, string $code): void
    {
        $token = $this->registerVerifyAndLogin('invalid@example.com');

        $response = static::createClient()->request('POST', '/api/ideas', ['auth_bearer' => $token, 'json' => $body]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($code, $response->toArray(false)['code']);
    }

    public function testCreateIsIdempotentOnTheClientGeneratedId(): void
    {
        $token = $this->registerVerifyAndLogin('idem@example.com');
        $id = '0190a1b2-0000-7000-8000-00000000abcd';

        $this->post($token, '/api/ideas', ['id' => $id, 'title' => 'Once']);
        self::assertResponseStatusCodeSame(201);
        $replay = $this->post($token, '/api/ideas', ['id' => $id, 'title' => 'Once']);
        self::assertResponseStatusCodeSame(200);

        self::assertSame($id, $replay['id']);
        self::assertSame(1, static::createClient()->request('GET', '/api/users/me/ideas', ['auth_bearer' => $token])->toArray()['totalItems']);
    }

    public function testAuthorEditsAndDeletesButAFriendCannot(): void
    {
        $owner = $this->registerVerifyAndLogin('edit-owner@example.com');
        $friend = $this->registerVerifyAndLogin('edit-friend@example.com');
        $this->befriend($owner, 'edit-owner@example.com', $friend, 'edit-friend@example.com');

        $idea = $this->post($owner, '/api/ideas', ['title' => 'Before', 'priceAmount' => 10]);

        $patched = static::createClient()->request('PATCH', "/api/ideas/{$idea['id']}", [
            'auth_bearer' => $owner,
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['title' => 'After', 'priceCurrency' => 'usd', 'occasion' => null],
        ])->toArray();
        self::assertSame('After', $patched['title']);
        self::assertSame('10.00', $patched['priceAmount'], 'untouched fields stay');
        self::assertSame('USD', $patched['priceCurrency']);

        // A friend can read it (it's published) but not change it: 403, since it's no secret.
        static::createClient()->request('PATCH', "/api/ideas/{$idea['id']}", [
            'auth_bearer' => $friend,
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['title' => 'Hijack'],
        ]);
        self::assertResponseStatusCodeSame(403);
        static::createClient()->request('DELETE', "/api/ideas/{$idea['id']}", ['auth_bearer' => $friend]);
        self::assertResponseStatusCodeSame(403);

        static::createClient()->request('DELETE', "/api/ideas/{$idea['id']}", ['auth_bearer' => $owner]);
        self::assertResponseStatusCodeSame(204);
        static::createClient()->request('GET', "/api/ideas/{$idea['id']}", ['auth_bearer' => $owner]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPublishAndUnpublish(): void
    {
        $token = $this->registerVerifyAndLogin('pub@example.com');
        $idea = $this->post($token, '/api/ideas', ['title' => 'Draft', 'visibility' => 'private']);
        self::assertSame('private', $idea['visibility']);
        self::assertNull($idea['publishedAt']);

        $published = $this->post($token, "/api/ideas/{$idea['id']}/publish");
        self::assertSame('published', $published['visibility']);
        self::assertNotNull($published['publishedAt']);

        $private = $this->post($token, "/api/ideas/{$idea['id']}/unpublish");
        self::assertSame('private', $private['visibility']);

        $counts = static::createClient()->request('GET', '/api/users/me/ideas', ['auth_bearer' => $token])->toArray()['counts'];
        self::assertSame(['published' => 0, 'drafts' => 1, 'archived' => 0], $counts);
    }

    public function testArchiveRules(): void
    {
        $owner = $this->registerVerifyAndLogin('arch-owner@example.com');
        $friend = $this->registerVerifyAndLogin('arch-friend@example.com');
        $this->befriend($owner, 'arch-owner@example.com', $friend, 'arch-friend@example.com');
        $ownerId = $this->userId($owner);

        $personal = $this->post($owner, '/api/ideas', ['title' => 'Mine']);
        $suggestion = $this->post($friend, '/api/ideas', ['title' => 'For you', 'ownerId' => $ownerId]);

        // Wrong kind for the idea type, or not the right person: 403 (both visible to the actor).
        $this->post($owner, "/api/ideas/{$personal['id']}/archive", ['kind' => 'gifted']);
        self::assertResponseStatusCodeSame(403);
        $this->post($friend, "/api/ideas/{$personal['id']}/archive", ['kind' => 'received']);
        self::assertResponseStatusCodeSame(403);

        $archived = $this->post($owner, "/api/ideas/{$personal['id']}/archive", ['kind' => 'received']);
        self::assertSame('archived', $archived['status']);
        self::assertSame('received', $archived['archiveKind']);
        $this->post($owner, "/api/ideas/{$personal['id']}/archive", ['kind' => 'received']);
        self::assertResponseStatusCodeSame(409);

        $gifted = $this->post($friend, "/api/ideas/{$suggestion['id']}/archive", ['kind' => 'gifted']);
        self::assertTrue($gifted['canUnarchive']);

        // Only whoever archived can restore.
        $this->post($friend, "/api/ideas/{$personal['id']}/unarchive");
        self::assertResponseStatusCodeSame(403);
        $restored = $this->post($owner, "/api/ideas/{$personal['id']}/unarchive");
        self::assertSame('active', $restored['status']);
        self::assertNull($restored['archiveKind']);
    }

    public function testFiltersSortingSearchAndPagination(): void
    {
        $token = $this->registerVerifyAndLogin('filters@example.com');
        $this->post($token, '/api/ideas', ['title' => 'Vinyle', 'priceAmount' => '149', 'occasion' => 'birthday']);
        $this->post($token, '/api/ideas', ['title' => 'Guide champignons', 'priceAmount' => '29.90', 'occasion' => 'christmas']);
        $this->post($token, '/api/ideas', ['title' => 'Sans prix', 'note' => 'un vinyle rare']);
        $this->post($token, '/api/ideas', ['title' => 'Couteau', 'priceAmount' => '64', 'occasion' => 'christmas']);

        $titles = fn (string $query): array => array_column(
            static::createClient()->request('GET', "/api/users/me/ideas{$query}", ['auth_bearer' => $token])->toArray()['member'],
            'title',
        );

        self::assertSame(['Couteau', 'Sans prix', 'Guide champignons', 'Vinyle'], $titles(''), 'most recent first by default');
        self::assertSame(['Guide champignons', 'Couteau', 'Vinyle', 'Sans prix'], $titles('?sort=price_asc'), 'no price comes last');
        self::assertSame(['Vinyle', 'Couteau', 'Guide champignons', 'Sans prix'], $titles('?sort=price_desc'));
        self::assertSame(['Couteau', 'Guide champignons'], $titles('?occasion=christmas'));
        self::assertSame(['Sans prix', 'Vinyle'], $titles('?q=VINYLE'), 'search is case-insensitive, title and note');
        self::assertSame(['Guide champignons', 'Vinyle'], $titles('?itemsPerPage=2&page=2'));

        static::createClient()->request('GET', '/api/users/me/ideas?sort=random', ['auth_bearer' => $token]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testImageUploadStoresALargeVersionAndAThumbnail(): void
    {
        $token = $this->registerVerifyAndLogin('image@example.com');
        $idea = $this->post($token, '/api/ideas', ['title' => 'With image']);

        $path = tempnam(sys_get_temp_dir(), 'img').'.png';
        $image = imagecreatetruecolor(1600, 800);
        imagepng($image, $path);

        $response = static::createClient()->request('POST', "/api/ideas/{$idea['id']}/image", [
            'auth_bearer' => $token,
            'headers' => ['Content-Type' => 'multipart/form-data'],
            'extra' => ['files' => ['image' => new UploadedFile($path, 'photo.png', 'image/png', null, true)]],
        ])->toArray();

        self::assertStringEndsWith('.jpg', $response['imageUrl']);
        self::assertStringEndsWith('-thumb.jpg', $response['thumbnailUrl']);

        $cleared = static::createClient()->request('DELETE', "/api/ideas/{$idea['id']}/image", ['auth_bearer' => $token])->toArray();
        self::assertNull($cleared['imageUrl']);
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<mixed>
     */
    private function post(string $token, string $path, ?array $json = null): array
    {
        $options = ['auth_bearer' => $token];
        if (null !== $json) {
            $options['json'] = $json;
        }

        return static::createClient()->request('POST', $path, $options)->toArray(false);
    }
}
