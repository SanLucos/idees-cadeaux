<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** POST /api/link-previews (spec §5.5). */
final class LinkPreviewTest extends AuthTestCase
{
    private const string SHOP = 'http://93.184.215.14';

    public function testProposesTitlePriceAndAResizedImage(): void
    {
        $token = $this->registerVerifyAndLogin('lp-ok@example.com');
        $client = static::createClient();
        $this->transport()->setResponseFactory([
            new MockResponse(<<<'HTML'
                <html><head>
                <meta property="og:title" content="Carnet de voyage">
                <meta property="og:image" content="/carnet.png">
                <meta property="product:price:amount" content="38,00">
                </head></html>
                HTML, ['response_headers' => ['content-type' => 'text/html; charset=utf-8']]),
            new MockResponse(self::png(1600, 800), ['response_headers' => ['content-type' => 'image/png']]),
        ]);

        $response = $client->request('POST', '/api/link-previews', ['auth_bearer' => $token, 'json' => ['url' => self::SHOP.'/carnet']]);

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertSame('Carnet de voyage', $body['title']);
        self::assertSame('38.00', $body['priceAmount']);
        self::assertSame('EUR', $body['priceCurrency']);
        self::assertStringStartsWith('data:image/jpeg;base64,', $body['imageDataUrl']);
        $size = getimagesizefromstring(base64_decode(substr($body['imageDataUrl'], \strlen('data:image/jpeg;base64,')), true));
        self::assertSame([1200, 600], [$size[0], $size[1]]);
    }

    public function testNothingFoundIsNotAnError(): void
    {
        $token = $this->registerVerifyAndLogin('lp-empty@example.com');
        $client = static::createClient();
        $this->transport()->setResponseFactory([new MockResponse('<html><body>…</body></html>', ['response_headers' => ['content-type' => 'text/html']])]);

        $response = $client->request('POST', '/api/link-previews', ['auth_bearer' => $token, 'json' => ['url' => self::SHOP.'/']]);

        self::assertResponseIsSuccessful();
        self::assertSame(['url' => self::SHOP.'/', 'title' => null, 'priceAmount' => null, 'priceCurrency' => null, 'imageDataUrl' => null], $response->toArray());
    }

    public function testAnImageThatCantBeFetchedStillPrefillsTheRest(): void
    {
        $token = $this->registerVerifyAndLogin('lp-noimg@example.com');
        $client = static::createClient();
        $this->transport()->setResponseFactory([
            new MockResponse('<html><head><title>Lampe</title><meta property="og:image" content="http://169.254.169.254/latest"></head></html>', ['response_headers' => ['content-type' => 'text/html']]),
            static fn () => self::fail('The internal image must not be fetched.'),
        ]);

        $response = $client->request('POST', '/api/link-previews', ['auth_bearer' => $token, 'json' => ['url' => self::SHOP.'/']]);

        self::assertSame('Lampe', $response->toArray()['title']);
        self::assertNull($response->toArray()['imageDataUrl']);
    }

    public function testAnInternalAddressGetsTheSameAnswerAsADeadSite(): void
    {
        $token = $this->registerVerifyAndLogin('lp-ssrf@example.com');

        $client = static::createClient();
        $this->transport()->setResponseFactory(static fn () => self::fail('Must not be sent.'));
        $internal = $client->request('POST', '/api/link-previews', ['auth_bearer' => $token, 'json' => ['url' => 'http://127.0.0.1/admin']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('link_preview.unavailable', $internal->toArray(false)['code']);

        $client = static::createClient();
        $this->transport()->setResponseFactory([new MockResponse('', ['error' => 'Could not resolve host'])]);
        $dead = $client->request('POST', '/api/link-previews', ['auth_bearer' => $token, 'json' => ['url' => self::SHOP.'/']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('link_preview.unavailable', $dead->toArray(false)['code']);
    }

    public function testRejectsNonHttpUrls(): void
    {
        $token = $this->registerVerifyAndLogin('lp-bad@example.com');

        foreach (['file:///etc/passwd', 'not a url', '', 'http://93.184.215.14:8080/'] as $url) {
            $response = static::createClient()->request('POST', '/api/link-previews', ['auth_bearer' => $token, 'json' => ['url' => $url]]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('link_preview.invalid_url', $response->toArray(false)['code'], $url);
        }
    }

    public function testRequiresAuthentication(): void
    {
        static::createClient()->request('POST', '/api/link-previews', ['json' => ['url' => self::SHOP.'/']]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testIsRateLimited(): void
    {
        $token = $this->registerVerifyAndLogin('lp-limit@example.com');

        for ($i = 0; $i < 30; ++$i) {
            static::createClient()->request('POST', '/api/link-previews', ['auth_bearer' => $token, 'json' => ['url' => 'ftp://x']]);
            self::assertResponseStatusCodeSame(422);
        }
        $response = static::createClient()->request('POST', '/api/link-previews', ['auth_bearer' => $token, 'json' => ['url' => 'ftp://x']]);
        self::assertResponseStatusCodeSame(429);
        self::assertSame('request.rate_limited', $response->toArray(false)['code']);
    }

    private function transport(): MockHttpClient
    {
        return static::getContainer()->get('link_preview.transport');
    }

    private static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
