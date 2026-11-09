<?php

declare(strict_types=1);

namespace App\LinkPreview;

/**
 * Amazon product pages (spec §5.5): no OpenGraph, no JSON-LD `Product`,
 * and a `<title>` padded with the store and the category. The product
 * is in Amazon's own markup: `#productTitle`, the main picture
 * (`#landingImage`) and the price in the buy box (`#corePrice…`).
 * When the page can't be read at all, a full product link still names
 * the product in its path (`/Nom-du-produit/dp/ASIN`).
 * Pure: no network.
 */
final class AmazonPage
{
    /** Where the buy box price lives, most specific first. */
    private const array PRICE_CONTAINERS = [
        'corePrice_feature_div',
        'corePriceDisplay_desktop_feature_div',
        'corePrice_desktop',
        'corePrice_mobile_feature_div',
        'apex_desktop',
    ];

    private const array MAIN_IMAGES = ['landingImage', 'imgBlkFront', 'ebooksImgBlkFront'];

    /** Currency by store when the price has no symbol we can read. */
    private const array STORE_CURRENCIES = [
        'com' => 'USD', 'ca' => 'CAD', 'co.uk' => 'GBP', 'com.au' => 'AUD', 'co.jp' => 'JPY', 'in' => 'INR',
        'pl' => 'PLN', 'se' => 'SEK', 'com.mx' => 'MXN', 'com.br' => 'BRL', 'com.tr' => 'TRY', 'ae' => 'AED',
        'sa' => 'SAR', 'sg' => 'SGD', 'eg' => 'EGP',
    ];

    public static function isAmazon(string $url): bool
    {
        return null !== self::store($url) || 1 === preg_match('/^(?:www\.)?(?:amzn\.(?:eu|to|com|asia)|a\.co)$/', self::host($url));
    }

    /** The store's domain suffix ("fr", "co.uk", "com"), null when the URL isn't an Amazon store. */
    public static function store(string $url): ?string
    {
        return 1 === preg_match('/(?:^|\.)amazon\.((?:co\.|com\.)?[a-z]{2,3})$/', self::host($url), $m) ? $m[1] : null;
    }

    /** "/LEGO-Architecture-Notre-Dame/dp/B0CWH1M12W" → "LEGO Architecture Notre Dame". */
    public static function titleFromUrl(string $url): ?string
    {
        if (null === self::store($url)) {
            return null;
        }
        $path = (string) parse_url($url, \PHP_URL_PATH);
        if (1 !== preg_match('#^(?:/-/[a-z]{2}(?:_[a-z]{2})?)?/([^/]+)/(?:dp|gp/product)/[A-Z0-9]{10}(?:[/?]|$)#i', $path, $m)) {
            return null;
        }
        $title = trim((string) preg_replace('/[\s\-_]+/u', ' ', rawurldecode($m[1])));

        return '' === $title || 1 !== preg_match('/\p{L}/u', $title) ? null : $title;
    }

    /** What the product page itself says; any field may be null. */
    public static function extract(\DOMXPath $xpath, string $pageUrl): PageMetadata
    {
        $title = self::text($xpath->query('//*[@id="productTitle"]')->item(0)) ?? self::cleanTitle(self::text($xpath->query('//title')->item(0)));
        [$amount, $currency] = self::price($xpath, $pageUrl) ?? [null, null];

        return new PageMetadata($title, self::image($xpath), $amount, $currency);
    }

    /** "Nom : Amazon.fr: Jeux et Jouets" → "Nom"; a bare "Amazon.fr" (captcha, error page) → null. */
    private static function cleanTitle(?string $title): ?string
    {
        if (null === $title) {
            return null;
        }
        $title = trim((string) preg_replace('/^Amazon\.[a-z.]+\s*:\s*|\s*:\s*Amazon\.[a-z.]+\s*(?::.*)?$/iu', '', $title));

        return '' === $title || 1 === preg_match('/^Amazon(\.[a-z.]+)?$/i', $title) ? null : $title;
    }

    private static function image(\DOMXPath $xpath): ?string
    {
        foreach (self::MAIN_IMAGES as $id) {
            $img = $xpath->query(\sprintf('//img[@id="%s"]', $id))->item(0);
            if (!$img instanceof \DOMElement) {
                continue;
            }
            $hires = trim($img->getAttribute('data-old-hires'));
            if (str_starts_with($hires, 'http')) {
                return $hires;
            }
            // {"https://…_SX425_.jpg": [425, 425], …}: the largest.
            $dynamic = json_decode(html_entity_decode($img->getAttribute('data-a-dynamic-image'), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), true);
            if (\is_array($dynamic) && [] !== $dynamic) {
                uasort($dynamic, static fn (mixed $a, mixed $b): int => self::area($b) <=> self::area($a));

                return (string) array_key_first($dynamic);
            }
            $src = trim($img->getAttribute('src'));
            if (str_starts_with($src, 'http')) {
                return $src;
            }
        }

        return null;
    }

    private static function area(mixed $size): int
    {
        return \is_array($size) ? (int) ($size[0] ?? 0) * (int) ($size[1] ?? 0) : 0;
    }

    /** @return array{string, string}|null amount and currency of the buy box price */
    private static function price(\DOMXPath $xpath, string $pageUrl): ?array
    {
        // `.a-price`, but not the struck-through list price (`.a-text-price`).
        $priceSpans = './/span[contains(concat(" ", normalize-space(@class), " "), " a-price ") and not(contains(@class, "a-text-price"))]';
        foreach (self::PRICE_CONTAINERS as $id) {
            $container = $xpath->query(\sprintf('//*[@id="%s"]', $id))->item(0);
            if (null === $container) {
                continue;
            }
            foreach ($xpath->query($priceSpans, $container) ?: [] as $span) {
                $offscreen = self::text($xpath->query('.//span[contains(@class, "a-offscreen")]', $span)->item(0));
                $symbol = self::text($xpath->query('.//span[contains(@class, "a-price-symbol")]', $span)->item(0));
                if (null !== $offscreen && 1 === preg_match('/\d/', $offscreen)) {
                    $amount = PageMetadataExtractor::parsePrice($offscreen);
                } else {
                    // The offscreen copy is sometimes blank: "79" "," "99".
                    $whole = preg_replace('/\D/', '', (string) self::text($xpath->query('.//span[contains(@class, "a-price-whole")]', $span)->item(0)));
                    $fraction = preg_replace('/\D/', '', (string) self::text($xpath->query('.//span[contains(@class, "a-price-fraction")]', $span)->item(0)));
                    $amount = '' === $whole ? null : PageMetadataExtractor::parsePrice($whole.('' === $fraction ? '' : '.'.$fraction));
                }
                if (null !== $amount) {
                    return [$amount, self::currency($offscreen.' '.$symbol, $pageUrl)];
                }
            }
        }

        return null;
    }

    private static function currency(string $text, string $pageUrl): string
    {
        return match (true) {
            str_contains($text, '€') => 'EUR',
            str_contains($text, '£') => 'GBP',
            default => self::STORE_CURRENCIES[self::store($pageUrl) ?? ''] ?? 'EUR',
        };
    }

    private static function text(?\DOMNode $node): ?string
    {
        $text = null === $node ? '' : trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));

        return '' === $text ? null : $text;
    }

    private static function host(string $url): string
    {
        return strtolower((string) parse_url($url, \PHP_URL_HOST));
    }
}
