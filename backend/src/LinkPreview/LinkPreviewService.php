<?php

declare(strict_types=1);

namespace App\LinkPreview;

use App\Service\UploadedImageReader;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Pre-fill from a link (spec §5.5). The image is fetched here, through
 * the same SSRF guard as the page, and handed back already resized and
 * re-encoded (EXIF gone) as a data URL: the client proposes it, and if
 * the user keeps it, it goes through the ordinary image upload, which
 * copies it to our storage — online or later from the offline outbox
 * (spec §11 décision 34). Nothing is stored for a preview nobody keeps.
 *
 * Failures are logged (channel `link_preview`) with the reason the client
 * never gets: the API answers them all alike (LinkPreviewException).
 * Addresses are logged without their query string.
 */
final class LinkPreviewService
{
    public const int PAGE_MAX_BYTES = 2 * 1024 * 1024;
    public const int IMAGE_MAX_BYTES = 5 * 1024 * 1024;
    private const int IMAGE_MAX_SIDE = 1200;
    private const int IMAGE_MAX_PIXELS = 40_000_000;

    public function __construct(
        private readonly SafeHttpFetcher $fetcher,
        private readonly PageMetadataExtractor $extractor,
        #[Target('link_preview')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{title: ?string, priceAmount: ?string, priceCurrency: ?string, imageDataUrl: ?string}
     *
     * @throws LinkPreviewException when the page itself can't be fetched
     */
    public function preview(string $url, string $locale): array
    {
        try {
            // Cut, not refused, past the cap: product pages say what we
            // need near the top, and Amazon's weigh 2–3 MB.
            $page = $this->fetcher->fetch($url, ['text/html', 'application/xhtml+xml'], self::PAGE_MAX_BYTES, $locale, truncate: true);
        } catch (LinkPreviewException $e) {
            // An Amazon page that won't come (anti-robot page, timeout)
            // still names the product in its link, or in the one a short
            // link (amzn.eu/d/…) redirected to.
            $title = AmazonPage::titleFromUrl($e->lastUrl ?? $url) ?? AmazonPage::titleFromUrl($url);
            $this->logger->warning('Link preview: page not fetched.', [
                'url' => self::forLog($url),
                'lastUrl' => self::forLog($e->lastUrl ?? $url),
                'reason' => $e->getMessage(),
                'cause' => self::rootCause($e),
                'titleFromUrl' => null !== $title,
            ]);
            if (null === $title) {
                throw $e;
            }

            return ['title' => mb_substr($title, 0, PageMetadataExtractor::TITLE_MAX_LENGTH), 'priceAmount' => null, 'priceCurrency' => null, 'imageDataUrl' => null];
        }
        $metadata = $this->extractor->extract($page->body, $page->contentType, $page->url);
        if (null === $metadata->title || (AmazonPage::isAmazon($page->url) && null === $metadata->imageUrl)) {
            $this->logger->warning('Link preview: page read, nothing found.', [
                'url' => self::forLog($url),
                'lastUrl' => self::forLog($page->url),
                'bytes' => \strlen($page->body),
                'pageTitle' => 1 === preg_match('#<title[^>]*>(.{0,100})#is', $page->body, $m) ? trim(html_entity_decode(strip_tags($m[1]))) : null,
                'captcha' => false !== stripos($page->body, 'captcha'),
                'hasTitle' => null !== $metadata->title,
                'hasImage' => null !== $metadata->imageUrl,
            ]);
        }

        return [
            'title' => $metadata->title,
            'priceAmount' => $metadata->priceAmount,
            'priceCurrency' => $metadata->priceCurrency,
            'imageDataUrl' => null === $metadata->imageUrl ? null : $this->image($metadata->imageUrl, $locale),
        ];
    }

    /** The underlying error (DNS, TLS, timeout…) when it says more than the reason. */
    private static function rootCause(\Throwable $e): ?string
    {
        $root = $e;
        while (null !== $root->getPrevious()) {
            $root = $root->getPrevious();
        }

        return $root->getMessage() === $e->getMessage() ? null : $root->getMessage();
    }

    /** Scheme, host and path only: the query string may carry anything. */
    private static function forLog(string $url): string
    {
        return explode('?', explode('#', $url, 2)[0], 2)[0];
    }

    /** Best effort: a page whose image can't be had still pre-fills the rest. */
    private function image(string $url, string $locale): ?string
    {
        try {
            $body = $this->fetcher->fetch($url, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], self::IMAGE_MAX_BYTES, $locale)->body;
        } catch (LinkPreviewException) {
            return null;
        }

        // Refuse decompression bombs before GD allocates the bitmap.
        $size = @getimagesizefromstring($body);
        if (false === $size || $size[0] * $size[1] > self::IMAGE_MAX_PIXELS) {
            return null;
        }

        $image = @imagecreatefromstring($body);
        if (false === $image) {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode(UploadedImageReader::toJpeg(UploadedImageReader::fit($image, self::IMAGE_MAX_SIDE)));
    }
}
