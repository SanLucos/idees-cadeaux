<?php

declare(strict_types=1);

namespace App\Tests\LinkPreview;

use App\LinkPreview\LinkPreviewException;
use App\LinkPreview\LinkPreviewService;
use App\LinkPreview\PageMetadataExtractor;
use App\LinkPreview\SafeHttpFetcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The reason a preview failed is logged, never sent to the client. */
final class LinkPreviewServiceLogTest extends TestCase
{
    /** A public address: the SSRF guard lets the mock answer without DNS. */
    private const string SHOP = 'http://93.184.215.14';

    /** @var list<array{string, array<string, mixed>}> */
    private array $logs = [];

    public function testLogsWhyAShortLinkLeadsNowhere(): void
    {
        $service = $this->service([
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => 'https://www.amazon.fr/dp/B0CWH1M12W?ref=share&tag=x']]),
            new MockResponse('', ['http_code' => 503, 'response_headers' => ['content-type' => 'text/html']]),
        ]);

        try {
            $service->preview(self::SHOP.'/d/abc?x=1', 'fr');
            self::fail('No page, no name in the link: nothing to propose.');
        } catch (LinkPreviewException) {
        }

        self::assertSame([['Link preview: page not fetched.', [
            'url' => self::SHOP.'/d/abc',
            'lastUrl' => 'https://www.amazon.fr/dp/B0CWH1M12W',
            'reason' => 'HTTP 503.',
            'cause' => null,
            'titleFromUrl' => false,
        ]]], $this->logs);
    }

    public function testLogsAnAmazonPageWithoutTheProduct(): void
    {
        $service = $this->service([
            new MockResponse('<html><head><title>Amazon.fr</title></head><body><form action="/errors/validateCaptcha"></form></body></html>', ['response_headers' => ['content-type' => 'text/html']]),
        ]);

        $preview = $service->preview('https://www.amazon.fr/dp/B0CWH1M12W', 'fr');

        self::assertNull($preview['title']);
        self::assertCount(1, $this->logs);
        self::assertSame('Link preview: page read, nothing found.', $this->logs[0][0]);
        self::assertSame(['pageTitle' => 'Amazon.fr', 'captcha' => true, 'hasTitle' => false, 'hasImage' => false], array_intersect_key($this->logs[0][1], array_flip(['pageTitle', 'captcha', 'hasTitle', 'hasImage'])));
    }

    public function testAPageThatPrefillsLogsNothing(): void
    {
        $this->service([
            new MockResponse('<html><head><title>Un livre</title></head></html>', ['response_headers' => ['content-type' => 'text/html']]),
        ])->preview(self::SHOP.'/livre', 'fr');

        self::assertSame([], $this->logs);
    }

    /** @param list<MockResponse> $responses */
    private function service(array $responses): LinkPreviewService
    {
        $logs = &$this->logs;
        $logger = new class($logs) extends AbstractLogger {
            /** @param list<array{string, array<string, mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = [(string) $message, $context];
            }
        };

        $transport = new MockHttpClient($responses);

        return new LinkPreviewService(new SafeHttpFetcher($transport, 'Idées Cadeaux'), new PageMetadataExtractor(), $logger);
    }
}
