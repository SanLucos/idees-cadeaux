<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Entity\DeviceToken;
use App\Entity\Enum\UserType;
use App\Entity\User;
use App\Notification\Push\FcmPushSender;
use App\Notification\Push\RoutingPushSender;
use App\Notification\Push\WebPushSender;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Spec §11 décision 70: push to the web app installed from the browser.
 */
final class WebPushSenderTest extends TestCase
{
    public function testDoesNothingUntilConfigured(): void
    {
        $http = new MockHttpClient(static fn () => self::fail('no HTTP call expected'));
        $sender = new WebPushSender($http, new NullLogger(), '', '', 'https://example.org');

        self::assertFalse($sender->isConfigured());
        self::assertSame([], $sender->send([$this->subscription('https://push.example/live')], 'Title', 'Body', []));
    }

    public function testSendsAnEncryptedSignedPushAndReportsExpiredSubscriptions(): void
    {
        $vapid = VAPID::createVapidKeys();
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];

            return str_ends_with($url, '/gone') ? new MockResponse('', ['http_code' => 410]) : new MockResponse('', ['http_code' => 201]);
        });
        $sender = new WebPushSender($http, new NullLogger(), $vapid['publicKey'], $vapid['privateKey'], 'https://ideescadeaux.example');

        $live = $this->subscription('https://push.example/live');
        $gone = $this->subscription('https://push.example/gone');
        $invalid = $sender->send([$live, $gone], 'Nouvel ami', 'Alice a accepté', ['type' => 'friend_request_accepted']);

        self::assertSame([$gone], $invalid, 'spec §5.11: a subscription the browser dropped (410) is cleaned');
        self::assertCount(2, $requests);
        [$method, $url, $options] = $requests[0];
        self::assertSame(['POST', 'https://push.example/live'], [$method, $url]);
        $headers = implode("\n", $options['headers']);
        self::assertStringContainsString('Content-Encoding: aes128gcm', $headers);
        self::assertStringContainsString('vapid t=', $headers, 'signed with our VAPID key (RFC 8292)');
        self::assertStringContainsString('k='.$vapid['publicKey'], $headers);
        self::assertStringContainsString('TTL: 172800', $headers);
        self::assertStringNotContainsString('Alice', (string) $options['body'], 'the payload is encrypted');
    }

    public function testRoutesPhonesToFcmAndBrowsersToWebPush(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse('', ['http_code' => 201]);
        });
        $urls = [];
        $vapid = VAPID::createVapidKeys();
        $router = new RoutingPushSender(
            new FcmPushSender($http, new ArrayAdapter(), new NullLogger(), '', ''),
            new WebPushSender($http, new NullLogger(), $vapid['publicKey'], $vapid['privateKey'], 'mailto:contact@example.org'),
        );

        $phone = new DeviceToken(new User(UserType::Regular, 'U'), 'android', 'fcm-token');
        self::assertSame([], $router->send([$phone, $this->subscription('https://push.example/web')], 'T', 'B', []));
        self::assertSame(['https://push.example/web'], $urls, 'FCM unconfigured: only the browser is reached');
    }

    private function subscription(string $endpoint): DeviceToken
    {
        // A real P-256 point: the payload is encrypted for it.
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $ec = openssl_pkey_get_details($key)['ec'];
        $point = "\x04".str_pad($ec['x'], 32, "\0", \STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", \STR_PAD_LEFT);
        $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return new DeviceToken(new User(UserType::Regular, 'U'), 'web', $endpoint, ['p256dh' => $b64($point), 'auth' => $b64(random_bytes(16))]);
    }
}
