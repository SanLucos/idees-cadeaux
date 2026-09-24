<?php

declare(strict_types=1);

namespace App\LinkPreview;

use App\Service\UploadedImageReader;

/**
 * Pre-fill from a link (spec §5.5). The image is fetched here, through
 * the same SSRF guard as the page, and handed back already resized and
 * re-encoded (EXIF gone) as a data URL: the client proposes it, and if
 * the user keeps it, it goes through the ordinary image upload, which
 * copies it to our storage — online or later from the offline outbox
 * (spec §11 décision 34). Nothing is stored for a preview nobody keeps.
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
    ) {
    }

    /**
     * @return array{title: ?string, priceAmount: ?string, priceCurrency: ?string, imageDataUrl: ?string}
     *
     * @throws LinkPreviewException when the page itself can't be fetched
     */
    public function preview(string $url, string $locale): array
    {
        $page = $this->fetcher->fetch($url, ['text/html', 'application/xhtml+xml'], self::PAGE_MAX_BYTES, $locale);
        $metadata = $this->extractor->extract($page->body, $page->contentType, $page->url);

        return [
            'title' => $metadata->title,
            'priceAmount' => $metadata->priceAmount,
            'priceCurrency' => $metadata->priceCurrency,
            'imageDataUrl' => null === $metadata->imageUrl ? null : $this->image($metadata->imageUrl, $locale),
        ];
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
