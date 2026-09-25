<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\FriendshipOrigin;
use App\Entity\Enum\UserType;
use App\Entity\Friendship;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Spec §5.16 "Partage du profil par lien": managing the link, joining
 * through it and its special cases, the web page and its images. What
 * the guest view must never show lives in tests/Visibility.
 */
final class ShareLinkTest extends AuthTestCase
{
    use ApiRequestTrait;
    use NotificationTestTrait;

    private string $camille;
    private string $lucas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->camille = $this->registerVerifyAndLogin('sl-camille@example.com');
        $this->lucas = $this->registerVerifyAndLogin('sl-lucas@example.com');
        $this->expect(200, $this->camille, 'PATCH', '/api/users/me', ['displayName' => 'Camille']);
        $this->expect(200, $this->lucas, 'PATCH', '/api/users/me', ['displayName' => 'Lucas']);
    }

    public function testTheOwnerCreatesRegeneratesAndDisablesTheirLink(): void
    {
        self::assertNull($this->expect(200, $this->camille, 'GET', '/api/share-link')['link'], 'no link until one is created');

        $this->expect(422, $this->camille, 'POST', '/api/share-link', []);
        self::assertSame('share_link.confirmation_required', $this->body['code']);

        $link = $this->expect(201, $this->camille, 'POST', '/api/share-link', ['confirmed' => true])['link'];
        self::assertMatchesRegularExpression('#^[A-Za-z0-9_-]{22}$#', $link['token'], '128 random bits');
        self::assertStringEndsWith('/u/'.$link['token'], $link['url']);
        self::assertSame('active', $link['status']);
        self::assertSame(0, $link['joinCount']);

        self::assertSame($link['token'], $this->expect(200, $this->camille, 'POST', '/api/share-link', ['confirmed' => true])['link']['token'], 'one link per owner');
        $this->expectGuestView(200, $link['token']);

        $regenerated = $this->expect(200, $this->camille, 'POST', '/api/share-link/regenerate')['link'];
        self::assertNotSame($link['token'], $regenerated['token']);
        $this->expectGuestView(404, $link['token']);
        $this->expectGuestView(200, $regenerated['token']);

        $this->expect(204, $this->camille, 'DELETE', '/api/share-link');
        $this->expectGuestView(404, $regenerated['token']);
        self::assertNull($this->expect(200, $this->camille, 'GET', '/api/share-link')['link']);
        $this->expect(404, $this->camille, 'POST', '/api/share-link/regenerate');
    }

    public function testJoiningCreatesAnImmediateMutualFriendshipAndNotifiesTheOwner(): void
    {
        $token = $this->createLink($this->camille);

        $invitation = $this->expect(200, $this->lucas, 'GET', "/api/share-links/{$token}/invitation");
        self::assertSame('none', $invitation['relation']);
        self::assertSame('Camille', $invitation['owner']['displayName']);
        self::assertNull($invitation['owner']['managedBy']);
        self::assertSame([], $this->typesOf($this->camille), 'opening the link alone does nothing');

        $joined = $this->expect(200, $this->lucas, 'POST', "/api/share-links/{$token}/join");
        self::assertSame('friend', $joined['relation']);
        self::assertNotNull($joined['friendshipId']);

        $camilleId = $this->userId($this->camille);
        $this->expect(200, $this->lucas, 'GET', "/api/users/{$camilleId}/ideas");
        $this->expect(200, $this->camille, 'GET', '/api/users/'.$this->userId($this->lucas).'/ideas');
        self::assertSame(['friend_joined_via_link'], $this->typesOf($this->camille));
        self::assertSame(1, $this->expect(200, $this->camille, 'GET', '/api/share-link')['link']['joinCount']);

        // Joining again, or opening one's own link: redirects, nothing created.
        self::assertSame('friend', $this->expect(200, $this->lucas, 'POST', "/api/share-links/{$token}/join")['relation']);
        self::assertSame('self', $this->expect(200, $this->camille, 'GET', "/api/share-links/{$token}/invitation")['relation']);
        self::assertSame('self', $this->expect(200, $this->camille, 'POST', "/api/share-links/{$token}/join")['relation']);
        self::assertCount(1, $this->notificationsOf($this->camille));
    }

    public function testAPendingRequestIsResolvedAndAnOldDeclineDoesNotMatter(): void
    {
        $token = $this->createLink($this->camille);

        // Camille asked Lucas; Lucas joins through her link instead of answering.
        $this->expect(200, $this->camille, 'POST', '/api/friendships', ['email' => 'sl-lucas@example.com']);
        $this->expect(200, $this->lucas, 'POST', "/api/share-links/{$token}/join");
        self::assertCount(1, $this->expect(200, $this->lucas, 'GET', '/api/friendships'));
        self::assertSame([], $this->expect(200, $this->lucas, 'GET', '/api/friendships/incoming'));

        // Léa once declined Camille's request: the link still prevails.
        $lea = $this->registerVerifyAndLogin('sl-lea@example.com');
        $this->expect(200, $this->camille, 'POST', '/api/friendships', ['email' => 'sl-lea@example.com']);
        $request = $this->expect(200, $lea, 'GET', '/api/friendships/incoming')[0];
        $this->expect(200, $lea, 'POST', "/api/friendships/{$request['id']}/decline");
        self::assertSame('friend', $this->expect(200, $lea, 'POST', "/api/share-links/{$token}/join")['relation']);
    }

    public function testSomeoneRemovedByTheOwnerCannotComeBackThroughTheLink(): void
    {
        $token = $this->createLink($this->camille);
        $friendshipId = $this->expect(200, $this->lucas, 'POST', "/api/share-links/{$token}/join")['friendshipId'];

        $this->expect(200, $this->camille, 'DELETE', "/api/friendships/{$friendshipId}");
        $this->expect(404, $this->lucas, 'GET', "/api/share-links/{$token}/invitation");
        self::assertSame('share_link.invalid', $this->body['code']);
        $this->expect(404, $this->lucas, 'POST', "/api/share-links/{$token}/join");
        self::assertSame('share_link.invalid', $this->body['code']);

        // Still true after a regeneration: only a classic request works.
        $token = $this->expect(200, $this->camille, 'POST', '/api/share-link/regenerate')['link']['token'];
        $this->expect(404, $this->lucas, 'POST', "/api/share-links/{$token}/join");
    }

    public function testSomeoneWhoLeftByThemselvesCanRejoin(): void
    {
        $token = $this->createLink($this->camille);
        $friendshipId = $this->expect(200, $this->lucas, 'POST', "/api/share-links/{$token}/join")['friendshipId'];

        $this->expect(200, $this->lucas, 'DELETE', "/api/friendships/{$friendshipId}");
        self::assertSame('friend', $this->expect(200, $this->lucas, 'POST', "/api/share-links/{$token}/join")['relation']);
    }

    public function testBeyondFiftyJoinsIn24HoursTheLinkIsSuspendedAndTheOwnerNotified(): void
    {
        $token = $this->createLink($this->camille);

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $camille = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'sl-camille@example.com']);
        for ($i = 0; $i < 50; ++$i) {
            $joiner = new User(UserType::Regular, "Joiner {$i}");
            $joiner->setEmail("sl-joiner-{$i}@example.com");
            $em->persist($joiner);
            $em->persist(Friendship::createAccepted($joiner, $camille, FriendshipOrigin::Link));
        }
        $em->flush();

        $this->expect(404, $this->lucas, 'POST', "/api/share-links/{$token}/join");
        self::assertSame('share_link.invalid', $this->body['code']);
        self::assertSame('suspended', $this->expect(200, $this->camille, 'GET', '/api/share-link')['link']['status']);
        self::assertSame(['share_link_suspended'], $this->typesOf($this->camille));
        $this->expectGuestView(404, $token);

        // Regenerating brings a working link back (the cap still counts the past 24 h).
        $fresh = $this->expect(200, $this->camille, 'POST', '/api/share-link/regenerate')['link'];
        self::assertSame('active', $fresh['status']);
        $this->expectGuestView(200, $fresh['token']);
    }

    public function testAManagerSharesTheirChildsProfileAndReceivesItsNotifications(): void
    {
        $jules = $this->expect(201, $this->camille, 'POST', '/api/managed-profiles', ['displayName' => 'Jules', 'parentalConsent' => true])['id'];
        $this->expect(201, $this->camille, 'POST', '/api/ideas', ['title' => 'Lego'], $jules);

        $this->expect(422, $this->camille, 'POST', '/api/share-link', [], $jules);
        $token = $this->expect(201, $this->camille, 'POST', '/api/share-link', ['confirmed' => true], $jules)['link']['token'];
        self::assertNull($this->expect(200, $this->camille, 'GET', '/api/share-link')['link'], 'the child\'s link is not the manager\'s');

        $guest = $this->expectGuestView(200, $token);
        self::assertSame('Jules', $guest['owner']['displayName']);
        self::assertSame(['Lego'], array_column($guest['member'], 'title'));

        $invitation = $this->expect(200, $this->lucas, 'GET', "/api/share-links/{$token}/invitation");
        self::assertTrue($invitation['owner']['isManaged']);
        self::assertSame('Camille', $invitation['owner']['managedBy']['displayName']);
        self::assertSame('manager', $this->expect(200, $this->camille, 'GET', "/api/share-links/{$token}/invitation")['relation']);

        $this->expect(200, $this->lucas, 'POST', "/api/share-links/{$token}/join");
        $notification = $this->notificationsOf($this->camille)[0];
        self::assertSame('friend_joined_via_link', $notification['type']);
        self::assertSame('Jules', $notification['payload']['subject']['displayName'], 'marked « Pour Jules »');

        // Only adults join: never on a child's behalf.
        $lucasToken = $this->createLink($this->lucas);
        $this->expect(403, $this->camille, 'POST', "/api/share-links/{$lucasToken}/join", null, $jules);
        self::assertSame('acting_as.not_allowed', $this->body['code']);
    }

    public function testTheWebPageIsReadOnlyUncachedAndNotIndexed(): void
    {
        $this->expect(201, $this->camille, 'POST', '/api/ideas', ['title' => 'Sac à dos', 'priceAmount' => '120', 'occasion' => 'birthday']);
        $this->expect(201, $this->camille, 'POST', '/api/ideas', ['title' => 'Plaid', 'priceAmount' => '75', 'occasion' => 'christmas']);
        $token = $this->createLink($this->camille);

        $response = static::createClient()->request('GET', "/u/{$token}", ['headers' => ['Accept-Language' => 'fr-FR,fr;q=0.9']]);
        self::assertSame(200, $response->getStatusCode());
        $html = $response->getContent();
        $headers = $response->getHeaders();
        self::assertStringContainsString('no-store', $headers['cache-control'][0]);
        self::assertStringContainsString('noindex', $headers['x-robots-tag'][0]);
        self::assertSame('no-referrer', $headers['referrer-policy'][0]);
        self::assertStringContainsString("default-src 'none'", $headers['content-security-policy'][0]);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('Les idées cadeaux de Camille', $html);
        self::assertStringContainsString('Sac à dos', $html);
        self::assertStringContainsString('Plaid', $html);

        // Open Graph: the pseudo, a generic text — never an idea.
        preg_match_all('#<meta property="og:[a-z_]+" content="([^"]*)">#', $html, $og);
        self::assertNotEmpty($og[1]);
        foreach ($og[1] as $content) {
            self::assertStringNotContainsString('Sac', $content);
            self::assertStringNotContainsString('Plaid', $content);
        }

        $filtered = static::createClient()->request('GET', "/u/{$token}?occasion=christmas")->getContent();
        self::assertStringContainsString('Plaid', $filtered);
        self::assertStringNotContainsString('Sac à dos', $filtered);

        $invalid = static::createClient()->request('GET', '/u/AAAAAAAAAAAAAAAAAAAAAA');
        self::assertSame(404, $invalid->getStatusCode());
        self::assertStringContainsString('no-store', $invalid->getHeaders(false)['cache-control'][0]);
    }

    public function testGuestImagesAreSignedShortLivedAndFollowTheLink(): void
    {
        $idea = $this->expect(201, $this->camille, 'POST', '/api/ideas', ['title' => 'With image']);
        $path = tempnam(sys_get_temp_dir(), 'img').'.png';
        imagepng(imagecreatetruecolor(400, 300), $path);
        static::createClient()->request('POST', "/api/ideas/{$idea['id']}/image", [
            'auth_bearer' => $this->camille,
            'headers' => ['Content-Type' => 'multipart/form-data'],
            'extra' => ['files' => ['image' => new UploadedFile($path, 'photo.png', 'image/png', null, true)]],
        ]);
        $token = $this->createLink($this->camille);

        $imageUrl = $this->expectGuestView(200, $token)['member'][0]['imageUrl'];
        self::assertMatchesRegularExpression('#/u/'.preg_quote($token, '#').'/media/image/[0-9a-f-]{36}\?e=\d+&s=[0-9a-f]{64}$#', $imageUrl);
        $mediaPath = (string) strstr($imageUrl, '/u/');

        $response = static::createClient()->request('GET', $mediaPath);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/jpeg', $response->getHeaders()['content-type'][0]);

        $tampered = preg_replace('#s=[0-9a-f]#', 's=x', $mediaPath);
        self::assertSame(404, static::createClient()->request('GET', (string) $tampered)->getStatusCode());
        $expired = preg_replace('#e=\d+#', 'e=1000', $mediaPath);
        self::assertSame(404, static::createClient()->request('GET', (string) $expired)->getStatusCode());

        $this->expect(204, $this->camille, 'DELETE', '/api/share-link');
        self::assertSame(404, static::createClient()->request('GET', $mediaPath)->getStatusCode(), 'disabling the link cuts its images');
    }

    public function testAppLinkFilesAreOnlyServedOnceConfigured(): void
    {
        self::assertSame(404, static::createClient()->request('GET', '/.well-known/assetlinks.json')->getStatusCode());
        self::assertSame(404, static::createClient()->request('GET', '/.well-known/apple-app-site-association')->getStatusCode());
    }

    private function createLink(string $token): string
    {
        return $this->expect(201, $token, 'POST', '/api/share-link', ['confirmed' => true])['link']['token'];
    }

    /**
     * @return array<mixed>
     */
    private function expectGuestView(int $status, string $token): array
    {
        $response = static::createClient()->request('GET', "/api/share-links/{$token}");
        self::assertSame($status, $response->getStatusCode());

        return $response->toArray(false);
    }
}
