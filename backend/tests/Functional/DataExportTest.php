<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Scheduler\PurgeExpiredExportsTask;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Spec §5.13 "Export de mes données": re-authentication, asynchronous
 * ZIP (JSON + images), 48-hour link by email; a manager exports a child
 * profile the same way. What must never be in it: tests/Visibility.
 */
final class DataExportTest extends AuthTestCase
{
    use ApiRequestTrait;
    use DataExportTestTrait;

    private string $camille;

    protected function setUp(): void
    {
        parent::setUp();
        $this->camille = $this->registerVerifyAndLogin('dx-camille@example.com');
        $this->expect(200, $this->camille, 'PATCH', '/api/users/me', ['displayName' => 'Camille', 'birthDay' => 12, 'birthMonth' => 3]);
    }

    public function testMyExportHasMyDataAndImagesBehindA48HourLink(): void
    {
        $hugo = $this->registerVerifyAndLogin('dx-hugo@example.com');
        $this->expect(200, $hugo, 'PATCH', '/api/users/me', ['displayName' => 'Hugo']);
        $this->befriend($this->camille, 'dx-camille@example.com', $hugo, 'dx-hugo@example.com');

        $idea = $this->expect(201, $this->camille, 'POST', '/api/ideas', ['title' => 'Platine vinyle', 'priceAmount' => '149', 'occasion' => 'birthday']);
        $path = tempnam(sys_get_temp_dir(), 'img').'.png';
        imagepng(imagecreatetruecolor(300, 300), $path);
        static::createClient()->request('POST', "/api/ideas/{$idea['id']}/image", [
            'auth_bearer' => $this->camille,
            'headers' => ['Content-Type' => 'multipart/form-data'],
            'extra' => ['files' => ['image' => new UploadedFile($path, 'photo.png', 'image/png', null, true)]],
        ]);
        $this->expect(201, $this->camille, 'POST', '/api/ideas', ['title' => 'Pour Hugo', 'ownerId' => $this->userId($hugo)]);
        $this->expect(201, $this->camille, 'POST', '/api/profile_sizes', ['label' => 'Chaussures', 'value' => '38']);

        self::assertSame('auth.reauthentication_required', $this->expect(422, $this->camille, 'POST', '/api/account/export', [])['code']);
        $pending = $this->expect(202, $this->camille, 'POST', '/api/account/export', ['password' => 'correcthorsebattery']);
        self::assertSame('pending', $pending['status']);

        ['link' => $link, 'files' => $files] = $this->runExport();
        self::assertSame('dx-camille@example.com', $link->email);
        self::assertFalse($link->aboutProfile);

        foreach (['profile.json', 'sizes.json', 'preferences.json', 'friends.json', 'ideas.json', 'comments.json', 'reactions.json', 'reservations.json', 'contributions.json', 'consents.json'] as $name) {
            self::assertArrayHasKey($name, $files);
        }
        $profile = json_decode($files['profile.json'], true);
        self::assertSame('dx-camille@example.com', $profile['email']);
        self::assertSame(12, $profile['birthDay']);
        $ideas = array_column(json_decode($files['ideas.json'], true), null, 'title');
        self::assertNull($ideas['Platine vinyle']['suggested_to']);
        self::assertSame('Hugo', $ideas['Pour Hugo']['suggested_to'], 'suggestions made to others are mine too');
        self::assertArrayHasKey($ideas['Platine vinyle']['image'], $files, 'uploaded images are in the archive');
        self::assertSame('Chaussures', json_decode($files['sizes.json'], true)[0]['label']);
        self::assertSame('Hugo', json_decode($files['friends.json'], true)['friends'][0]['displayName']);

        // The link: wrong token, or past 48 h, gives nothing; then the purge takes it.
        $path = (string) parse_url((string) $link->actionUrl, \PHP_URL_PATH);
        self::assertSame(404, static::createClient()->request('GET', substr($path, 0, -4).'XXXX')->getStatusCode());
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement("UPDATE data_export SET expires_at = NOW() - INTERVAL '1 minute'");
        self::assertSame(404, static::createClient()->request('GET', $path)->getStatusCode());
        self::assertSame(1, self::getContainer()->get(PurgeExpiredExportsTask::class)());
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM data_export'));
    }

    public function testAManagerExportsTheirChildsProfile(): void
    {
        $jules = $this->expect(201, $this->camille, 'POST', '/api/managed-profiles', ['displayName' => 'Jules', 'parentalConsent' => true])['id'];
        $this->expect(201, $this->camille, 'POST', '/api/ideas', ['title' => 'Lego'], $jules);

        $other = $this->registerVerifyAndLogin('dx-other@example.com');
        $this->expect(404, $other, 'POST', "/api/managed-profiles/{$jules}/export", ['password' => 'correcthorsebattery']);
        self::assertSame('acting_as.not_allowed', $this->expect(403, $this->camille, 'POST', '/api/account/export', ['password' => 'correcthorsebattery'], $jules)['code']);

        $this->expect(202, $this->camille, 'POST', "/api/managed-profiles/{$jules}/export", ['password' => 'correcthorsebattery']);
        ['link' => $link, 'files' => $files] = $this->runExport();

        self::assertSame('dx-camille@example.com', $link->email, 'the link goes to the manager');
        self::assertTrue($link->aboutProfile);
        $profile = json_decode($files['profile.json'], true);
        self::assertSame('Jules', $profile['displayName']);
        self::assertSame('Camille', $profile['managedBy']);
        self::assertNotNull(json_decode($files['consents.json'], true)['parentalConsentAt']);
        self::assertSame(['Lego'], array_column(json_decode($files['ideas.json'], true), 'title'));
    }
}
