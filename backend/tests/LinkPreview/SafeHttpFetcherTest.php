<?php

declare(strict_types=1);

namespace App\Tests\LinkPreview;

use App\LinkPreview\LinkPreviewException;
use App\LinkPreview\SafeHttpFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * SSRF guard (spec §5.5, CLAUDE.md règle 10). Hosts are IP literals so
 * no test depends on DNS; the mock transport fails the test if a
 * refused URL ever reaches the network.
 */
final class SafeHttpFetcherTest extends TestCase
{
    private const string PUBLIC_IP = '93.184.215.14';

    /** @return iterable<string, array{string}> */
    public static function refusedUrls(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/'];
        yield 'loopback, other form' => ['http://127.1.2.3/admin'];
        yield 'private 10/8' => ['http://10.0.0.5/'];
        yield 'private 172.16/12' => ['https://172.20.1.1/'];
        yield 'private 192.168/16' => ['http://192.168.1.1/'];
        yield 'link-local / cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'unspecified' => ['http://0.0.0.0/'];
        yield 'IPv6 loopback' => ['http://[::1]/'];
        yield 'IPv6 unique local' => ['http://[fd00::1]/'];
        yield 'IPv4-mapped IPv6' => ['http://[::ffff:127.0.0.1]/'];
        yield 'other scheme' => ['ftp://'.self::PUBLIC_IP.'/'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'gopher' => ['gopher://'.self::PUBLIC_IP.'/'];
        yield 'other port' => ['http://'.self::PUBLIC_IP.':8080/'];
        yield 'https on 22' => ['https://'.self::PUBLIC_IP.':22/'];
        yield 'credentials' => ['http://user:pass@'.self::PUBLIC_IP.'/'];
        yield 'no host' => ['http:///path'];
    }

    #[DataProvider('refusedUrls')]
    public function testRefusesUrlsOutsideThePublicWeb(string $url): void
    {
        $fetcher = $this->fetcher(static fn () => self::fail('The request must not be sent.'));

        $this->expectException(LinkPreviewException::class);
        $fetcher->fetch($url, ['text/html'], 1024);
    }

    public function testFetchesAPublicPage(): void
    {
        $fetcher = $this->fetcher([new MockResponse('<html></html>', ['response_headers' => ['content-type' => 'text/html; charset=utf-8']])]);

        $page = $fetcher->fetch('http://'.self::PUBLIC_IP.'/product', ['text/html'], 1024);

        self::assertSame('<html></html>', $page->body);
        self::assertSame('http://'.self::PUBLIC_IP.'/product', $page->url);
    }

    public function testFollowsUpToThreeRedirects(): void
    {
        $base = 'http://'.self::PUBLIC_IP;
        $fetcher = $this->fetcher([
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => '/a']]),
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => $base.'/b']]),
            new MockResponse('', ['http_code' => 307, 'response_headers' => ['location' => 'c']]),
            new MockResponse('ok', ['response_headers' => ['content-type' => 'text/html']]),
        ]);

        self::assertSame($base.'/c', $fetcher->fetch($base.'/', ['text/html'], 1024)->url);
    }

    public function testRefusesAFourthRedirect(): void
    {
        $redirect = static fn () => new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => '/again']]);
        $fetcher = $this->fetcher([$redirect(), $redirect(), $redirect(), $redirect(), new MockResponse('never')]);

        $this->expectException(LinkPreviewException::class);
        $fetcher->fetch('http://'.self::PUBLIC_IP.'/', ['text/html'], 1024);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedRedirects(): iterable
    {
        yield 'to loopback' => ['http://127.0.0.1/'];
        yield 'to metadata' => ['http://169.254.169.254/'];
        yield 'to another port' => ['http://'.self::PUBLIC_IP.':6379/'];
        yield 'to another scheme' => ['file:///etc/passwd'];
    }

    #[DataProvider('refusedRedirects')]
    public function testChecksEveryRedirectHop(string $location): void
    {
        $fetcher = $this->fetcher([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => $location]]),
            static fn () => self::fail('The redirect must not be followed.'),
        ]);

        $this->expectException(LinkPreviewException::class);
        $fetcher->fetch('http://'.self::PUBLIC_IP.'/', ['text/html'], 1024);
    }

    public function testCapsTheResponseSize(): void
    {
        $fetcher = $this->fetcher([new MockResponse(['<html>', str_repeat('x', 2000)], ['response_headers' => ['content-type' => 'text/html']])]);

        $this->expectException(LinkPreviewException::class);
        $fetcher->fetch('http://'.self::PUBLIC_IP.'/', ['text/html'], 1024);
    }

    public function testRejectsAnAnnouncedOversizedBody(): void
    {
        $fetcher = $this->fetcher([new MockResponse('small', ['response_headers' => ['content-type' => 'text/html', 'content-length' => '999999']])]);

        $this->expectException(LinkPreviewException::class);
        $fetcher->fetch('http://'.self::PUBLIC_IP.'/', ['text/html'], 1024);
    }

    public function testRejectsAnUnexpectedContentType(): void
    {
        $fetcher = $this->fetcher([new MockResponse('{}', ['response_headers' => ['content-type' => 'application/json']])]);

        $this->expectException(LinkPreviewException::class);
        $fetcher->fetch('http://'.self::PUBLIC_IP.'/', ['text/html'], 1024);
    }

    public function testRejectsAnErrorStatus(): void
    {
        $fetcher = $this->fetcher([new MockResponse('nope', ['http_code' => 404, 'response_headers' => ['content-type' => 'text/html']])]);

        $this->expectException(LinkPreviewException::class);
        $fetcher->fetch('http://'.self::PUBLIC_IP.'/', ['text/html'], 1024);
    }

    private function fetcher(mixed $responses): SafeHttpFetcher
    {
        return new SafeHttpFetcher(new MockHttpClient($responses), 'Idées Cadeaux');
    }
}
