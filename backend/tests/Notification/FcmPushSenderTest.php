<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Entity\DeviceToken;
use App\Entity\Enum\UserType;
use App\Entity\User;
use App\Notification\Push\FcmPushSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FcmPushSenderTest extends TestCase
{
    public function testDoesNothingUntilConfigured(): void
    {
        $http = new MockHttpClient(static fn () => self::fail('no HTTP call expected'));
        $sender = new FcmPushSender($http, new ArrayAdapter(), new NullLogger(), '', '');

        self::assertFalse($sender->isConfigured());
        self::assertSame([], $sender->send([$this->token('abc')], 'Title', 'Body', []));
    }

    public function testExchangesASignedJwtThenSendsAndReportsInvalidTokens(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $account = json_encode(['client_email' => 'fcm@example.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.example/token']);

        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$url, $options];
            if (str_ends_with($url, '/token')) {
                return new MockResponse(json_encode(['access_token' => 'ya29.test']));
            }
            $message = json_decode($options['body'], true)['message'];

            return 'dead-token' === $message['token']
                ? new MockResponse(json_encode(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]]), ['http_code' => 404])
                : new MockResponse('{"name":"projects/p/messages/1"}');
        });

        $sender = new FcmPushSender($http, new ArrayAdapter(), new NullLogger(), 'demo-project', (string) $account);
        $good = $this->token('live-token');
        $dead = $this->token('dead-token');

        $invalid = $sender->send([$good, $dead], 'Nouvel ami', 'Alice a accepté', ['type' => 'friend_request_accepted']);

        self::assertSame([$dead], $invalid, 'spec §5.11: invalid tokens are reported for cleanup');
        self::assertCount(3, $requests, 'one OAuth exchange, then one send per token');

        [$tokenUrl, $tokenOptions] = $requests[0];
        self::assertSame('https://oauth2.example/token', $tokenUrl);
        parse_str($tokenOptions['body'], $form);
        self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
        $claims = json_decode(base64_decode(strtr(explode('.', $form['assertion'])[1], '-_', '+/')), true);
        self::assertSame('https://www.googleapis.com/auth/firebase.messaging', $claims['scope']);
        self::assertSame('fcm@example.iam.gserviceaccount.com', $claims['iss']);

        [$sendUrl, $sendOptions] = $requests[1];
        self::assertSame('https://fcm.googleapis.com/v1/projects/demo-project/messages:send', $sendUrl);
        self::assertContains('Authorization: Bearer ya29.test', $sendOptions['headers']);
        self::assertSame(['title' => 'Nouvel ami', 'body' => 'Alice a accepté'], json_decode($sendOptions['body'], true)['message']['notification']);
    }

    private function token(string $value): DeviceToken
    {
        return new DeviceToken(new User(UserType::Regular, 'U'), 'android', $value);
    }
}
