<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\VerificationCodePurpose;
use App\Message\SendAccountEmail;
use App\Scheduler\DeleteScheduledProfilesTask;
use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Spec §5.13 "Suppression du compte et des données": re-authentication,
 * 14 days of grace (suspended account, cancel by signing in or from the
 * email), then erasure for good by the daily task.
 */
final class AccountDeletionTest extends AuthTestCase
{
    use ApiRequestTrait;
    use NotificationTestTrait;

    private string $camille;
    private string $hugo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->camille = $this->registerVerifyAndLogin('ad-camille@example.com');
        $this->hugo = $this->registerVerifyAndLogin('ad-hugo@example.com');
        $this->expect(200, $this->camille, 'PATCH', '/api/users/me', ['displayName' => 'Camille']);
        $this->expect(200, $this->hugo, 'PATCH', '/api/users/me', ['displayName' => 'Hugo']);
        $this->befriend($this->camille, 'ad-camille@example.com', $this->hugo, 'ad-hugo@example.com');
    }

    public function testDeletionNeedsReauthenticationAndSuspendsTheAccount(): void
    {
        $refreshToken = static::createClient()->request('POST', '/api/auth/login', ['json' => ['email' => 'ad-camille@example.com', 'password' => 'correcthorsebattery']])->toArray()['refresh_token'];
        $this->expect(201, $this->camille, 'POST', '/api/device-tokens', ['token' => 'push-camille', 'platform' => 'android']);

        self::assertSame('auth.reauthentication_required', $this->expect(422, $this->camille, 'POST', '/api/account/deletion', [])['code']);
        self::assertSame('auth.reauthentication_failed', $this->expect(422, $this->camille, 'POST', '/api/account/deletion', ['password' => 'wrong-password'])['code']);
        self::assertNull($this->expect(200, $this->camille, 'GET', '/api/users/me')['deletionScheduledAt']);

        $me = $this->expect(200, $this->camille, 'POST', '/api/account/deletion', ['password' => 'correcthorsebattery']);
        self::assertEqualsWithDelta((new \DateTimeImmutable('+14 days'))->getTimestamp(), (new \DateTimeImmutable($me['deletionScheduledAt']))->getTimestamp(), 60);

        // An email with the cancel link.
        $email = $this->accountEmails('deletion_requested')[0];
        self::assertSame('ad-camille@example.com', $email->email);
        self::assertStringContainsString('/account/cancel-deletion/', (string) $email->actionUrl);

        // Sessions and push tokens revoked.
        static::createClient()->request('POST', '/api/auth/refresh', ['json' => ['refresh_token' => $refreshToken]]);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM device_token WHERE token = 'push-camille'"));

        // Only the « Votre compte sera supprimé le … » screen's needs remain.
        $this->expect(200, $this->camille, 'GET', '/api/users/me');
        self::assertSame('account.deletion_scheduled', $this->expect(403, $this->camille, 'GET', '/api/users/me/ideas')['code']);
        self::assertSame($me['deletionScheduledAt'], $this->body['deletionScheduledAt']);
        $this->expect(403, $this->camille, 'POST', '/api/ideas', ['title' => 'Nope']);
        $this->expect(403, $this->camille, 'POST', '/api/sync', []);

        // Signing in still works, to cancel.
        $token = $this->login('ad-camille@example.com');
        self::assertNull($this->expect(200, $token, 'POST', '/api/account/cancel-deletion')['deletionScheduledAt']);
        $this->expect(200, $token, 'GET', '/api/users/me/ideas');
    }

    public function testDuringTheGracePeriodNobodyCanReachTheAccount(): void
    {
        $this->expect(200, $this->camille, 'POST', '/api/account/deletion', ['password' => 'correcthorsebattery']);

        // Its email can't be registered again: sign in to cancel instead.
        static::createClient()->request('POST', '/api/auth/register', ['json' => ['email' => 'ad-camille@example.com', 'password' => 'anotherpassword']]);
        self::assertResponseStatusCodeSame(409);

        // No new friend request (same silent answer), no contact match.
        $lea = $this->registerVerifyAndLogin('ad-lea@example.com');
        $this->expect(200, $lea, 'POST', '/api/friendships', ['email' => 'ad-camille@example.com']);
        $this->expect(200, $lea, 'GET', '/api/friendships/outgoing');
        self::assertSame([], $this->body);
        $this->expect(200, $lea, 'POST', '/api/contacts/match', ['hashedEmails' => [hash('sha256', 'ad-camille@example.com')]]);
        self::assertSame([], $this->body);
    }

    public function testTheEmailLinkCancelsOnlyOnConfirmation(): void
    {
        $this->expect(200, $this->camille, 'POST', '/api/account/deletion', ['password' => 'correcthorsebattery']);
        $url = (string) $this->accountEmails('deletion_requested')[0]->actionUrl;
        $path = (string) parse_url($url, \PHP_URL_PATH);

        self::assertSame(200, static::createClient()->request('GET', $path)->getStatusCode());
        self::assertNotNull($this->expect(200, $this->camille, 'GET', '/api/users/me')['deletionScheduledAt'], 'opening the link changes nothing');

        self::assertSame(200, static::createClient()->request('POST', $path)->getStatusCode());
        self::assertNull($this->expect(200, $this->camille, 'GET', '/api/users/me')['deletionScheduledAt']);
        self::assertSame(404, static::createClient()->request('POST', $path)->getStatusCode(), 'used up');
    }

    public function testAnAccountWithoutPasswordConfirmsWithAnEmailedCode(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE app_user SET password_hash = NULL WHERE email = 'ad-camille@example.com'");

        self::assertSame('auth.reauthentication_failed', $this->expect(422, $this->camille, 'POST', '/api/account/deletion', ['password' => 'correcthorsebattery'])['code']);
        $this->expect(202, $this->camille, 'POST', '/api/account/reauth-code');
        $code = $this->lastCodeFor('ad-camille@example.com', VerificationCodePurpose::Reauthenticate);

        self::assertNotNull($this->expect(200, $this->camille, 'POST', '/api/account/deletion', ['code' => $code])['deletionScheduledAt']);
    }

    public function testAtTheEndOfTheGracePeriodEverythingIsErased(): void
    {
        $lea = $this->registerVerifyAndLogin('ad-lea@example.com');
        $this->befriend($this->camille, 'ad-camille@example.com', $lea, 'ad-lea@example.com');
        $this->befriend($this->hugo, 'ad-hugo@example.com', $lea, 'ad-lea@example.com');
        $camilleId = $this->userId($this->camille);
        $hugoId = $this->userId($this->hugo);

        // Camille's own things, her child's, and an image.
        $mine = $this->expect(201, $this->camille, 'POST', '/api/ideas', ['title' => 'Camille idea']);
        $path = tempnam(sys_get_temp_dir(), 'img').'.png';
        imagepng(imagecreatetruecolor(300, 300), $path);
        $withImage = static::createClient()->request('POST', "/api/ideas/{$mine['id']}/image", [
            'auth_bearer' => $this->camille,
            'headers' => ['Content-Type' => 'multipart/form-data'],
            'extra' => ['files' => ['image' => new UploadedFile($path, 'photo.png', 'image/png', null, true)]],
        ])->toArray();
        $jules = $this->expect(201, $this->camille, 'POST', '/api/managed-profiles', ['displayName' => 'Jules', 'parentalConsent' => true])['id'];
        $this->expect(201, $this->camille, 'POST', '/api/ideas', ['title' => 'Jules idea'], $jules);
        $this->expect(201, $this->hugo, 'POST', '/api/comments', ['ideaId' => $mine['id'], 'body' => 'On Camille\'s idea']);

        // What Camille did on Hugo's list.
        $vinyl = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Vinyl']);
        $bike = $this->expect(201, $this->hugo, 'POST', '/api/ideas', ['title' => 'Bike']);
        $this->expect(201, $this->camille, 'POST', '/api/ideas', ['title' => 'Camille suggestion', 'ownerId' => $hugoId]);
        $this->expect(201, $this->camille, 'POST', '/api/reservations', ['ideaId' => $vinyl['id']]);
        $this->expect(201, $this->camille, 'POST', '/api/comments', ['ideaId' => $vinyl['id'], 'body' => 'Camille comment']);
        $this->expect(200, $this->camille, 'PUT', "/api/ideas/{$vinyl['id']}/reaction");
        $pool = $this->expect(201, $this->camille, 'POST', '/api/contributions', ['ideaId' => $bike['id']]);
        $this->expect(200, $this->camille, 'PUT', "/api/contributions/{$pool['id']}/pledge", ['amount' => '40']);
        $this->expect(200, $lea, 'PUT', "/api/contributions/{$pool['id']}/pledge", ['amount' => '25']);

        $this->expect(200, $this->camille, 'POST', '/api/account/deletion', ['password' => 'correcthorsebattery']);
        $task = self::getContainer()->get(DeleteScheduledProfilesTask::class);
        self::assertSame(0, $task(), 'nothing before the grace period ends');

        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement("UPDATE app_user SET deletion_scheduled_at = NOW() - INTERVAL '1 minute' WHERE id = :id", ['id' => $camilleId]);
        $this->deliver();
        self::assertSame(1, $task());
        self::assertSame('ad-camille@example.com', $this->accountEmails('deleted')[0]->email, 'she is told');

        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM app_user WHERE id IN (:a, :b)', ['a' => $camilleId, 'b' => $jules]), 'the account and its child profile');
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM idea WHERE owner_id IN (:a, :b) OR author_id IN (:a, :b)', ['a' => $camilleId, 'b' => $jules]));
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM comment WHERE body IN ('Camille comment', 'On Camille''s idea')"));

        // Hugo's list: her suggestion, reservation, comment and reaction are gone…
        $list = $this->expect(200, $lea, 'GET', "/api/users/{$hugoId}/ideas");
        $byTitle = array_column($list['member'], null, 'title');
        self::assertArrayNotHasKey('Camille suggestion', $byTitle);
        self::assertNull($byTitle['Vinyl']['reservation']);
        self::assertSame(0, $byTitle['Vinyl']['commentCount']);
        self::assertSame(0, $byTitle['Vinyl']['reactions']['count']);

        // …her contribution is closed, Léa's pledge kept, the total recomputed.
        $contribution = $this->expect(200, $lea, 'GET', "/api/contributions/{$pool['id']}");
        self::assertSame('closed', $contribution['status']);
        self::assertNull($contribution['initiator']);
        self::assertSame('25.00', $contribution['totalAmount']);
        self::assertSame(['25.00'], array_column($contribution['participants'], 'amount'));

        // Her image is gone from the storage.
        $storage = self::getContainer()->get('default.storage');
        \assert($storage instanceof FilesystemOperator);
        $imagePath = substr((string) parse_url($withImage['imageUrl'], \PHP_URL_PATH), (int) strpos((string) parse_url($withImage['imageUrl'], \PHP_URL_PATH), 'ideas/'));
        self::assertFalse($storage->fileExists($imagePath));

        // Nothing in Hugo's notification centre names her any more.
        self::assertStringNotContainsString($camilleId, (string) json_encode($this->notificationsOf($this->hugo)));
    }

    /**
     * @return SendAccountEmail[]
     */
    private function accountEmails(string $kind): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');

        return array_values(array_filter(
            array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()),
            static fn ($message) => $message instanceof SendAccountEmail && $kind === $message->kind,
        ));
    }
}
